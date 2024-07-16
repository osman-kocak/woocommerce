<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * WooCommerce Remote Logger
 *
 * The WooCommerce remote logger class adds functionality to log WooCommerce errors remotely based on if the customer opted in and several other conditions.
 *
 * No personal information is logged, only error information and relevant context.
 *
 * @class WC_Remote_Logger
 * @since 9.2.0
 * @package WooCommerce\Classes
 */
class WC_Remote_Logger {
	const LOG_ENDPOINT       = 'https://public-api.wordpress.com/rest/v1.1/logstash';
	const THROTTLE_TRANSIENT = 'woocommerce_remote_logging_throttle';
	const THROTTLE_LIMIT     = 10; // Maximum number of requests in the interval
	const THROTTLE_INTERVAL  = 300; // Throttle interval in seconds (5 minutes)

	/**
	 * The logger instance.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private $local_logger;

	/**
	 * Remote logger constructor.
	 *
	 * @internal
	 * @param \WC_Logger_Interface|null $logger Logger instance.
	 */
	public function __construct( \WC_Logger_Interface $logger = null ) {
		if ( null === $logger ) {
			$local_logger = wc_get_logger();
		}
		$this->local_logger = $logger;
	}


	/**
	 * Log the error remotely using async request.
	 *
	 * @param array $error The error details.
	 */

	/**
	 * Add a log entry.
	 *
	 * @param string $level One of the following:
	 *     'emergency': System is unusable.
	 *     'alert': Action must be taken immediately.
	 *     'critical': Critical conditions.
	 *     'error': Error conditions.
	 *     'warning': Warning conditions.
	 *     'notice': Normal but significant condition.
	 *     'info': Informational messages.
	 *     'debug': Debug-level messages.
	 * @param string $message Log message.
	 * @param array  $context Optional. Additional information for log handlers.
	 *
	 * @return void
	 */
	public function log( $level, $message, $context = array() ) {
		if ( ! $this->is_remote_logging_allowed() ) {
			return;
		}

		if ( $this->should_throttle_logging() ) {
			$this->local_logger->info( 'Remote logging throttled.', array( 'source' => 'wc-remote-logger' ) );
			return;
		}

		if ( ! WC_Log_Levels::is_valid_level( $level ) ) {
			/* translators: 1: WC_Remote_Logger::log 2: level */
			wc_doing_it_wrong( __METHOD__, sprintf( __( '%1$s was called with an invalid level "%2$s".', 'woocommerce' ), '<code>WC_Remote_Logger::log</code>', $level ), '9.2.0' );
		}

		/**
		 * Filter the logging message. Returning null will prevent logging from occurring.
		 *
		 * @since 9.2.0
		 * @param string $message Log message.
		 * @param string $level   One of: emergency, alert, critical, error, warning, notice, info, or debug.
		 * @param array  $context Additional information for log handlers.
		 */
		$filtered_message = apply_filters( 'woocommerce_remote_logger_log_message', $message, $level, $context );

		if ( null === $filtered_message ) {
			return;
		}

		try {
			$log_data = $this->get_formatted_log( $level, $filtered_message, $context );
			$body     = array(
				'params' => json_encode( $log_data ),
			);

			wp_safe_remote_post(
				self::LOG_ENDPOINT,
				array(
					'body'     => json_encode( $body ),
					'timeout'  => 2,
					'headers'  => array(
						'Content-Type' => 'application/json',
					),
					// Send the request asynchronously to avoid performance issues.
					'blocking' => false,
				)
			);
		} catch ( Exception $e ) {
			// Log the error locally if the remote logging fails.
			$local_logger->error( 'Remote logging failed: ' . $e->getMessage(), $body );
		}

		$this->record_log_timestamp();
	}


	/**
	 * Get formatted log data to be sent to the remote logging service.
	 *
	 * @param string $level Log level.
	 * @param string $message Log message.
	 * @param array  $context Optional. Additional information for log handlers.
	 *
	 * @return array Formatted log data.
	 */
	public function get_formatted_log( $level, $message, $context = array() ) {
		$log_data = array(
			'feature'  => 'woocommerce_core',
			'severity' => $level,
			'message'  => $this->sanitize( $message ),
			'host'     => wp_parse_url( home_url(), PHP_URL_HOST ),
		);

		if ( ! empty( $context['backtrace'] ) ) {
			$log_data['trace'] = $this->sanitize_trace( $context['backtrace'] );
			unset( $context['backtrace'] );
		}

		if ( ! empty( $context['tags'] ) ) {
			$log_data['tags'] = $context['tags'];
			unset( $context['tags'] );
		}

		// Get blog details if available
		if ( class_exists( 'WC_Tracks' ) ) {
			$user         = wp_get_current_user();
			$blog_details = WC_Tracks::get_blog_details( $user->ID );

			if ( is_numeric( $blog_details['blog_id'] ) && $blog_details['blog_id'] > 0 ) {
				$log_data['blog_id'] = $blog_details['blog_id'];
			}
		} else {
			$blog_details = array();
		}

		if ( ! empty( $context['error'] && is_array( $context['error'] ) && ! empty( $context['error']['file'] ) ) ) {
			$context['error']['file'] = $this->sanitize( $context['error']['file'] );
		}

		// Merge extra attributes
		$extra_attrs = array_merge(
			array(
				'wc_version' => WC()->version,
				'store_id'   => $blog_details['store_id'] ?? null,
			),
			$context['extra'] ?? array()
		);
		unset( $context['extra'] );

		// Merge the extra attributes with the remaining context
		$log_data['extra'] = array_merge( $extra_attrs, $context );

		return $log_data;
	}

	/**
	 * Determines if remote logging is allowed based on the following conditions:
	 *
	 * 1. The feature flag for remote error logging is enabled.
	 * 2. The user has opted into tracking/logging.
	 * 3. The store is allowed to log based on the variant assignment percentage.
	 * 4. The current WooCommerce version is the latest so we don't log errors that might have been fixed in a newer version.
	 *
	 * @return bool
	 */
	public function is_remote_logging_allowed() {
		if ( ! FeaturesUtil::feature_is_enabled( 'remote_logging' ) ) {
			return false;
		}

		if ( ! $this->is_user_opted_in() ) {
			return false;
		}

		if ( ! $this->is_variant_assignment_allowed() ) {
			return false;
		}

		if ( ! $this->is_latest_woocommerce_version() ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the user has opted into tracking/logging.
	 *
	 * @return bool
	 */
	private function is_user_opted_in() {
		return 'yes' === get_option( 'woocommerce_allow_tracking', 'no' );
	}

	/**
	 * Check if the store is allowed to log based on the variant assignment percentage.
	 *
	 * @return bool
	 */
	private function is_variant_assignment_allowed() {
		$assignment = get_option( 'woocommerce_remote_variant_assignment', 0 );
		return ( $assignment <= 12 ); // Considering 10% of the 0-120 range.
	}

	/**
	 * Check if the current WooCommerce version is the latest.
	 *
	 * @return bool
	 */
	private function is_latest_woocommerce_version() {
		$latest_wc_version = $this->fetch_latest_woocommerce_version();

		if ( is_null( $latest_wc_version ) ) {
			return false;
		}

		return version_compare( WC()->version, $latest_wc_version, '>=' );
	}

	/**
	 * Fetch the latest WooCommerce version using the WordPress API and cache it.
	 *
	 * @return string|null
	 */
	private function fetch_latest_woocommerce_version() {
		$transient_key  = 'latest_woocommerce_version';
		$cached_version = get_transient( $transient_key );
		if ( $cached_version ) {
			return $cached_version;
		}

		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$plugin_info = plugins_api( 'plugin_information', array( 'slug' => 'woocommerce' ) );
		if ( is_wp_error( $plugin_info ) ) {
			return null;
		}

		if ( ! empty( $plugin_info->version ) ) {
			$latest_version = $plugin_info->version;
			set_transient( $transient_key, $latest_version, DAY_IN_SECONDS );
			return $latest_version;
		}

		return null;
	}

	/**
	 * Record the current timestamp to the throttle log.
	 */
	private function record_log_timestamp() {
		$timestamps   = get_transient( self::THROTTLE_TRANSIENT ) ?: array();
		$timestamps[] = time();
		set_transient( self::THROTTLE_TRANSIENT, $timestamps, self::THROTTLE_INTERVAL );
	}

	/**
	 * Check if logging should be throttled.
	 *
	 * @return bool
	 */
	private function should_throttle_logging() {
		$timestamps = get_transient( self::THROTTLE_TRANSIENT );
		if ( ! $timestamps ) {
			return false;
		}

		// Remove timestamps older than the throttle interval
		$current_time = time();
		$timestamps   = array_filter(
			$timestamps,
			function ( $timestamp ) use ( $current_time ) {
				return ( $current_time - $timestamp ) <= self::THROTTLE_INTERVAL;
			}
		);

		// Update the transient with the filtered timestamps
		set_transient( self::THROTTLE_TRANSIENT, $timestamps, self::THROTTLE_INTERVAL );

		// Check if the number of logs in the interval exceeds the limit
		return count( $timestamps ) >= self::THROTTLE_LIMIT;
	}

	/**
	 * Sanitize the content to exclude sensitive data.
	 *
	 * The trace is sanitized by:
	 *
	 * 1. Removing the path to the WordPress installation directory.
	 * 2. Removing the path to the WooCommerce plugin directory if it is present.
	 *
	 * For example, the trace:
	 *
	 * /var/www/html/wp-content/plugins/woocommerce/includes/class-wc-remote-logger.php on line 123
	 * will be sanitized to:
	 * **\/woocommerce/includes/class-wc-remote-logger.php on line 123
	 *
	 * @param string $message The error message.
	 * @return string The sanitized message.
	 */
	private function sanitize( $content ) {
		$sanitized_content = preg_replace( '/\/.*(\/woocommerce.*|\/wp-.*)/i', '**$1', $content );
		return $sanitized_content;
	}

	/**
	 * Sanitize the error trace to exclude sensitive data.
	 *
	 * @param array|string $trace The error trace.
	 * @return string The sanitized trace.
	 */
	private function sanitize_trace( $trace ) {
		if ( is_string( $trace ) ) {
			return $this->sanitize( $trace );
		}

		$sanitized_trace = array_map(
			function ( $trace_item ) {
				return $this->sanitize( $trace_item );
			},
			$trace
		);

		return json_encode( $sanitized_trace );
	}
}
