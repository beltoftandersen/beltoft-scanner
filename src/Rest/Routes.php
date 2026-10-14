<?php

namespace BeltoftScanner\Rest;

use BeltoftScanner\Catalog\ProductLookup;
use BeltoftScanner\Log\Repository as Log;
use BeltoftScanner\Support\Context;
use WC_Product;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The endpoints the scanner panel talks to.
 *
 * Authentication is WordPress's own cookie scheme, which means every request
 * carries X-WP-Nonce and WordPress rejects it with 403 rest_cookie_invalid_nonce
 * if the nonce has expired. The panel watches for that and asks the partner to
 * sign in again rather than silently dropping their scans.
 *
 * ON LOGGING: the brief has the panel POST unmatched codes to a log. This logs
 * them in the lookup instead, at the moment the miss happens. It is the same
 * record with two differences that matter: it cannot be lost when the phone
 * goes offline between the failed lookup and the log call, and it cannot be
 * forged or skipped by the client. The explicit "Reportar" action stays a
 * separate call, because that one is a person saying "this is wrong", which is
 * a different fact from "this code did not resolve".
 */
class Routes {

	public const NAMESPACE_V1 = 'beltoft-scanner/v1';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		/*
		 * GET only looks; POST with add=1 also puts a unit on the order. A
		 * request that changes the cart must not be one a browser, a proxy or
		 * a prefetch may repeat on its own.
		 */
		register_rest_route( self::NAMESPACE_V1, '/lookup', array(
			'methods'             => 'GET, POST',
			'callback'            => array( __CLASS__, 'lookup' ),
			'permission_callback' => array( __CLASS__, 'may_scan' ),
			'args'                => array(
				'code' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'clean_code' ),
				),
				/*
				 * One round trip per scan.
				 *
				 * Resolving the code, adding the unit and reading the order back
				 * used to be three requests, about a second and a half on a
				 * phone. Someone working along a shelf feels every one of those,
				 * and thirty boxes is most of a minute spent watching a
				 * spinner. With add=1 a scan is a single call that answers with
				 * the whole order.
				 */
				'add'  => array(
					'required' => false,
					'type'     => 'boolean',
					'default'  => false,
				),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/report', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'report' ),
			'permission_callback' => array( __CLASS__, 'may_scan' ),
			'args'                => array(
				'code' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => array( __CLASS__, 'clean_code' ),
				),
			),
		) );

		/*
		 * Scanning changes the order, so it goes through the cart rather than
		 * through the page.
		 *
		 * The ordering list is a paginated catalogue view - twenty-four products
		 * behind filters - so a scanned box is very often not on screen, and
		 * "find its row and type in it" has nowhere to type. The order actually
		 * being assembled is the cart beside the list, which is server-side.
		 * Adding there means a scan survives a page change, two phones on the
		 * same account merge instead of overwriting, and the undo below is a
		 * real decrement rather than an attempt to remember what a form field
		 * used to say. The row's quantity on screen is kept in step when the row
		 * happens to be visible.
		 */
		register_rest_route( self::NAMESPACE_V1, '/cart', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'cart_contents' ),
				'permission_callback' => array( __CLASS__, 'may_scan' ),
			),
			array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'cart' ),
			'permission_callback' => array( __CLASS__, 'may_scan' ),
			'args'                => array(
				'product_id' => array( 'required' => true, 'type' => 'integer' ),
				'delta'      => array( 'required' => false, 'type' => 'integer', 'default' => 1 ),
				'set'        => array( 'required' => false, 'type' => 'integer' ),
			),
			),
		) );

		register_rest_route( self::NAMESPACE_V1, '/notify', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'notify' ),
			'permission_callback' => array( __CLASS__, 'may_scan' ),
			'args'                => array(
				'product_id' => array( 'required' => true, 'type' => 'integer' ),
				'quantity'   => array( 'required' => false, 'type' => 'integer', 'default' => 1 ),
			),
		) );
	}

	/**
	 * A code as scanned or typed: plain text, trimmed, no longer than the log
	 * column. Not narrowed to letters and digits - SKUs here carry dots,
	 * slashes and spaces (`OC.1.08.5.1`, `1SUN/19BB0001000`, `HY-1322 IN01`),
	 * and stripping them turned a valid SKU into a miss.
	 */
	public static function clean_code( $value ): string {
		return trim( mb_substr( sanitize_text_field( (string) $value ), 0, 64 ) );
	}

	public static function may_scan() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'beltoft_scanner_auth', __( 'Sign in to continue.', 'beltoft-scanner' ), array( 'status' => 401 ) );
		}

		if ( ! Context::user_may_scan() ) {
			return new WP_Error( 'beltoft_scanner_forbidden', __( 'Your account does not have access to the scanner.', 'beltoft-scanner' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function lookup( WP_REST_Request $request ) {
		$code    = (string) $request->get_param( 'code' );
		$user_id = get_current_user_id();
		$product = ProductLookup::find( $code );

		if ( ! $product instanceof WC_Product ) {
			Log::record( $code, Log::NOT_FOUND, $user_id );

			return new WP_Error(
				'beltoft_scanner_not_found',
				/* translators: %s: the scanned barcode. */
				sprintf( __( 'Code not found: %s', 'beltoft-scanner' ), $code ),
				array( 'status' => 404, 'reason' => Log::NOT_FOUND, 'code_scanned' => $code )
			);
		}

		$verdict = ProductLookup::assess( $product, $user_id );
		$status  = $verdict['status'];

		if ( ProductLookup::OK !== $status ) {
			Log::record( $code, $status, $user_id, $product->get_id() );
		}

		if ( in_array( $status, array( Log::NOT_FOUND, Log::NOT_PURCHASABLE, Log::NOT_VISIBLE ), true ) ) {
			/*
			 * Both answer the same way on purpose. A partner who may not buy a
			 * product should not learn it exists from a different error message,
			 * and the two are told apart in the log, where it matters.
			 */
			return new WP_Error(
				'beltoft_scanner_not_found',
				sprintf( __( 'Code not found: %s', 'beltoft-scanner' ), $code ),
				array( 'status' => 404, 'reason' => $status, 'code_scanned' => $code )
			);
		}

		$payload = array(
			'status'      => $status,
			'product'     => ProductLookup::payload( $product ),
			'replacement' => $verdict['replacement'] instanceof WC_Product
				? ProductLookup::payload( $verdict['replacement'] )
				: null,
		);

		/*
		 * In stock but not enough of it: the line is still allowed - the list's
		 * own rules cap or warn - and the panel shows what is actually
		 * available beside the input.
		 */
		if ( ProductLookup::OK === $status && ! $product->is_in_stock() ) {
			$payload['status'] = 'backorder';
		}

		if ( 'POST' === $request->get_method() && $request->get_param( 'add' ) && in_array( $payload['status'], array( ProductLookup::OK, 'backorder' ), true ) ) {
			$added = self::apply_delta( $product, 1, null );

			if ( is_wp_error( $added ) ) {
				return $added;
			}

			// The order itself, not just what changed - that is the whole point
			// of doing this in one call.
			$payload = array_merge( $payload, self::cart_state(), $added );
		}

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * Add to, or take back from, the cart by a delta.
	 *
	 * A delta rather than an absolute quantity because two scans of the same box
	 * a second apart must be two units, and because "set it to 4" from a phone
	 * that has been offline would quietly undo whatever was added meanwhile.
	 */
	public static function cart( WP_REST_Request $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$delta      = (int) $request->get_param( 'delta' );
		$set        = $request->get_param( 'set' );
		$product    = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'beltoft_scanner_bad_product', __( 'Invalid product.', 'beltoft-scanner' ), array( 'status' => 400 ) );
		}

		/*
		 * WooCommerce does not boot the session, the customer or the cart on an
		 * ordinary REST request - only the Store API does - so WC()->cart is
		 * null here until asked for. wc_load_cart() is WooCommerce's own way to
		 * say "this request needs a cart", and without it every scan returned a
		 * 500 while the lookup beside it worked fine.
		 */
		if ( ! self::ready_cart() ) {
			return new WP_Error( 'beltoft_scanner_no_cart', __( 'Cart unavailable.', 'beltoft-scanner' ), array( 'status' => 500 ) );
		}

		/*
		 * Judged on where the quantity ends up, not on how it was asked for. A
		 * line that can no longer be ordered - sold out, hidden, discontinued -
		 * can always come down or off, and nothing can go up unless it could
		 * be ordered now, however the request spells it (`set`, a `delta` of
		 * zero, or both).
		 */
		$current = self::line( $product )['quantity'];
		$target  = null === $set ? $current + $delta : (int) $set;

		if ( $target > $current ) {
			$verdict = ProductLookup::assess( $product, get_current_user_id() );

			if ( ProductLookup::OK !== $verdict['status'] ) {
				return new WP_Error(
					'beltoft_scanner_not_orderable',
					__( 'This product cannot be added.', 'beltoft-scanner' ),
					array( 'status' => 409, 'reason' => $verdict['status'] )
				);
			}
		}

		$result = self::apply_delta( $product, $delta, $set );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array_merge( self::cart_state(), $result ), 200 );
	}

	/**
	 * Move a product's quantity, and say where it ended up.
	 *
	 * Shared by the scan path and the panel's own steppers so a tap and a beep
	 * cannot end up obeying two different sets of rules.
	 *
	 * @param WC_Product $product
	 * @param int        $delta   Relative change, used when $set is null.
	 * @param int|null   $set     Absolute quantity, when the partner typed one.
	 * @return array|WP_Error
	 */
	private static function apply_delta( WC_Product $product, int $delta, $set ) {
		if ( ! self::ready_cart() ) {
			return new WP_Error( 'beltoft_scanner_no_cart', __( 'Cart unavailable.', 'beltoft-scanner' ), array( 'status' => 500 ) );
		}

		$parent_id    = $product->get_parent_id();
		$variation_id = $parent_id ? $product->get_id() : 0;
		$product_id   = $parent_id ?: $product->get_id();
		$line         = self::line( $product );
		$existing     = $line['key'];
		$current      = $line['quantity'];

		$target = null === $set ? max( 0, $current + $delta ) : max( 0, (int) $set );

		/*
		 * Cap at what can actually be sold. set_quantity() does no stock
		 * validation - only add_to_cart() does - so without this a partner
		 * scanning the same box ten times ended up with fourteen of a product
		 * with six on the shelf, and found out at checkout or later.
		 */
		$capped = false;
		$stock  = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;

		if ( null !== $stock && ! $product->backorders_allowed() && $target > $stock ) {
			$target = max( 0, $stock );
			$capped = true;
		}

		if ( $existing ) {
			WC()->cart->set_quantity( $existing, $target, true );
		} elseif ( $target > 0 ) {
			$added = WC()->cart->add_to_cart( $product_id, $target, $variation_id );

			if ( ! $added ) {
				$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
				wc_clear_notices();

				return new WP_Error(
					'beltoft_scanner_add_failed',
					$notices ? wp_strip_all_tags( $notices[0]['notice'] ?? '' ) : __( 'This product could not be added.', 'beltoft-scanner' ),
					array( 'status' => 409 )
				);
			}
		}

		return array(
			'product_id' => $product->get_id(),
			'quantity'   => $target,
			'stock'      => $stock,
			'capped'     => $capped,
			'name'       => wp_strip_all_tags( $product->get_name() ),
		);
	}

	/**
	 * This product's line in the cart: its key and quantity, or '' and 0.
	 *
	 * Found by walking the cart rather than by generate_cart_id(). That helper
	 * hashes the product id together with whatever cart_item_data was attached
	 * when the line was created, and something on this install attaches some -
	 * a line added from the ordering page and the same line recreated here
	 * hash differently. The symptom was a scan that always believed the cart
	 * was empty: it read zero, wrote one, and three scans of the same box
	 * still left a quantity of one.
	 *
	 * @return array{key:string, quantity:int}
	 */
	private static function line( WC_Product $product ): array {
		if ( ! self::ready_cart() ) {
			return array( 'key' => '', 'quantity' => 0 );
		}

		$parent_id    = $product->get_parent_id();
		$variation_id = $parent_id ? $product->get_id() : 0;
		$product_id   = $parent_id ?: $product->get_id();

		foreach ( WC()->cart->get_cart() as $key => $line ) {
			if ( (int) ( $line['product_id'] ?? 0 ) === $product_id
				&& (int) ( $line['variation_id'] ?? 0 ) === $variation_id ) {
				return array( 'key' => (string) $key, 'quantity' => (int) ( $line['quantity'] ?? 0 ) );
			}
		}

		return array( 'key' => '', 'quantity' => 0 );
	}

	/**
	 * The whole order, as the panel draws it.
	 *
	 * Returned by every call that changes the cart, so the panel never has to
	 * ask a second time for what it just did.
	 */
	private static function cart_state(): array {
		$lines = array();

		foreach ( WC()->cart->get_cart() as $line ) {
			$product = $line['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$lines[] = array(
				'product_id' => $product->get_id(),
				'name'       => wp_strip_all_tags( $product->get_name() ),
				'sku'        => $product->get_sku(),
				'quantity'   => (int) $line['quantity'],
				'stock'      => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
				'line_total' => html_entity_decode( wp_strip_all_tags( wc_price( (float) ( $line['line_subtotal'] ?? 0 ) ) ) ),
				'thumbnail'  => self::thumb( $product ),
			);
		}

		return array(
			// Newest first: what was just scanned should be the thing on screen.
			'lines'    => array_reverse( $lines ),
			'count'    => (int) WC()->cart->get_cart_contents_count(),
			'subtotal' => html_entity_decode( wp_strip_all_tags( wc_price( (float) WC()->cart->get_subtotal() ) ) ),
			'cartUrl'  => wc_get_checkout_url(),
		);
	}

	/** Make sure this request has a cart that has read itself. See cart(). */
	private static function ready_cart(): bool {
		if ( ! WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			return false;
		}

		WC()->cart->get_cart();

		return true;
	}

	/**
	 * What is on the order right now.
	 *
	 * The panel shows this rather than remembering what it scanned. A phone
	 * that reloaded, a scan made on the shop floor an hour ago, a line added on
	 * a laptop - all of it is already in the cart, and a list the panel keeps
	 * for itself would disagree with the order the moment anything else touched
	 * it.
	 */
	public static function cart_contents() {
		if ( ! self::ready_cart() ) {
			return new WP_Error( 'beltoft_scanner_no_cart', __( 'Cart unavailable.', 'beltoft-scanner' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( self::cart_state(), 200 );
	}

	private static function thumb( WC_Product $product ): string {
		$id = $product->get_image_id() ?: get_post_thumbnail_id( $product->get_parent_id() ?: $product->get_id() );

		return $id ? (string) wp_get_attachment_image_url( $id, 'woocommerce_gallery_thumbnail' ) : '';
	}

	public static function report( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'code' );

		Log::report( $code, get_current_user_id() );

		return new WP_REST_Response(
			array( 'reported' => true, 'message' => __( 'Thank you. We will check this code.', 'beltoft-scanner' ) ),
			200
		);
	}

	/** Filter through which a plugin that keeps back-in-stock alerts takes a subscription. */
	public const NOTIFY_FILTER = 'beltoft_scanner_notify';

	/**
	 * "Notify me" on an out-of-stock scan.
	 *
	 * The scanner keeps no subscriptions of its own: whichever plugin owns
	 * back-in-stock alerts - the emails, the unsubscribe links, the admin
	 * screen - takes them through NOTIFY_FILTER, returning 'subscribed',
	 * 'already' or a WP_Error. Nothing answering means the feature is off;
	 * the panel then does not offer it at all (see Ui\Assets::data()).
	 */
	public static function notify( WP_REST_Request $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		$product    = wc_get_product( $product_id );

		/*
		 * A product the catalog rules refuse this user is answered exactly like
		 * one that does not exist, as the lookup does - otherwise the reply, and
		 * the email when stock returns, would reveal it.
		 */
		$verdict = $product instanceof WC_Product ? ProductLookup::assess( $product, get_current_user_id() ) : null;

		if ( ! $verdict || in_array( $verdict['status'], array( Log::NOT_VISIBLE, Log::NOT_PURCHASABLE ), true ) ) {
			return new WP_Error( 'beltoft_scanner_bad_product', __( 'Invalid product.', 'beltoft-scanner' ), array( 'status' => 400 ) );
		}

		$result = apply_filters(
			self::NOTIFY_FILTER,
			null,
			$product,
			wp_get_current_user(),
			max( 1, (int) $request->get_param( 'quantity' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! in_array( $result, array( 'subscribed', 'already' ), true ) ) {
			return new WP_Error(
				'beltoft_scanner_no_notifier',
				__( 'Back-in-stock alerts are not available at the moment.', 'beltoft-scanner' ),
				array( 'status' => 503 )
			);
		}

		return new WP_REST_Response(
			array(
				'subscribed' => true,
				'already'    => 'already' === $result,
				'message'    => 'already' === $result
					? __( 'You are already being notified about this product.', 'beltoft-scanner' )
					: __( 'We will let you know as soon as it is back in stock.', 'beltoft-scanner' ),
			),
			200
		);
	}
}
