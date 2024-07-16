<?php

/**
 * Class WC_Remote_Logger_Test.
 */
class WC_Remote_Logger_Test extends \WC_Unit_Test_Case {

	/**
	 * Set up test
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		include_once WC_ABSPATH . 'includes/class-wc-remote-logger.php';

		WC()->version = '9.2.0';
		$this->logger = new WC_Remote_Logger();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		$this->cleanup_filters();

		delete_option( 'woocommerce_feature_remote_logging_enabled' );
	}

	/**
	 * Clean up filters.
	 *
	 * @return void
	 */
	private function cleanup_filters() {
		remove_all_filters( 'option_woocommerce_admin_remote_feature_enabled' );
		remove_all_filters( 'option_woocommerce_allow_tracking' );
		remove_all_filters( 'option_woocommerce_version' );
		remove_all_filters( 'option_woocommerce_remote_variant_assignment' );
	}

	/**
	 * Test that remote logging is allowed when all conditions are met.
	 */
	public function test_remote_logging_allowed() {
		update_option( 'woocommerce_feature_remote_logging_enabled', 'yes' );

		add_filter(
			'option_woocommerce_allow_tracking',
			function () {
				return 'yes';
			}
		);
		add_filter(
			'option_woocommerce_remote_variant_assignment',
			function () {
				return 5;
			}
		);

		add_filter(
			'plugins_api',
			function ( $result, $action, $args ) {
				if ( 'plugin_information' === $action && 'woocommerce' === $args->slug ) {
					return (object) array(
						'version' => '9.2.0',
					);
				}
				return $result;
			},
			10,
			3
		);

		$this->assertTrue( $this->logger->is_remote_logging_allowed() );
	}

	/**
	 * Test that remote logging is not allowed when the feature flag is disabled.
	 */
	public function test_remote_logging_not_allowed_feature_flag_disabled() {
		update_option( 'woocommerce_feature_remote_logging_enabled', 'no' );

		add_filter(
			'option_woocommerce_allow_tracking',
			function () {
				return 'yes';
			}
		);
		add_filter(
			'option_woocommerce_remote_variant_assignment',
			function () {
				return 5;
			}
		);

		set_transient( 'latest_woocommerce_version', '9.2.0', DAY_IN_SECONDS );

		$this->assertFalse( $this->logger->is_remote_logging_allowed() );
	}

	/**
	 * Test that remote logging is not allowed when user tracking is not opted in.
	 */
	public function test_remote_logging_not_allowed_tracking_opted_out() {
		update_option( 'woocommerce_feature_remote_logging_enabled', 'yes' );
		add_filter(
			'option_woocommerce_allow_tracking',
			function () {
				return 'no';
			}
		);
		add_filter(
			'option_woocommerce_remote_variant_assignment',
			function () {
				return 5;
			}
		);

		set_transient( 'latest_woocommerce_version', '9.2.0', DAY_IN_SECONDS );

		$this->assertFalse( $this->logger->is_remote_logging_allowed() );
	}

	/**
	 * Test that remote logging is not allowed when the WooCommerce version is outdated.
	 */
	public function test_remote_logging_not_allowed_outdated_version() {
		update_option( 'woocommerce_feature_remote_logging_enabled', 'yes' );
		add_filter(
			'option_woocommerce_allow_tracking',
			function () {
				return 'yes';
			}
		);
		add_filter(
			'option_woocommerce_remote_variant_assignment',
			function () {
				return 5;
			}
		);

		set_transient( 'latest_woocommerce_version', '9.2.0', DAY_IN_SECONDS );
		WC()->version = '9.0.0';

		$this->assertFalse( $this->logger->is_remote_logging_allowed() );
	}

	/**
	 * Test that remote logging is not allowed when the variant assignment is high.
	 */
	public function test_remote_logging_not_allowed_high_variant_assignment() {
		update_option( 'woocommerce_feature_remote_logging_enabled', 'yes' );
		add_filter(
			'option_woocommerce_allow_tracking',
			function () {
				return 'yes';
			}
		);
		add_filter(
			'option_woocommerce_version',
			function () {
				return '9.2.0';
			}
		);
		add_filter(
			'option_woocommerce_remote_variant_assignment',
			function () {
				return 15;
			}
		);

		set_transient( 'latest_woocommerce_version', '9.2.0', DAY_IN_SECONDS );

		$this->assertFalse( $this->logger->is_remote_logging_allowed() );
	}

	/**
	 * Test get_formatted_log method with basic log data returns expected array.
	 */
	public function test_get_formatted_log_basic() {
		$level   = 'error';
		$message = 'Fatal error occurred at line 123 in /home/user/path/wp-content/file.php';
		$context = array( 'tags' => array( 'tag1', 'tag2' ) );

		$result = $this->logger->get_formatted_log( $level, $message, $context );

		$this->assertArrayHasKey( 'feature', $result );
		$this->assertArrayHasKey( 'severity', $result );
		$this->assertArrayHasKey( 'message', $result );
		$this->assertArrayHasKey( 'host', $result );
		$this->assertEquals( 'woocommerce_core', $result['feature'] );
		$this->assertEquals( 'error', $result['severity'] );
		$this->assertEquals( 'Fatal error occurred at line 123 in **/wp-content/file.php', $result['message'] );
		$this->assertEquals( wp_parse_url( home_url(), PHP_URL_HOST ), $result['host'] );
		$this->assertArrayHasKey( 'tags', $result );
		$this->assertEquals( array( 'tag1', 'tag2' ), $result['tags'] );
		$this->assertArrayHasKey( 'extra', $result );
		$this->assertArrayHasKey( 'store_id', $result['extra'] );
	}

	/**
	 * Test get_formatted_log method sanitizes backtrace.
	 */
	public function test_get_formatted_log_with_backtrace() {
		$level   = 'error';
		$message = 'Test error message';
		$context = array( 'backtrace' => '/home/user/path/wp-content/file.php' );

		$result = $this->logger->get_formatted_log( $level, $message, $context );

		$this->assertArrayHasKey( 'trace', $result );
		$this->assertEquals( '**/wp-content/file.php', $result['trace'] );

		$context = array( 'backtrace' => '/home/user/path/wp-content/plugins/woocommerce/file.php' );
		$result  = $this->logger->get_formatted_log( $level, $message, $context );

		$this->assertArrayHasKey( 'trace', $result );
		$this->assertEquals( '**/woocommerce/file.php', $result['trace'] );
	}


	/**
	 * Test get_formatted_log method log extra attributes.
	 */
	public function test_get_formatted_log_with_extra() {
		$level   = 'error';
		$message = 'Test error message';
		$context = array(
			'extra' => array(
				'key1' => 'value1',
				'key2' => 'value2',
			),
		);

		$result = $this->logger->get_formatted_log( $level, $message, $context );

		$this->assertArrayHasKey( 'extra', $result );
		$this->assertEquals( 'value1', $result['extra']['key1'] );
		$this->assertEquals( 'value2', $result['extra']['key2'] );
	}


	/**
	 * Test log method when throttled.
	 *
	 * @return void
	 */
	public function test_log_when_throttled() {
		$mock_local_logger = $this->createMock( \WC_Logger::class );
		$mock_local_logger->expects( $this->once() )
			->method( 'info' )
			->with( 'Remote logging throttled.', array( 'source' => 'wc-remote-logger' ) );

		$this->logger = $this->getMockBuilder( WC_Remote_Logger::class )
							->setConstructorArgs( array( $mock_local_logger ) )
							->onlyMethods( array( 'is_remote_logging_allowed' ) )
							->getMock();

		$this->logger->method( 'is_remote_logging_allowed' )->willReturn( true );

		// generate throttling for 100 requests.
		$throttle_data = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$throttle_data[] = time() - $i;
		}

		set_transient( WC_Remote_Logger::THROTTLE_TRANSIENT, $throttle_data, WC_Remote_Logger::THROTTLE_INTERVAL );

		$this->logger->log( 'error', 'Test message' );

		delete_transient( WC_Remote_Logger::THROTTLE_TRANSIENT );
	}


	/**
	 * Test log method applies filter.
	 *
	 * @return void
	 */
	public function test_log_filtered_message_null() {
		$this->logger = $this->getMockBuilder( WC_Remote_Logger::class )
							->setConstructorArgs( array( $mock_local_logger ) )
							->onlyMethods( array( 'is_remote_logging_allowed' ) )
							->getMock();

		$this->logger->method( 'is_remote_logging_allowed' )->willReturn( true );

		add_filter(
			'woocommerce_remote_logger_log_message',
			function ( $message ) {
				$this->assertEquals( 'Test message', $message );
				return 'Filtered message';
			}
		);

		$this->logger->log( 'error', 'Test message' );
	}
}
