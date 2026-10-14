<?php
/**
 * Plugin Name: Beltoft Scanner
 * Description: Scan product barcodes with the phone camera into the WooCommerce cart, on the pages other plugins register it for.
 * Version:     2.0.0
 * Author:      beltoft.net
 * Text Domain: beltoft-scanner
 * Domain Path: /languages
 * Requires PHP: 8.1
 */

defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_SCANNER_FILE', __FILE__ );
define( 'BELTOFT_SCANNER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BELTOFT_SCANNER_URL', plugin_dir_url( __FILE__ ) );
define( 'BELTOFT_SCANNER_VERSION', '2.0.0' );

/**
 * PSR-4 for this plugin only, matching the beltoft-* plugins: no Composer at
 * runtime, one autoloader per plugin, registered from the main file.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'BeltoftScanner\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$path = BELTOFT_SCANNER_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( 'BeltoftScanner\Log\Table', 'install' ) );

add_action( 'plugins_loaded', static function () {
	load_plugin_textdomain( 'beltoft-scanner', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	BeltoftScanner\Plugin::init();
} );
