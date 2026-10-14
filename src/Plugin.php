<?php

namespace BeltoftScanner;

defined( 'ABSPATH' ) || exit;

/**
 * Wiring. Everything this plugin does starts here.
 */
class Plugin {

	public static function init(): void {
		Log\Table::maybe_install();

		Rest\Routes::register();
		Ui\Shortcode::register();
		Ui\Element::register();
		Ui\Assets::register();
		Admin\MissesPage::register();
	}
}
