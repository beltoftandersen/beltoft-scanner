<?php

namespace BeltoftScanner\Ui;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the scan button as a Bricks element, so it can be placed and
 * configured in the builder rather than by editing a template.
 *
 * Bricks loads element classes from a file path, not from a class name, so
 * registration hands it the file below and Bricks includes it itself. The class
 * in that file cannot be namespaced - Bricks resolves elements by the global
 * class name it finds - which is why it lives on its own rather than here.
 */
class Element {

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add' ), 11 );
	}

	public static function add(): void {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return;
		}

		\Bricks\Elements::register_element( BELTOFT_SCANNER_DIR . 'src/Ui/element-scan-button.php' );
	}
}
