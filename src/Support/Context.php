<?php

namespace BeltoftScanner\Support;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Who the scanner is for, and where it appears - asked of the plugins that
 * use it, never decided here.
 *
 * The scanner is a core other plugins plug into: each one that wants it adds
 * a context - its pages, and the users it allows there - through
 * `beltoft_scanner_contexts`:
 *
 *     $contexts['my-ordering-page'] = array(
 *         'applies'  => callable(): bool,             // is this request one of my pages?
 *         'may_scan' => callable( WP_User ): bool,    // may this user scan here?
 *     );
 *
 * The button and the REST endpoints both ask this class, so they cannot
 * disagree - a button that cannot look anything up, or an endpoint answering
 * requests no button would make. With nothing registered the scanner appears
 * nowhere and answers nobody, which is the safe default for a tool that adds to
 * carts at someone's prices.
 */
class Context {

	public const FILTER = 'beltoft_scanner_contexts';

	/**
	 * The registered contexts that are usable: both callbacks callable.
	 *
	 * @return array<string, array{applies: callable, may_scan: callable}>
	 */
	public static function all(): array {
		$usable = array();

		foreach ( (array) apply_filters( self::FILTER, array() ) as $id => $context ) {
			if ( is_array( $context )
				&& is_callable( $context['applies'] ?? null )
				&& is_callable( $context['may_scan'] ?? null ) ) {
				$usable[ (string) $id ] = $context;
			}
		}

		return $usable;
	}

	/**
	 * Whether some context lets this user scan, wherever the request is.
	 *
	 * What the REST endpoints ask. Their requests are XHRs, not page views, so
	 * "is this one of my pages" has no answer there - only "may this person
	 * scan at all".
	 */
	public static function user_may_scan( ?int $user_id = null ): bool {
		$user = self::user( $user_id );

		if ( ! $user ) {
			return false;
		}

		foreach ( self::all() as $context ) {
			if ( call_user_func( $context['may_scan'], $user ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the button and panel belong on this request.
	 *
	 * One context has to say both: that this is its page and that this user may
	 * scan there. A partner allowed on the trade list is not thereby allowed on
	 * a page some other context answers for.
	 */
	public static function applies(): bool {
		$user = self::user( null );

		if ( ! $user ) {
			return false;
		}

		foreach ( self::all() as $context ) {
			if ( call_user_func( $context['applies'] ) && call_user_func( $context['may_scan'], $user ) ) {
				return true;
			}
		}

		return false;
	}

	private static function user( ?int $user_id ): ?WP_User {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();

		return $user instanceof WP_User && $user->exists() ? $user : null;
	}
}
