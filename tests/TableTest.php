<?php

use BeltoftScanner\Log\Table;

/**
 * maybe_install() runs on every request, so the version it compares against
 * must come from the autoloaded options, not cost a query of its own.
 */
class TableTest extends WP_UnitTestCase {

	public function test_the_version_option_is_autoloaded() {
		delete_option( 'beltoft_scanner_db_version' );
		wp_cache_delete( 'alloptions', 'options' );

		Table::maybe_install();
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( 'beltoft_scanner_db_version', wp_load_alloptions() );
	}

	public function test_an_install_from_before_autoload_is_upgraded() {
		update_option( 'beltoft_scanner_db_version', '1', false );
		wp_cache_delete( 'alloptions', 'options' );

		Table::maybe_install();
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( 'beltoft_scanner_db_version', wp_load_alloptions() );
	}

	public function test_the_log_is_carried_over_from_the_old_table_name() {
		global $wpdb;

		$old = $wpdb->prefix . 'b2b_scan_misses';
		$new = Table::name();

		// Real tables, not the suite's temporary ones: the new table already
		// exists for real from the bootstrap, and a rename must meet it.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$wpdb->query( "DROP TABLE IF EXISTS `{$new}`" );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );
		$wpdb->query( "CREATE TABLE `{$old}` ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, code varchar(64) NOT NULL DEFAULT '', PRIMARY KEY (id) )" );
		$wpdb->insert( $old, array( 'code' => 'OLD-MISS' ) );
		delete_option( 'beltoft_scanner_db_version' );
		update_option( 'b2b_scanner_db_version', '2' );

		Table::maybe_install();

		$this->assertSame( 'OLD-MISS', $wpdb->get_var( "SELECT code FROM `{$new}` LIMIT 1" ) );
		$this->assertFalse( get_option( 'b2b_scanner_db_version' ) );

		$wpdb->suppress_errors( true );
		$this->assertFalse( $wpdb->query( "SELECT 1 FROM `{$old}` LIMIT 0" ) );
		$wpdb->suppress_errors( false );
	}

	private function real_tables(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	private function legacy_with( string $code ): string {
		global $wpdb;

		$old = $wpdb->prefix . 'b2b_scan_misses';
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );
		$wpdb->query( "CREATE TABLE `{$old}` ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, code varchar(64) NOT NULL DEFAULT '', PRIMARY KEY (id) )" );
		$wpdb->insert( $old, array( 'code' => $code ) );
		delete_option( 'beltoft_scanner_db_version' );

		return $old;
	}

	/** A request mid-upgrade created the new table, empty, before the rename ran. */
	public function test_an_empty_new_table_gives_way_to_the_old_log() {
		global $wpdb;

		$this->real_tables();
		$new = Table::name();
		$wpdb->query( "DELETE FROM `{$new}`" );
		$this->legacy_with( 'OLD-MISS' );

		Table::maybe_install();

		$this->assertSame( 'OLD-MISS', $wpdb->get_var( "SELECT code FROM `{$new}` LIMIT 1" ) );
	}

	public function test_a_new_table_with_rows_is_never_replaced() {
		global $wpdb;

		$this->real_tables();
		$new = Table::name();
		$wpdb->query( "DELETE FROM `{$new}`" );
		$wpdb->insert( $new, array( 'code' => 'NEW-MISS', 'reason' => 'not_found', 'created_at' => '2026-10-14 00:00:00' ) );
		$old = $this->legacy_with( 'OLD-MISS' );

		Table::maybe_install();

		$this->assertSame( array( 'NEW-MISS' ), $wpdb->get_col( "SELECT code FROM `{$new}`" ) );
		$wpdb->query( "DROP TABLE IF EXISTS `{$old}`" );
		$wpdb->query( "DELETE FROM `{$new}`" );
	}
}
