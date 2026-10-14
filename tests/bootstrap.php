<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads WooCommerce and this plugin only - deliberately not beltoft-commerce.
 * The scanner is a core that other plugins plug into; a suite that passes
 * without them is the proof that it does not depend on any of them.
 *
 * The WordPress test install is the one shared with beltoft-commerce's suite
 * (WP_TESTS_DIR), against its own throwaway database. Plugins are loaded from
 * the staging tree by absolute path.
 */

define( 'BELTOFT_SITE_PLUGINS', '/var/www/staging.mindthetrash.pt/wp-content/plugins' );

$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
	require BELTOFT_SITE_PLUGINS . '/woocommerce/woocommerce.php';
	require dirname( __DIR__ ) . '/beltoft-scanner.php';
} );

tests_add_filter( 'setup_theme', function () {
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
		WC();
	}
} );

require $_tests_dir . '/includes/bootstrap.php';
