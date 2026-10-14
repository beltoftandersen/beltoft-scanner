<?php

namespace BeltoftScanner\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the miss log.
 */
class Repository {

	/** Why a scanned code could not be turned into a line on the order. */
	public const NOT_FOUND       = 'not_found';
	public const NOT_PURCHASABLE = 'not_purchasable';
	/**
	 * The product exists but this account cannot see it at all - it is not in
	 * the trade catalogue. Told apart from NOT_FOUND because the fix is
	 * different: this one is a product_audience term, not a missing barcode.
	 */
	public const NOT_VISIBLE     = 'not_visible';
	public const OUT_OF_STOCK    = 'out_of_stock';
	public const DISCONTINUED    = 'discontinued';

	private const REASONS = array(
		self::NOT_FOUND,
		self::NOT_PURCHASABLE,
		self::NOT_VISIBLE,
		self::OUT_OF_STOCK,
		self::DISCONTINUED,
	);

	public static function record( string $code, string $reason, int $user_id, int $product_id = 0 ): int {
		global $wpdb;

		if ( ! in_array( $reason, self::REASONS, true ) ) {
			$reason = self::NOT_FOUND;
		}

		Table::maybe_install();

		$ok = $wpdb->insert(
			Table::name(),
			array(
				'code'       => substr( $code, 0, 64 ),
				'reason'     => $reason,
				'product_id' => $product_id,
				'user_id'    => $user_id,
				'reported'   => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Flag the most recent entry for a code, which is what "Reportar" means. */
	public static function report( string $code, int $user_id ): bool {
		global $wpdb;

		$table = Table::name();

		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE code = %s AND user_id = %d ORDER BY id DESC LIMIT 1",
				substr( $code, 0, 64 ),
				$user_id
			)
		);

		if ( ! $id ) {
			// Reported without a preceding miss: record it so it is not lost.
			$id = self::record( $code, self::NOT_FOUND, $user_id );
		}

		return (bool) $wpdb->update( $table, array( 'reported' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * Grouped for the admin screen: one row per code, most frequent first,
	 * because the same gap scanned forty times is one thing to fix.
	 */
	public static function summary( int $limit = 100 ): array {
		global $wpdb;

		Table::maybe_install();
		$table = Table::name();

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT code, reason, COUNT(*) AS hits, MAX(created_at) AS last_seen,
				        MAX(reported) AS reported, MAX(product_id) AS product_id
				 FROM {$table}
				 GROUP BY code, reason
				 ORDER BY MAX(reported) DESC, hits DESC, last_seen DESC
				 LIMIT %d",
				$limit
			)
		);
	}
}
