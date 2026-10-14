<?php

use B2BScanner\Support\Breakpoints;
use B2BScanner\Ui\Assets;

/**
 * The button's breakpoint is Bricks', not a number in the stylesheet. CSS
 * cannot use a custom property in a media query, so the rule is printed with
 * the width in it.
 */
class AssetsTest extends WP_UnitTestCase {

	public function test_the_button_rule_uses_the_bricks_breakpoint() {
		$css = Assets::trigger_css();

		$this->assertStringContainsString( '@media (max-width: ' . Breakpoints::mobile_max_width() . 'px)', $css );
		$this->assertStringContainsString( '.b2bs-trigger', $css );
	}

	public function test_the_stylesheet_does_not_hardcode_the_breakpoint() {
		$css = file_get_contents( B2B_SCANNER_DIR . 'assets/scanner.css' );

		$this->assertDoesNotMatchRegularExpression( '/@media \(max-width: \d+px\) \{\s*\.b2bs-trigger \{/', $css );
	}
}
