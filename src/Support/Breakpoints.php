<?php

namespace BeltoftScanner\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The width below which the scan button appears, read from Bricks.
 *
 * Hardcoding 767px would be wrong the moment someone edits the breakpoints in
 * Bricks - the button would then appear at a width where the ordering page has
 * already switched to its desktop three-column layout, or stay hidden on a
 * phone. Bricks keeps the real numbers in Bricks\Breakpoints::$breakpoints, so
 * that is what is asked.
 */
class Breakpoints {

	/** Bricks' own default for mobile_landscape, used only if Bricks is absent. */
	private const FALLBACK = 767;

	public static function mobile_max_width(): int {
		$breakpoints = self::all();

		foreach ( $breakpoints as $breakpoint ) {
			if ( 'mobile_landscape' === ( $breakpoint['key'] ?? '' ) ) {
				$width = (int) ( $breakpoint['width'] ?? 0 );

				if ( $width > 0 ) {
					return $width;
				}
			}
		}

		return self::FALLBACK;
	}

	private static function all(): array {
		if ( ! class_exists( '\Bricks\Breakpoints' ) ) {
			return array();
		}

		$breakpoints = \Bricks\Breakpoints::$breakpoints;

		return is_array( $breakpoints ) ? $breakpoints : array();
	}
}
