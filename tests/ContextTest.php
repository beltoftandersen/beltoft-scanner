<?php

use BeltoftScanner\Rest\Routes;
use BeltoftScanner\Support\Context;

/**
 * Where the scanner appears and who may use it is decided by the plugins that
 * register a context - never by the scanner itself.
 */
class ContextTest extends WP_UnitTestCase {

	private int $user;

	public function setUp(): void {
		parent::setUp();
		$this->user = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->user );
	}

	public function tearDown(): void {
		remove_all_filters( 'beltoft_scanner_contexts' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function register( string $id, bool $applies, bool $may_scan ): void {
		add_filter( 'beltoft_scanner_contexts', function ( $contexts ) use ( $id, $applies, $may_scan ) {
			$contexts[ $id ] = array(
				'applies'  => fn() => $applies,
				'may_scan' => fn( $user ) => $may_scan,
			);

			return $contexts;
		} );
	}

	public function test_with_no_context_registered_the_scanner_appears_nowhere() {
		$this->assertFalse( Context::applies() );
		$this->assertFalse( Context::user_may_scan() );
	}

	public function test_a_context_that_applies_and_allows_the_user_shows_the_scanner() {
		$this->register( 'shop', true, true );

		$this->assertTrue( Context::applies() );
	}

	public function test_a_context_that_does_not_apply_here_does_not_show_it() {
		$this->register( 'shop', false, true );

		$this->assertFalse( Context::applies() );
	}

	public function test_a_context_that_refuses_the_user_does_not_show_it() {
		$this->register( 'shop', true, false );

		$this->assertFalse( Context::applies() );
	}

	/** Applies and allows must come from the same context, not one each. */
	public function test_one_context_applying_and_another_allowing_is_not_enough() {
		$this->register( 'here', true, false );
		$this->register( 'elsewhere', false, true );

		$this->assertFalse( Context::applies() );
	}

	public function test_the_user_is_passed_to_may_scan() {
		$seen = null;

		add_filter( 'beltoft_scanner_contexts', function ( $contexts ) use ( &$seen ) {
			$contexts['shop'] = array(
				'applies'  => fn() => true,
				'may_scan' => function ( $user ) use ( &$seen ) {
					$seen = $user;

					return true;
				},
			);

			return $contexts;
		} );

		Context::applies();

		$this->assertInstanceOf( WP_User::class, $seen );
		$this->assertSame( $this->user, $seen->ID );
	}

	public function test_a_malformed_context_is_ignored() {
		add_filter( 'beltoft_scanner_contexts', function ( $contexts ) {
			$contexts['broken']  = array( 'applies' => 'no_such_function_xyz', 'may_scan' => fn() => true );
			$contexts['partial'] = array( 'applies' => fn() => true );
			$contexts['junk']    = 'not an array';

			return $contexts;
		} );

		$this->assertFalse( Context::applies() );
		$this->assertFalse( Context::user_may_scan() );
	}

	public function test_rest_refuses_a_visitor_who_is_not_signed_in() {
		$this->register( 'shop', true, true );
		wp_set_current_user( 0 );

		$result = Routes::may_scan();

		$this->assertWPError( $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_rest_refuses_a_user_no_context_allows() {
		$this->register( 'shop', true, false );

		$result = Routes::may_scan();

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/** REST calls are not page requests, so only may_scan decides there. */
	public function test_rest_allows_a_user_some_context_allows_even_off_its_page() {
		$this->register( 'shop', false, true );

		$this->assertTrue( Routes::may_scan() );
	}
}
