<?php
/**
 * Deleting the plugin removes what it stored: the miss log (scanned codes and
 * who scanned them), its version option and the recent scan ids. The old
 * names are included for an install that never got to carry them over.
 *
 * @package BeltoftScanner
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'beltoft_scanner_misses', 'b2b_scan_misses' ) as $beltoft_scanner_table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$beltoft_scanner_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'beltoft_scanner_db_version' );
delete_option( 'b2b_scanner_db_version' );

// Recent scan ids, one transient per user (Rest\Routes::remember_scan()).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_beltoft_scanner_scans_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_beltoft_scanner_scans_' ) . '%'
	)
);
