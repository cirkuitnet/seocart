<?php
/**
 * Tests the cart's Store API operations through the production wiring, against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * Served the way WordPress serves a request from the web (ServesStoreApi): a read creates nothing
 * and sets no cookie; a refused write changes nothing; the first write creates exactly one cart and
 * sends its token; later writes carry it and the version, every accepted write sends it again, and
 * every answer carries the totals; a replay is refused as stale with the current totals; after a
 * purchase the next write starts a new cart; an undeclared nested key and too many lines are
 * refused before the service runs.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class CartOperationsTest extends CartTestCase {

	use ServesStoreApi;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();

		parent::tear_down();
	}

	/**
	 * Tests that a read without a cart answers an empty cart with zero totals, creates no row and sends no cookie, and is never cached.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_creates_nothing_and_sets_no_cookie(): void {
		$read = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( 200, $read['status'] );
		$this->assertEmptyCart( $read['body'] );
		$this->assertStringContainsString( 'no-store', $read['headers']['Cache-Control'] ?? '' );
		$this->assertSame( array(), $this->cookies );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $read['headers'] );
		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
	}

	/**
	 * Tests that a write without the Store API's header is refused and creates nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_without_the_header_is_refused_and_creates_nothing(): void {
		$refused = $this->serve( $this->server, 'POST', '/seocart/store/v1' . CartOperations::LINES_ROUTE, array(), self::linesBody( array( self::variant() => 1 ) ) );

		$this->assertSame( 403, $refused['status'] );
		$this->assertErrorShape( $refused['body'], 'store_api.header_missing', 403 );
		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( array(), $this->cookies );
	}

	/**
	 * Tests the life of a cart over the wire: the first write creates it and sends its token, a later write carries the token and the version, a replay is refused as stale with the current totals, and a read returns it without a cookie.
	 *
	 * Every accepted write sends the cart's cookie again, the same token for the guest cart's seven
	 * days from that write, so the cookie lives as long as the cart; the refused replay and the read
	 * send none.
	 *
	 * Planted violation: in CartService::start(), leave out the call to issue(): the first
	 * write's answer then carries no token, and the client cannot come back to its cart.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cart_is_created_changed_and_read_over_the_wire(): void {
		$shirt = self::variant();
		$mug   = self::variant();

		$this->price(
			array(
				$shirt => 1000,
				$mug   => 250,
			)
		);

		$first = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 2 ) ) );

		$this->assertSame( 200, $first['status'], (string) wp_json_encode( $first['body'] ) );
		$this->assertSame( 1, $first['body']['version'] );
		$this->assertSame( array( $shirt . ':2' ), self::lineSummary( $first['body'] ) );
		$this->assertCount( 1, $this->cookies, 'The first write sends exactly one cookie.' );
		$this->assertSame( 1, $this->rowsOf( CartTables::CARTS ) );

		$token = $this->cookies[0]['value'];

		$this->assertSame( $token, $first['headers'][ CartTokenTransport::HEADER ] ?? null );

		$this->presentCookie( $token );

		$again  = self::linesBody(
			array(
				$mug   => 1,
				$shirt => 1,
			),
			1
		);
		$second = $this->store( 'POST', CartOperations::LINES_ROUTE, $again );

		$this->assertSame( 200, $second['status'] );
		$this->assertSame( 2, $second['body']['version'] );
		$this->assertSame( array( $shirt . ':3', $mug . ':1' ), self::lineSummary( $second['body'] ) );
		$this->assertSame( 3900, $second['body']['totals']['summary']['grand_minor'], 'Three shirts at 10.00 and a mug at 2.50, with 20 % tax.' );
		$this->assertCount( 2, $this->cookies, 'The later write did not send the cookie again.' );
		$this->assertSame( $token, $second['headers'][ CartTokenTransport::HEADER ] ?? null );

		$replay = $this->store( 'POST', CartOperations::LINES_ROUTE, $again );

		$this->assertSame( 409, $replay['status'] );
		$this->assertErrorShape( $replay['body'], 'cart.version_stale', 409 );
		$this->assertSame( 2, $replay['body']['data']['details']['current_version'] ?? null );

		$refusedTotals = (array) ( $replay['body']['data']['details']['totals'] ?? array() );
		$answerTotals  = $second['body']['totals'];

		unset( $refusedTotals['calculated_at'], $answerTotals['calculated_at'] );

		$this->assertSame( $answerTotals, $refusedTotals, 'The refusal carries the totals of the cart it refused to change, worked out again.' );

		$changed = $this->store(
			'PATCH',
			'/cart/lines/' . LineIdentity::of( $mug )->value(),
			array(
				'quantity'     => 0,
				'cart_version' => 2,
			)
		);

		$this->assertSame( 200, $changed['status'] );
		$this->assertSame( array( 3, $shirt . ':3' ), array( $changed['body']['version'], ...self::lineSummary( $changed['body'] ) ) );

		$read = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( 3, $read['body']['version'] );
		$this->assertSame( 3600, $read['body']['totals']['summary']['grand_minor'] );
		$this->assertCount( 3, $this->cookies, 'Each of the three accepted writes sends the cookie once; the refused replay and the read send none.' );
		$this->assertSame( array_fill( 0, 3, $this->cookies[0] ), $this->cookies, 'Every write sends the same cookie, the same token for the cart\'s whole life.' );
		$this->assertSame( FrozenClock::at( self::STORE_NOW )->now()->getTimestamp() + self::TTL_SECONDS, $this->cookies[0]['options']['expires'], 'The cookie lives the guest cart\'s seven days from the write.' );
		$this->assertSame( 1, $this->rowsOf( CartTables::CARTS ) );
	}

	/**
	 * Tests that after a purchase the browser's cookie names no cart: a read answers the empty cart, and the next write starts a new cart and sends its new token.
	 *
	 * The cookie the browser keeps is HttpOnly, so the shop's page cannot clear it once the order is
	 * accepted; the cart it names is converted, and is no cart to either request.
	 *
	 * @since 0.1.0
	 */
	public function test_after_a_purchase_the_next_write_starts_a_new_cart(): void {
		$shirt = self::variant();

		$this->assertSame( 200, $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 1 ) ) )['status'] );

		$bought = $this->cookies[0]['value'];

		$this->presentCookie( $bought );
		$this->plantStatus( (int) $this->db->fetchValue( 'SELECT id FROM %i', $this->table( CartTables::CARTS ) ), CartStatus::Converted, 91 );

		$read = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( 200, $read['status'] );
		$this->assertEmptyCart( $read['body'], 'A read of the bought cart.' );

		$next = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 2 ) ) );

		$this->assertSame( 200, $next['status'], (string) wp_json_encode( $next['body'] ) );
		$this->assertSame( array( 1, $shirt . ':2' ), array( $next['body']['version'], ...self::lineSummary( $next['body'] ) ) );
		$this->assertCount( 2, $this->cookies );
		$this->assertNotSame( $bought, $this->cookies[1]['value'], 'The new cart has a token of its own.' );
		$this->assertSame( 2, $this->rowsOf( CartTables::CARTS ) );
	}

	/**
	 * Tests that changing a line needs the cart's token.
	 *
	 * @since 0.1.0
	 */
	public function test_changing_a_line_needs_the_carts_token(): void {
		$refused = $this->store(
			'PATCH',
			'/cart/lines/' . LineIdentity::of( self::variant() )->value(),
			array(
				'quantity'     => 1,
				'cart_version' => 1,
			)
		);

		$this->assertSame( 400, $refused['status'] );
		$this->assertErrorShape( $refused['body'], 'store_api.cart_token_missing', 400 );
	}

	/**
	 * Tests that a line with a key the declaration does not list, and a batch of more lines than a cart holds, are refused before anything is written.
	 *
	 * Planted violation: in JsonSchemaCompiler::members(), leave out `additionalProperties: false`:
	 * the line with the undeclared key then creates a cart.
	 *
	 * @since 0.1.0
	 */
	public function test_an_undeclared_line_key_and_too_many_lines_are_refused(): void {
		$undeclared = $this->store(
			'POST',
			CartOperations::LINES_ROUTE,
			array(
				'lines' => array(
					array(
						'variant_id' => self::variant(),
						'quantity'   => 1,
						'price'      => 1,
					),
				),
			)
		);

		$this->assertSame( 400, $undeclared['status'] );
		$this->assertErrorShape( $undeclared['body'], 'rest_invalid_param', 400 );

		$quantities = array();

		for ( $line = 0; $line <= Cart::MAX_LINES; $line++ ) {
			$quantities[ self::variant() ] = 1;
		}

		$tooMany = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( $quantities ) );

		$this->assertSame( 400, $tooMany['status'] );
		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( array(), $this->cookies );
	}

	/**
	 * Writes an answer's lines as `variant:quantity`, in their order.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $body The answer.
	 * @return list<string> The lines.
	 */
	private static function lineSummary( array $body ): array {
		return array_map( static fn( array $line ): string => $line['variant_id'] . ':' . $line['quantity'], $body['lines'] ?? array() );
	}
}
