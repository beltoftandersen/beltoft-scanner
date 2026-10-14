<?php

namespace BeltoftScanner\Ui;

use BeltoftScanner\Support\Context;

defined( 'ABSPATH' ) || exit;

/**
 * [beltoft_scan_button] - the trigger, placed wherever the builder wants it.
 *
 * The shortcode is the dependable half of the pair: the Bricks element gives
 * the builder a proper control, but if the element is ever unregistered - the
 * theme updated, the plugin load order changed - a shortcode already sitting in
 * the template keeps working.
 */
class Shortcode {

	public const TAG = 'beltoft_scan_button';

	public static function register(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array $atts
	 * @return string
	 */
	public static function render( $atts = array() ): string {
		if ( ! Context::applies() ) {
			return '';
		}

		$atts = shortcode_atts(
			array( 'label' => __( 'Scan code', 'beltoft-scanner' ) ),
			(array) $atts,
			self::TAG
		);

		return self::markup( (string) $atts['label'] );
	}

	/**
	 * Shared with the Bricks element so the two cannot drift apart.
	 */
	public static function markup( string $label ): string {
		return sprintf(
			'<button type="button" class="bscan-trigger" data-bscan-open aria-controls="bscan-panel" aria-expanded="false">
				<span class="bscan-trigger__icon" aria-hidden="true"></span>
				<span class="bscan-trigger__label">%s</span>
			</button>',
			esc_html( $label )
		);
	}
}
