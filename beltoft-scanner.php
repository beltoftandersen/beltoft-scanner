<?php
/**
 * Plugin Name: Beltoft Scanner
 * Description: Scan product barcodes with the phone camera into the WooCommerce cart, on the pages other plugins register it for.
 * Version:     1.0.7
 * Author:      beltoft.net
 * Text Domain: beltoft-scanner
 * Domain Path: /languages
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'B2B_SCANNER_FILE', __FILE__ );
define( 'B2B_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'B2B_SCANNER_URL', plugin_dir_url( __FILE__ ) );
define( 'B2B_SCANNER_VERSION', '1.0.7' );

/**
 * PSR-4 for this plugin only, matching the beltoft-* plugins: no Composer at
 * runtime, one autoloader per plugin, registered from the main file.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'B2BScanner\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$path = B2B_SCANNER_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'B2BScanner\Log\Table', 'install' ) );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'beltoft-scanner', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	B2BScanner\Plugin::init();
} );
