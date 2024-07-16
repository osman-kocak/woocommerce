<?php
/**
 * Plugin Name: WooCommerce
 * Plugin URI: https://woocommerce.com/
 * Description: An ecommerce toolkit that helps you sell anything. Beautifully.
 * Version: 9.2.0-dev
 * Author: Automattic
 * Author URI: https://woocommerce.com
 * Text Domain: woocommerce
 * Domain Path: /i18n/languages/
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WC_PLUGIN_FILE' ) ) {
	define( 'WC_PLUGIN_FILE', __FILE__ );
}

// Load core packages and the autoloader.
require __DIR__ . '/src/Autoloader.php';
require __DIR__ . '/src/Packages.php';

if ( ! \Automattic\WooCommerce\Autoloader::init() ) {
	return;
}
\Automattic\WooCommerce\Packages::init();

// Include the main WooCommerce class.
if ( ! class_exists( 'WooCommerce', false ) ) {
	include_once dirname( WC_PLUGIN_FILE ) . '/includes/class-woocommerce.php';
}

// Initialize dependency injection.
$GLOBALS['wc_container'] = new Automattic\WooCommerce\Container();

/**
 * Returns the main instance of WC.
 *
 * @since  2.1
 * @return WooCommerce
 */
function WC() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid
	return WooCommerce::instance();
}

/**
 * Returns the WooCommerce object container.
 * Code in the `includes` directory should use the container to get instances of classes in the `src` directory.
 *
 * @since  4.4.0
 * @return \Automattic\WooCommerce\Container The WooCommerce object container.
 */
function wc_get_container() {
	return $GLOBALS['wc_container'];
}

// Global for backwards compatibility.
$GLOBALS['woocommerce'] = WC();

// Jetpack's Rest_Authentication needs to be initialized even before plugins_loaded.
if ( class_exists( \Automattic\Jetpack\Connection\Rest_Authentication::class ) ) {
	\Automattic\Jetpack\Connection\Rest_Authentication::init();
}

//$c1=new \Automattic\WooCommerce\Class1();
//add_action('fizzbuzz', [$c1, 'foobar'], 10, 2);
//add_action('fizzbuzz', [new \Automattic\WooCommerce\Class2(), 'foobar'], 10, 2);
//add_action('fizzbuzz', [new \Automattic\WooCommerce\Class3(), 'foobar'], 10, 2);

\Automattic\WooCommerce\Hooks::register_filter('fizzbuzz', new \Automattic\WooCommerce\Class1(), 'foobar', 10, 2);
$id1=\Automattic\WooCommerce\Hooks::register_filter('fizzbuzz', \Automattic\WooCommerce\Class2::class, 'foobar', 20, 2);
$id2=\Automattic\WooCommerce\Hooks::register_filter('fizzbuzz', \Automattic\WooCommerce\Class2::class, 'foobar', 20, 2);
\Automattic\WooCommerce\Hooks::register_filter('fizzbuzz', fn() => new \Automattic\WooCommerce\Class3(), 'foobar', 10, 2);
\Automattic\WooCommerce\Hooks::register_filter('fizzbuzz', function() { \Automattic\WooCommerce\Class4::init(); }, Automattic\WooCommerce\Class4::class . '::foobar', 10, 2);
//echo "Id1: $id1\n";
//echo "Id2: $id1\n";

//apply_filters('fizzbuzz',1,'fizzbuzz');

//\Automattic\WooCommerce\Hooks::remove_hook('fizzbuzz', \Automattic\WooCommerce\Class2::class, 'foobar', 20, 2);
//\Automattic\WooCommerce\Hooks::remove_hook('fizzbuzz', \Automattic\WooCommerce\Class2::class, 'foobar', 20, 2);

/*
\Automattic\WooCommerce\Hooks::remove_hook(
    'woocommerce_debug_tools',
    \Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator::class,
    'add_initiate_regeneration_entry_to_tools_array',
    999
);
*/

//apply_filters('fizzbuzz',1,'fizzbuzz');
