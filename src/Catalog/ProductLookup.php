<?php

namespace BeltoftScanner\Catalog;

use BeltoftScanner\Log\Repository as Log;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a scanned barcode into a product this partner is allowed to order.
 *
 * WHICH FIELD HOLDS THE EAN. WooCommerce's own `_global_unique_id`, which is
 * also what beltoft-commerce's {beltoft_ean} tag prints, so the code shown on
 * the ordering list and the code matched here are the same value. This install
 * also carries `_alg_ean` from an older plugin, and the two disagree on 65
 * products - but `_alg_ean` has twenty-two codes shared between unrelated
 * products (one EAN sits on five different boxes of condoms), and a scanner
 * cannot act on an ambiguous match. `_global_unique_id` has no duplicates.
 *
 * Roughly forty-five products still carry placeholder GTINs (1111111111xxx)
 * and twenty have only the old field. Those will not match a real barcode, and
 * that is the intended outcome: they are logged as misses so the gaps can be
 * filled in Odoo rather than silently resolving to the wrong product.
 */
class ProductLookup {

	/** WooCommerce's GTIN/EAN/UPC/ISBN field. */
	public const GTIN_META = '_global_unique_id';

	/** The scan can be used. */
	public const OK = 'ok';

	/**
	 * Find a product by barcode, then by SKU.
	 *
	 * SKU is the documented fallback so a partner can type a reference into the
	 * manual field and get the same behaviour as scanning one.
	 */
	public static function find( string $code ): ?WC_Product {
		$code = trim( $code );

		if ( '' === $code ) {
			return null;
		}

		foreach ( self::ids_by_gtin( $code ) as $id ) {
			$product = wc_get_product( $id );

			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}

		$by_sku = wc_get_product_id_by_sku( $code );

		if ( $by_sku ) {
			$product = wc_get_product( $by_sku );

			if ( $product instanceof WC_Product ) {
				return $product;
			}
		}

		return null;
	}

	/**
	 * Published products and variations carrying this GTIN.
	 *
	 * Variations are included because that is where a barcode usually lives:
	 * the box on the shelf is one variant, not the parent.
	 */
	private static function ids_by_gtin( string $code ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id
				 FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = %s
				   AND TRIM(m.meta_value) = %s
				   AND p.post_type IN ('product','product_variation')
				   AND p.post_status IN ('publish','private')
				 ORDER BY p.post_type = 'product_variation' DESC, p.ID ASC
				 LIMIT 10",
				self::GTIN_META,
				$code
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/** Filter through which the plugins using the scanner apply their catalog rules. */
	public const ASSESS_FILTER = 'beltoft_scanner_assess';

	/**
	 * What this user may do with the product they just scanned.
	 *
	 * Who may see or buy what is not the scanner's to decide: the plugin that
	 * owns the catalog says so through ASSESS_FILTER, returning null to let the
	 * product through or Log::NOT_VISIBLE / Log::NOT_PURCHASABLE to refuse it.
	 * A second implementation of those rules here would drift from the real
	 * one - the failure mode being a scanner that adds a line the catalog would
	 * never have shown.
	 *
	 * NOT_VISIBLE is answered exactly like a code that matches nothing, so the
	 * reply never reveals a catalogue this account cannot see. The log keeps
	 * the difference, because the two need different fixes.
	 *
	 * The order matters. Catalog rules come first, so a product this account
	 * may not see is not revealed by offering its replacement. The replacement
	 * comes before WooCommerce's own checks, because a discontinued product is
	 * usually no longer purchasable or in stock, and that is exactly when the
	 * replacement is wanted. A replacement is only offered if this user could
	 * order it - offering one the add would then refuse helps nobody.
	 *
	 * @return array{status:string, replacement:?WC_Product}
	 */
	public static function assess( WC_Product $product, int $user_id ): array {
		$refused = self::refused_by_rules( $product, $user_id );

		if ( $refused ) {
			return array( 'status' => $refused, 'replacement' => null );
		}

		$replacement = self::replacement_for( $product );

		if ( $replacement instanceof WC_Product
			&& $replacement->get_id() !== $product->get_id()
			&& null === self::refused_by_rules( $replacement, $user_id )
			&& self::OK === self::sellable( $replacement ) ) {
			return array( 'status' => Log::DISCONTINUED, 'replacement' => $replacement );
		}

		return array( 'status' => self::sellable( $product ), 'replacement' => null );
	}

	/**
	 * Whether this user may know the product exists at all: it is published
	 * (or private, as the GTIN lookup allows) and the catalog rules do not
	 * refuse it. Anything else is answered like a product that does not exist.
	 */
	public static function visible_to( WC_Product $product, int $user_id ): bool {
		$statuses = array( 'publish', 'private' );
		$parent   = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;

		if ( ! in_array( $product->get_status(), $statuses, true )
			|| ( $parent && ! in_array( $parent->get_status(), $statuses, true ) ) ) {
			return false;
		}

		return null === self::refused_by_rules( $product, $user_id );
	}

	/** The consumers' catalog rules: null, or the refusal they returned. */
	private static function refused_by_rules( WC_Product $product, int $user_id ): ?string {
		$refused = apply_filters( self::ASSESS_FILTER, null, $product, $user_id );

		return in_array( $refused, array( Log::NOT_VISIBLE, Log::NOT_PURCHASABLE ), true ) ? $refused : null;
	}

	/** What WooCommerce itself says: purchasable and in stock, or why not. */
	private static function sellable( WC_Product $product ): string {
		if ( ! $product->is_purchasable() ) {
			return Log::NOT_PURCHASABLE;
		}

		if ( ! $product->is_in_stock() && ! $product->backorders_allowed() ) {
			return Log::OUT_OF_STOCK;
		}

		return self::OK;
	}

	/**
	 * The product that replaces a discontinued one, if anything knows of one.
	 *
	 * Nothing on this install records that today - there is no replacement or
	 * discontinued meta key anywhere in the database - so rather than invent a
	 * key that the Odoo sync does not write, this is a filter. Point it at
	 * whatever field the sync grows and the toast starts offering the
	 * replacement without further change here.
	 *
	 * @return WC_Product|null
	 */
	private static function replacement_for( WC_Product $product ) {
		$replacement = apply_filters( 'beltoft_scanner_replacement_for', null, $product );

		if ( is_numeric( $replacement ) ) {
			$replacement = wc_get_product( (int) $replacement );
		}

		return $replacement instanceof WC_Product ? $replacement : null;
	}

	/**
	 * The shape the scanner panel works from.
	 *
	 * price_html comes from WooCommerce itself, inside this partner's own
	 * request, so beltoft-commerce's price resolver has already applied their
	 * pricing group and the figure matches the list they are looking at.
	 */
	public static function payload( WC_Product $product ): array {
		$parent_id = $product->get_parent_id() ?: $product->get_id();
		$thumb_id  = $product->get_image_id() ?: get_post_thumbnail_id( $parent_id );

		return array(
			'id'                 => $product->get_id(),
			'parent_id'          => $parent_id,
			'name'               => wp_strip_all_tags( $product->get_name() ),
			'sku'                => $product->get_sku(),
			'gtin'               => (string) $product->get_global_unique_id(),
			'price_html'         => $product->get_price_html(),
			'thumbnail'          => $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) : '',
			'stock_status'       => $product->get_stock_status(),
			'stock_quantity'     => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'backorders_allowed' => $product->backorders_allowed(),
			'permalink'          => get_permalink( $parent_id ),
		);
	}
}
