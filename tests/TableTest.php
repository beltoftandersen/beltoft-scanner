<?php

use B2BScanner\Log\Table;

/**
 * maybe_install() runs on every request, so the version it compares against
 * must come from the autoloaded options, not cost a query of its own.
 */
class TableTest extends WP_UnitTestCase {

	public function test_the_version_option_is_autoloaded() {
		delete_option( 'b2b_scanner_db_version' );
		wp_cache_delete( 'alloptions', 'options' );

		Table::maybe_install();
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( 'b2b_scanner_db_version', wp_load_alloptions() );
	}

	public function test_an_install_from_before_autoload_is_upgraded() {
		update_option( 'b2b_scanner_db_version', '1', false );
		wp_cache_delete( 'alloptions', 'options' );

		Table::maybe_install();
		wp_cache_delete( 'alloptions', 'options' );

		$this->assertArrayHasKey( 'b2b_scanner_db_version', wp_load_alloptions() );
	}
}
