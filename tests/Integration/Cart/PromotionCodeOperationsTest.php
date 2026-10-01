<?php
/**
 * Tests the cart's promotion-code operations over the wire, through the production wiring, against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Application\PromotionCodeLimits;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Platform\RateLimiter\RateCountersTable;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Cart\ServesStoreApi;

/**
 * Served the way WordPress serves a request from the web (ServesStoreApi): a code is applied with a
 * POST and removed with a DELETE whose cart version is in the query string, each answer the cart
 * with its totals, and each sending the cart's cookie again; a code made of any of the characters a
 * code may have is removed through its path; every code that cannot be applied gets the same body,
 * byte for byte; ten codes tried close the cart to codes with `store_api.rate_limited`; a made-up
 * token starts no counter; and a GET that names DELETE changes nothing.
 *
 * The shirt costs 10.00 net and the stub tax rate is 20 %. The windows planted are far in the past
 * or far in the future, so no test reads the clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class PromotionCodeOperationsTest extends CartTestCase {

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
	 * Tests that a code is applied with a POST and removed with a DELETE carrying the version in the query string, each answer the cart with its totals and each sending the cookie again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_is_applied_and_removed_over_the_wire(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'SAVE10' );
		$this->startOverTheWire( $shirt );

		$applied = $this->applyOverTheWire( ' save10 ', 1 );

		$this->assertSame( 200, $applied['status'], (string) wp_json_encode( $applied['body'] ) );
		$this->assertSame( array( 2, array( array( 'code' => 'SAVE10' ) ) ), array( $applied['body']['version'], $applied['body']['promotion_codes'] ) );
		$this->assertSame( array( -100, 1080 ), array( $applied['body']['totals']['summary']['discount_total_minor'], $applied['body']['totals']['summary']['grand_minor'] ), '10 % off a shirt at 10.00: 9.00 and 1.80 tax.' );
		$this->assertContains( 'promotion:' . $this->uuidOf( 'SAVE10' ), array_column( $applied['body']['totals']['adjustments'], 'source' ) );
		$this->assertCount( 2, $this->cookies, 'Applying a code did not send the cart\'s cookie again.' );

		$removed = $this->removeOverTheWire( 'save10', 2 );

		$this->assertSame( 200, $removed['status'], (string) wp_json_encode( $removed['body'] ) );
		$this->assertSame( array( 3, array(), 0, 1200 ), array( $removed['body']['version'], $removed['body']['promotion_codes'], $removed['body']['totals']['summary']['discount_total_minor'], $removed['body']['totals']['summary']['grand_minor'] ) );
		$this->assertCount( 3, $this->cookies, 'Removing a code did not send the cart\'s cookie again.' );
		$this->assertSame( array_fill( 0, 3, $this->cookies[0] ), $this->cookies, 'Every write sends the same cookie.' );
	}

	/**
	 * Tests that a code made of every character a code may have, at the longest a code may be, is applied, and removed through the DELETE route's path.
	 *
	 * Every character of Promotion::CODE_PATTERN fits in one path segment as it is. The client sends
	 * the code in lower case, and removes it in lower case; both reach the same code.
	 *
	 * @since 0.1.0
	 */
	public function test_a_code_of_every_character_a_code_may_have_is_removed_through_its_path(): void {
		$shirt = self::variant();
		$code  = str_pad( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-', 64, 'X' );

		$this->assertTrue( Promotion::isCode( $code ), 'The code is not one the alphabet allows.' );

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( $code );
		$this->startOverTheWire( $shirt );

		$applied = $this->applyOverTheWire( strtolower( $code ), 1 );

		$this->assertSame( array( 200, array( array( 'code' => $code ) ) ), array( $applied['status'], $applied['body']['promotion_codes'] ?? null ), (string) wp_json_encode( $applied['body'] ) );

		$removed = $this->removeOverTheWire( strtolower( $code ), 2 );

		$this->assertSame( array( 200, 3, array() ), array( $removed['status'], $removed['body']['version'] ?? null, $removed['body']['promotion_codes'] ?? null ), (string) wp_json_encode( $removed['body'] ) );
	}

	/**
	 * Tests that every code that cannot be applied gets the same answer, byte for byte, whatever the reason.
	 *
	 * Unknown, ended, not started, not active, used up, a fixed amount in another currency, an
	 * ended code entered in lower case, and a stored code with a slash: one status and one body.
	 *
	 * Planted violation: in PromotionResolver::require(), refuse a code whose promotion has ended
	 * with `promotion.limit_reached` instead: the ended codes then get another body, and the test
	 * fails.
	 *
	 * @since 0.1.0
	 */
	public function test_every_refused_code_gets_the_same_body_byte_for_byte(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'GONE', array( 'ends_at' => '2001-01-01 00:00:00' ) );
		$this->plantPromotion( 'SOON', array( 'starts_at' => '2999-01-01 00:00:00' ) );
		$this->plantPromotion( 'DRAFT', array( 'status' => 'draft' ) );
		$this->plantPromotion(
			'POUNDS',
			array(
				'effect_kind'                 => 'fixed',
				'effect_percent_micropercent' => null,
				'effect_amount_minor'         => 500,
				'effect_currency'             => 'GBP',
				'effect_amount_basis'         => 'net',
			)
		);
		$this->plantUsage(
			$this->plantPromotion(
				'ONCE',
				array(
					'usage_limit' => 1,
					'used'        => 1,
				)
			),
			1,
			'committed'
		);
		$this->plantPromotion( 'SAVE/10' );
		$this->startOverTheWire( $shirt );

		$bodies = array();

		foreach ( array( 'NOPE', 'GONE', 'SOON', 'DRAFT', 'ONCE', 'POUNDS', 'gone', 'save/10' ) as $code ) {
			$refused = $this->applyOverTheWire( $code, 1 );

			$this->assertSame( 400, $refused['status'], $code );
			$this->assertErrorShape( $refused['body'], 'promotion.code_invalid', 400 );

			$bodies[ $code ] = $this->server->sent_body;
		}

		$this->assertSame( array_fill_keys( array_keys( $bodies ), $bodies['NOPE'] ), $bodies, 'A refused code\'s body tells one reason from another.' );
		$this->assertSame( 'That code cannot be applied.', json_decode( $bodies['NOPE'], true )['message'] ?? null );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT version FROM %i', $this->table( CartTables::CARTS ) ), 'A refused code changed the cart.' );
	}

	/**
	 * Tests that after ten codes tried the cart answers every code, a valid one too, with `store_api.rate_limited`, and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_ten_refused_codes_close_the_cart_over_the_wire(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'GOOD' );
		$this->startOverTheWire( $shirt );

		for ( $code = 1; $code <= PromotionCodeLimits::CART_LIMIT; $code++ ) {
			$this->assertSame( 400, $this->applyOverTheWire( 'NOPE' . $code, 1 )['status'] );
		}

		$over = $this->applyOverTheWire( 'GOOD', 1 );

		$this->assertSame( 429, $over['status'] );
		$this->assertErrorShape( $over['body'], 'store_api.rate_limited', 429 );

		$read = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( array( 1, array() ), array( $read['body']['version'], $read['body']['promotion_codes'] ) );
		$this->assertCount( 1, $this->cookies, 'A refused write sent the cookie.' );
	}

	/**
	 * Tests that a made-up cart token is refused as naming no cart, and starts no counter of codes tried.
	 *
	 * Planted violation: in CartService::applyPromotionCode(), count the code against the token the
	 * request presents before its cart is found: the made-up token then leaves a counter row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_made_up_token_starts_no_counter(): void {
		$this->plantPromotion( 'GOOD' );

		foreach ( array( 'NOPE', 'GOOD' ) as $code ) {
			$this->presentCookie( CartToken::generate()->value() );

			$refused = $this->applyOverTheWire( $code, 1 );

			$this->assertSame( 404, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'cart.not_found', 404 );
		}

		$this->assertSame(
			0,
			(int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE scope IN ( %s, %s )', $this->table( RateCountersTable::NAME ), PromotionCodeLimits::CART_BUCKET, PromotionCodeLimits::CLIENT_BUCKET ),
			'A made-up token started a counter of codes tried.'
		);
		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
	}

	/**
	 * Tests that a GET naming DELETE, or POST, with `?_method=` is refused and changes nothing, although WordPress routes it as the write.
	 *
	 * @since 0.1.0
	 */
	public function test_a_get_naming_a_write_method_changes_no_code(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1000 ) );
		$this->plantPromotion( 'SAVE10' );
		$this->startOverTheWire( $shirt );

		$this->assertSame( 200, $this->applyOverTheWire( 'SAVE10', 1 )['status'] );

		$delete = $this->serve(
			$this->server,
			'GET',
			$this->path( '/cart/codes/SAVE10' ),
			array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ),
			array(),
			array(
				'_method'      => 'DELETE',
				'cart_version' => 2,
			)
		);
		$post   = $this->serve(
			$this->server,
			'GET',
			$this->path( CartOperations::CODES_ROUTE ),
			array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ),
			array(),
			array(
				'_method'      => 'POST',
				'code'         => 'SAVE10',
				'cart_version' => 2,
			)
		);

		foreach ( array( $delete, $post ) as $refused ) {
			$this->assertSame( 405, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'store_api.read_method', 405 );
		}

		$read = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( array( 2, array( array( 'code' => 'SAVE10' ) ) ), array( $read['body']['version'], $read['body']['promotion_codes'] ) );
		$this->assertCount( 2, $this->cookies, 'A refused GET sent the cookie.' );
	}

	/**
	 * Tests that applying or removing a code needs the cart's token.
	 *
	 * @since 0.1.0
	 */
	public function test_writing_codes_needs_the_carts_token(): void {
		foreach ( array( $this->applyOverTheWire( 'SAVE10', 1 ), $this->removeOverTheWire( 'SAVE10', 1 ) ) as $refused ) {
			$this->assertSame( 400, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'store_api.cart_token_missing', 400 );
		}
	}

	/**
	 * Starts a cart with one shirt over the wire, and presents its cookie from then on.
	 *
	 * @since 0.1.0
	 *
	 * @param int $shirt The shirt's variant.
	 */
	private function startOverTheWire( int $shirt ): void {
		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 1 ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[0]['value'] );
	}

	/**
	 * Sends `cart.apply_code`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    The code.
	 * @param int    $version The cart version.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function applyOverTheWire( string $code, int $version ): array {
		return $this->store(
			'POST',
			CartOperations::CODES_ROUTE,
			array(
				'code'         => $code,
				'cart_version' => $version,
			)
		);
	}

	/**
	 * Sends `cart.remove_code`: a DELETE, with the cart version in the query string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code    The code.
	 * @param int    $version The cart version.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function removeOverTheWire( string $code, int $version ): array {
		return $this->serve(
			$this->server,
			'DELETE',
			$this->path( '/cart/codes/' . rawurlencode( $code ) ),
			array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE ),
			array(),
			array( 'cart_version' => $version )
		);
	}

	/**
	 * Returns the full path of a Store API route.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route The route, relative to the Store API's namespace.
	 * @return string The path.
	 */
	private function path( string $route ): string {
		return '/' . RestBinding::STORE_NAMESPACE . $route;
	}

	/**
	 * Returns a planted promotion's uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code Its code.
	 * @return string The uuid.
	 */
	private function uuidOf( string $code ): string {
		return (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE code = %s', $this->table( PromotionTables::PROMOTIONS ), $code );
	}
}
