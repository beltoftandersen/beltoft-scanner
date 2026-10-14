<?php
/**
 * The scan button as a Bricks element.
 *
 * Not namespaced on purpose: Bricks includes this file and then instantiates
 * the class by the global name it discovers in it.
 *
 * @package BeltoftScanner
 */

defined( 'ABSPATH' ) || exit;

class Element_Beltoft_Scan_Button extends \Bricks\Element {

	public $category = 'woocommerce';
	public $name     = 'beltoft-scan-button';
	public $icon     = 'ti-camera';

	public function get_label() {
		return esc_html__( 'Scan barcode', 'beltoft-scanner' );
	}

	public function set_controls() {
		$this->controls['label'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Text', 'beltoft-scanner' ),
			'type'        => 'text',
			'default'     => esc_html__( 'Scan code', 'beltoft-scanner' ),
			'placeholder' => esc_html__( 'Scan code', 'beltoft-scanner' ),
		);

		/*
		 * Bricks already has a conditions system, and this site uses it for
		 * logged-in and role checks elsewhere. This control exists so the button
		 * can be restricted without leaving the builder, but it is not the
		 * security boundary: BeltoftScanner\Support\Context decides on the server
		 * whether the button renders and whether the endpoints answer, and it
		 * does so whatever is set here.
		 */
		$this->controls['rolesInfo'] = array(
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Visible only on pages where another plugin turns the scanner on, to users it allows to scan, and on small screens. Use Bricks conditions to restrict it further.', 'beltoft-scanner' ),
		);

		$this->controls['alwaysVisible'] = array(
			'tab'         => 'content',
			'label'       => esc_html__( 'Also show on large screens', 'beltoft-scanner' ),
			'type'        => 'checkbox',
			'description' => esc_html__( 'By default the button only shows below the Bricks mobile landscape breakpoint.', 'beltoft-scanner' ),
		);
	}

	public function render() {
		$settings = $this->settings;
		$label    = isset( $settings['label'] ) && '' !== $settings['label']
			? $settings['label']
			: esc_html__( 'Scan code', 'beltoft-scanner' );

		if ( ! \BeltoftScanner\Support\Context::applies() ) {
			// In the builder, show why nothing renders instead of an empty box.
			if ( bricks_is_builder_call() ) {
				echo '<div ' . $this->render_attributes( '_root' ) . '>'
					. esc_html__( 'The button shows on pages where the scanner is active, to users allowed to scan.', 'beltoft-scanner' )
					. '</div>';
			}

			return;
		}

		$classes = empty( $settings['alwaysVisible'] ) ? '' : ' bscan-trigger--always';

		echo '<div ' . $this->render_attributes( '_root' ) . '>'
			. str_replace( 'class="bscan-trigger"', 'class="bscan-trigger' . esc_attr( $classes ) . '"', \BeltoftScanner\Ui\Shortcode::markup( $label ) )
			. '</div>';
	}
}
