<?php

namespace B2BScanner\Log;

defined( 'ABSPATH' ) || exit;

/**
 * The table of codes that were scanned and could not be used.
 *
 * This is the point of the feature as much as the scanning is. A partner in a
 * stockroom holding a product whose barcode this shop does not recognise is
 * the shop's problem, not theirs, and the only way it ever gets fixed is if
 * somebody can see the list. Every unusable scan lands here with the reason,
 * so the gaps can be corrected in Odoo instead of being rediscovered by the
 * next person holding the same box.
 */
class Table {

	private const VERSION_OPTION = 'b2b_scanner_db_version';
	/** 2: same schema - bumped so existing installs re-save the option autoloaded. */
	private const VERSION        = '2';

	public static function name(): string {
		global $wpdb;

		return $wpdb->prefix . 'b2b_scan_misses';
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

		update_option( self::VERSION_OPTION, self::VERSION, true );
	}
}
