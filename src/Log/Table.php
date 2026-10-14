<?php

namespace BeltoftScanner\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The table of codes that were scanned and could not be used.
 *
 * This is the point of the feature as much as the scanning is. A partner in a
 * stockroom holding a product whose barcode this shop does not recognise is
 * the shop's problem, not theirs, and the only way it ever gets fixed is if
 * somebody can see the list. Every unusable scan lands here with the reason,
 * so the gaps can be corrected at the source instead of being rediscovered by the
 * next person holding the same box.
 */
class Table {

	private const VERSION_OPTION = 'beltoft_scanner_db_version';

	/** The names this table and its option had before the plugin was beltoft-scanner. */
	private const LEGACY_TABLE  = 'b2b_scan_misses';
	private const LEGACY_OPTION = 'b2b_scanner_db_version';
	/**
	 * 2: same schema - bumped so existing installs re-save the option autoloaded.
	 * 3: same schema - rerun adopt_legacy() where a request created the new,
	 *    empty table before the old one could be carried over.
	 */
	private const VERSION        = '3';

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'beltoft_scanner_misses';
	}

	/**
	 * Also called on every load, cheaply, because a plugin activated before
	 * this table existed - or restored from a database dump that predates it -
	 * would otherwise throw on the first scan.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::adopt_legacy();

		$table   = self::name();
		$collate = $wpdb->get_charset_collate();

		/*
		 * `code` is indexed but not unique: the same missing barcode scanned by
		 * three people on three days is three facts, and how often a code comes
		 * back is what says which gap to fix first.
		 */
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				code varchar(64) NOT NULL DEFAULT '',
				reason varchar(32) NOT NULL DEFAULT 'not_found',
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reported tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY code (code),
				KEY reason (reason),
				KEY created_at (created_at)
			) {$collate};"
		);

		// Only once the table is really there, so a failed install is retried.
		if ( self::exists( $table ) ) {
			update_option( self::VERSION_OPTION, self::VERSION, true );
		}
	}

	/**
	 * Carry the log over from the old table name.
	 *
	 * Renamed when the new table does not exist yet. When it does - a request
	 * that arrived mid-upgrade can create it first - the old rows are copied
	 * in and the old table dropped only once the copy succeeded. Either way
	 * nothing is lost, and a failure leaves the old table for the next try.
	 */
	private static function adopt_legacy(): void {
		global $wpdb;

		$legacy = $wpdb->prefix . self::LEGACY_TABLE;
		$table  = self::name();

		if ( self::exists( $legacy ) ) {
			if ( ! self::exists( $table ) ) {
				$wpdb->query( "ALTER TABLE `{$legacy}` RENAME TO `{$table}`" );
			} else {
				$columns = 'code, reason, product_id, user_id, reported, created_at';
				$copied  = $wpdb->query( "INSERT INTO `{$table}` ({$columns}) SELECT {$columns} FROM `{$legacy}` ORDER BY id" );

				if ( false !== $copied ) {
					$wpdb->query( "DROP TABLE `{$legacy}`" );
				}
			}
		}

		if ( ! self::exists( $legacy ) ) {
			delete_option( self::LEGACY_OPTION );
		}
	}

	/**
	 * Asked by reading the table rather than through SHOW TABLES, which does
	 * not list temporary tables - what the test suite creates.
	 */
	private static function exists( string $table ): bool {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );
		$found      = false !== $wpdb->query( "SELECT 1 FROM `{$table}` LIMIT 0" );
		$wpdb->suppress_errors( $suppressed );

		return $found;
	}
}
