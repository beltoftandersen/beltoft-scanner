<?php

namespace BeltoftScanner\Admin;

use BeltoftScanner\Log\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * The list of barcodes that did not resolve, so they can be fixed at the source.
 *
 * Grouped by code and ordered with reported codes first, then by how often each
 * one has come back: a code scanned forty times is one gap that forty orders
 * tripped over, and it should be the first thing anyone looks at.
 */
class MissesPage {

	private const SLUG = 'beltoft-scanner-misses';

	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Unresolved codes', 'beltoft-scanner' ),
			__( 'Unresolved codes', 'beltoft-scanner' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$rows = Repository::summary();

		echo '<div class="wrap"><h1>' . esc_html__( 'Unresolved codes', 'beltoft-scanner' ) . '</h1>';
		echo '<p>' . esc_html__( 'Scanned codes that did not give an orderable product. Most are fixed on the product itself: its SKU or GTIN.', 'beltoft-scanner' ) . '</p>';

		if ( ! $rows ) {
			echo '<p><em>' . esc_html__( 'Nothing to resolve.', 'beltoft-scanner' ) . '</em></p></div>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>'
			. '<th>' . esc_html__( 'Code', 'beltoft-scanner' ) . '</th>'
			. '<th>' . esc_html__( 'Reason', 'beltoft-scanner' ) . '</th>'
			. '<th>' . esc_html__( 'Scans', 'beltoft-scanner' ) . '</th>'
			. '<th>' . esc_html__( 'Last', 'beltoft-scanner' ) . '</th>'
			. '<th>' . esc_html__( 'Product', 'beltoft-scanner' ) . '</th>'
			. '<th>' . esc_html__( 'Reported', 'beltoft-scanner' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$product = $row->product_id ? wc_get_product( (int) $row->product_id ) : null;

			printf(
				'<tr><td><code>%s</code></td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row->code ),
				esc_html( self::reason_label( $row->reason ) ),
				(int) $row->hits,
				esc_html( $row->last_seen ),
				$product ? esc_html( $product->get_name() ) : '&mdash;',
				$row->reported ? esc_html__( 'Yes', 'beltoft-scanner' ) : '&mdash;'
			);
		}

		echo '</tbody></table></div>';
	}

	private static function reason_label( string $reason ): string {
		$labels = array(
			Repository::NOT_FOUND       => __( 'No product with this code', 'beltoft-scanner' ),
			Repository::NOT_PURCHASABLE => __( 'Exists, but not available to this account', 'beltoft-scanner' ),
			Repository::NOT_VISIBLE     => __( 'Outside this account\'s catalogue', 'beltoft-scanner' ),
			Repository::OUT_OF_STOCK    => __( 'Out of stock', 'beltoft-scanner' ),
			Repository::DISCONTINUED    => __( 'Discontinued', 'beltoft-scanner' ),
		);

		return $labels[ $reason ] ?? $reason;
	}
}
