<?php

use B2BScanner\Rest\Routes;

/**
 * The endpoints, through the REST server: what may go into the cart, what
 * may come out, and what the replies give away.
 */
class RestTest extends WP_UnitTestCase {

	private int $user;

	public function setUp(): void {
		parent::setUp();

		$this->user = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $this->user );

		add_filter( 'beltoft_scanner_contexts', function ( $contexts ) {
			$contexts['test'] = array(
				'applies'  => fn() => true,
				'may_scan' => fn() => true,
			);

			return $contexts;
		} );

		do_action( 'rest_api_init' );
		WC()->cart->empty_cart();
	}

	public function tearDown(): void {
		remove_all_filters( 'beltoft_scanner_contexts' );
		remove_all_filters( 'beltoft_scanner_assess' );
		WC()->cart->empty_cart();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	private function product( string $sku = '' ): WC_Product {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );

		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}

		$product->save();

		return wc_get_product( $product->get_id() );
	}

	private function hide( WC_Product $hidden ): void {
		add_filter( 'beltoft_scanner_assess', fn( $status, $product ) => $product->get_id() === $hidden->get_id() ? 'not_visible' : $status, 10, 2 );
	}

	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/' . Routes::NAMESPACE_V1 . $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function in_cart( WC_Product $product ): int {
		foreach ( WC()->cart->get_cart() as $line ) {
			if ( (int) $line['product_id'] === $product->get_id() ) {
				return (int) $line['quantity'];
			}
		}

		return 0;
	}

	/* ------------------------------------------------------------- codes */

	public function test_a_code_keeps_the_characters_skus_use() {
		$this->assertSame( 'OC.1.08.5.1', Routes::clean_code( ' OC.1.08.5.1 ' ) );
		$this->assertSame( '1SUN/19BB0001000', Routes::clean_code( '1SUN/19BB0001000' ) );
		$this->assertSame( 'HY-1322 IN01', Routes::clean_code( 'HY-1322 IN01' ) );
	}

	public function test_a_code_is_capped_and_stripped_of_markup() {
		$this->assertSame( 64, strlen( Routes::clean_code( str_repeat( 'a', 100 ) ) ) );
		$this->assertSame( 'x', Routes::clean_code( '<b>x</b>' ) );
	}

	public function test_a_sku_with_a_dot_and_a_slash_is_found() {
		$product = $this->product( '1SUN/19.BB' );

		$response = $this->call( 'GET', '/lookup', array( 'code' => '1SUN/19.BB' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $product->get_id(), $response->get_data()['product']['id'] );
	}

	/* -------------------------------------------------------------- cart */

	public function test_setting_a_quantity_cannot_add_a_product_the_rules_refuse() {
		$hidden = $this->product();
		$this->hide( $hidden );

		$response = $this->call( 'POST', '/cart', array( 'product_id' => $hidden->get_id(), 'delta' => 0, 'set' => 5 ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 0, $this->in_cart( $hidden ) );
	}

	public function test_a_line_that_can_no_longer_be_ordered_can_still_be_removed() {
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id(), 3 );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$response = $this->call( 'POST', '/cart', array( 'product_id' => $product->get_id(), 'set' => 0 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->in_cart( $product ) );
	}

	public function test_a_line_that_can_no_longer_be_ordered_can_be_reduced_by_typing() {
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id(), 3 );
		$this->hide( $product );

		$response = $this->call( 'POST', '/cart', array( 'product_id' => $product->get_id(), 'set' => 1 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->in_cart( $product ) );
	}

	public function test_a_line_that_can_no_longer_be_ordered_cannot_grow() {
		$product = $this->product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->hide( $product );

		$response = $this->call( 'POST', '/cart', array( 'product_id' => $product->get_id(), 'set' => 4 ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 1, $this->in_cart( $product ) );
	}

	/* ------------------------------------------------------------ lookup */

	public function test_a_lookup_by_get_does_not_change_the_cart() {
		$product = $this->product( 'GET-ONLY' );

		$response = $this->call( 'GET', '/lookup', array( 'code' => 'GET-ONLY', 'add' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $this->in_cart( $product ) );
	}

	public function test_a_scan_posted_with_add_puts_one_unit_on_the_order() {
		$product = $this->product( 'SCAN-ME' );

		$response = $this->call( 'POST', '/lookup', array( 'code' => 'SCAN-ME', 'add' => true ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->in_cart( $product ) );
		$this->assertSame( 1, $response->get_data()['quantity'] );
	}

	/* ------------------------------------------------------------ notify */

	public function test_notify_answers_a_hidden_product_like_one_that_does_not_exist() {
		$hidden = $this->product();
		$this->hide( $hidden );

		$hidden_reply  = $this->call( 'POST', '/notify', array( 'product_id' => $hidden->get_id() ) );
		$missing_reply = $this->call( 'POST', '/notify', array( 'product_id' => 999999 ) );

		$this->assertSame( $missing_reply->get_status(), $hidden_reply->get_status() );
		$this->assertSame( $missing_reply->get_data()['code'], $hidden_reply->get_data()['code'] );
	}
}
