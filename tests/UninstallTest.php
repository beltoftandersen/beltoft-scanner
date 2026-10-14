<?php

/**
 * Deleting the plugin leaves none of its data behind. Run in its own process
 * because uninstall.php needs WP_UNINSTALL_PLUGIN, which cannot be undefined.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends WP_UnitTestCase {

	public function test_uninstall_removes_the_log_the_option_and_the_scan_ids() {
		global $wpdb;

		update_option( 'beltoft_scanner_db_version', '3' );
		set_transient( 'beltoft_scanner_scans_7', array( 'a' => 1 ), DAY_IN_SECONDS );

		// The real table, not the suite's temporary stand-in. The next run's
		// bootstrap reinstalls it (Log\Table::maybe_install()).
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		define( 'WP_UNINSTALL_PLUGIN', 'beltoft-scanner/beltoft-scanner.php' );
		include BELTOFT_SCANNER_DIR . 'uninstall.php';
		wp_cache_flush();

		$this->assertFalse( get_option( 'beltoft_scanner_db_version' ) );
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%beltoft_scanner_scans_%'" ) );

		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->query( "SELECT 1 FROM `{$wpdb->prefix}beltoft_scanner_misses` LIMIT 0" ) );
		$wpdb->suppress_errors( false );
	}
}
