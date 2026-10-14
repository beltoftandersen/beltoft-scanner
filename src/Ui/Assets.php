<?php

namespace BeltoftScanner\Ui;

use BeltoftScanner\Rest\Routes;
use BeltoftScanner\Support\Breakpoints;
use BeltoftScanner\Support\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the scanner's two files, and only where they are used.
 *
 * The ordering page is one page on one host for a handful of accounts. Putting
 * a camera library on every request of both shops to serve it would be a poor
 * trade, so this is conditional on the same check the button uses.
 */
class Assets {

	public const HANDLE = 'beltoft-scanner';

	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'panel' ), 30 );
	}

	public static function enqueue(): void {
		if ( ! Context::applies() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			BELTOFT_SCANNER_URL . 'assets/scanner.css',
			array(),
			BELTOFT_SCANNER_VERSION
		);

		wp_add_inline_style( self::HANDLE, self::trigger_css() );

		wp_enqueue_script(
			self::HANDLE,
			BELTOFT_SCANNER_URL . 'assets/scanner.js',
			array(),
			BELTOFT_SCANNER_VERSION,
			true
		);

		wp_localize_script( self::HANDLE, 'BeltoftScannerData', self::data() );
	}

	/**
	 * The button shows only where a camera is likely and the list has stacked:
	 * below Bricks' mobile_landscape breakpoint. Printed rather than kept in
	 * scanner.css because a media query cannot use a custom property, and a
	 * fixed number there would ignore the breakpoint set in Bricks.
	 */
	public static function trigger_css(): string {
		return sprintf(
			'@media (max-width: %dpx) { .bscan-trigger { display: inline-flex; } }',
			Breakpoints::mobile_max_width()
		);
	}

	private static function data(): array {
		return array(
			'restUrl'    => esc_url_raw( rest_url( Routes::NAMESPACE_V1 ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'loginUrl'   => esc_url_raw( wc_get_page_permalink( 'myaccount' ) ),
			// WooCommerce's own endpoint URL, so a site in a subdirectory works.
			'fragmentsUrl' => esc_url_raw( \WC_AJAX::get_endpoint( 'get_refreshed_fragments' ) ),
			'breakpoint' => Breakpoints::mobile_max_width(),
			/*
			 * The ZXing build is served from this plugin. It is only fetched
			 * when the browser has no BarcodeDetector of its own, which on
			 * current Android Chrome it does - so most phones never download it.
			 */
			'zxingUrl'   => esc_url_raw( BELTOFT_SCANNER_URL . 'assets/lib/zxing-browser.min.js' ),
			// "Notify me" is only offered when a plugin takes the subscriptions.
			'canNotify'  => has_filter( Routes::NOTIFY_FILTER ) !== false,
			'i18n'       => array(
				'title'         => __( 'Scan barcode', 'beltoft-scanner' ),
				'close'         => __( 'Close', 'beltoft-scanner' ),
				'torch'         => __( 'Light', 'beltoft-scanner' ),
				'manual'        => __( 'Enter code', 'beltoft-scanner' ),
				'manualPlace'   => __( 'EAN or SKU', 'beltoft-scanner' ),
				'add'           => __( 'Add', 'beltoft-scanner' ),
				'undo'          => __( 'Undo', 'beltoft-scanner' ),
				'report'        => __( 'Report', 'beltoft-scanner' ),
				'notify'        => __( 'Notify me', 'beltoft-scanner' ),
				/* translators: %s: the scanned barcode. */
				'notFound'      => __( 'Code not found: %s', 'beltoft-scanner' ),
				'noStock'       => __( 'Out of stock', 'beltoft-scanner' ),
				'pending'       => __( 'pending', 'beltoft-scanner' ),
				'offline'       => __( 'No connection. Your codes are saved.', 'beltoft-scanner' ),
				'denied'        => __( 'We could not access the camera. Allow access in your browser settings or enter the code manually.', 'beltoft-scanner' ),
				'noCamera'      => __( 'This device has no camera available. Enter the code manually.', 'beltoft-scanner' ),
				'webview'       => __( 'The camera does not work inside this app. Open the page in Safari or Chrome.', 'beltoft-scanner' ),
				'openExternal'  => __( 'Open in browser', 'beltoft-scanner' ),
				'sessionLost'   => __( 'Your session has expired. Sign in again to continue — your scans are saved.', 'beltoft-scanner' ),
				'signIn'        => __( 'Sign in', 'beltoft-scanner' ),
				'restored'      => __( 'We recovered your pending scans.', 'beltoft-scanner' ),
				'available'     => __( 'Available: %s', 'beltoft-scanner' ),
				'added'         => __( '%1$s · %2$s in the order', 'beltoft-scanner' ),
				'starting'      => __( 'Starting the camera…', 'beltoft-scanner' ),
				'oneLine'       => __( '%s item', 'beltoft-scanner' ),
				/* translators: %s: number of items on the order. */
				'nLines'        => __( '%s items', 'beltoft-scanner' ),
				'more'          => __( 'Increase quantity', 'beltoft-scanner' ),
				'less'          => __( 'Decrease quantity', 'beltoft-scanner' ),
				'qty'           => __( 'Quantity', 'beltoft-scanner' ),
				'remove'        => __( 'Remove', 'beltoft-scanner' ),
			),
		);
	}

	/**
	 * The panel lives at the end of the body, not inside the shortcode.
	 *
	 * It is pinned to the top of the viewport while the list scrolls behind it,
	 * and an element that has to escape every ancestor's overflow, transform and
	 * stacking context is far easier to keep honest as a direct child of body
	 * than as whatever the builder wrapped the button in.
	 */
	public static function panel(): void {
		if ( ! Context::applies() ) {
			return;
		}

		include BELTOFT_SCANNER_DIR . 'src/Ui/panel.php';
	}
}
