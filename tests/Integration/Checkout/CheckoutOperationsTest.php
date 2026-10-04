<?php
/**
 * Tests checkout.update_session over the wire: the Store API's route, its policy and its answer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * `PUT seocart/store/v1/checkout`, served through the production wiring: a write the Store API's policy guards, which rides the cart's version and answers with the session, the version and the totals.
 *
 * The addresses are personal data, which a guest's answer does not carry: each given address is
 * an object without its fields, and one not given is null.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class CheckoutOperationsTest extends CheckoutTestCase {

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
	 * Tests the session write over the wire: it moves the cart's version on, answers with the session without its personal data, the version and the totals with shipping, sends the cart's cookie again, and a replay is refused as stale.
	 *
	 * Planted violation: in CheckoutOperations::updateSession(), declare the write with
	 * WriteMethod::Post: the route then answers PUT with no route, 404.
	 *
	 * @since 0.1.0
	 */
	public function test_the_session_is_written_over_the_wire(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1999 ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 1 ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[0]['value'] );

		$body    = array(
			'cart_version'        => 1,
			'shipping_address'    => AddressDocument::of( self::address( 'GB' ) ),
			'shipping_method_key' => 'flat',
			'payment_method_key'  => 'stub',
		);
		$written = $this->store( 'PUT', CheckoutOperations::ROUTE, $body );

		$this->assertSame( 200, $written['status'], (string) wp_json_encode( $written['body'] ) );
		$this->assertSame( array( 'checkout_session', 'version', 'totals' ), array_keys( $written['body'] ) );
		$this->assertSame(
			array(
				'billing_address'     => null,
				'shipping_address'    => array(),
				'shipping_method_key' => 'flat',
				'payment_method_key'  => 'stub',
			),
			$written['body']['checkout_session'],
			'A guest\'s answer carries an address\'s fields, which are personal data.'
		);
		$this->assertSame( '{}', (string) wp_json_encode( (object) $written['body']['checkout_session']['shipping_address'] ) );
		$this->assertStringContainsString( '"shipping_address":{}', $this->server->sent_body, 'A hidden address is not sent as an empty object.' );
		$this->assertSame( 2, $written['body']['version'] );
		$this->assertSame( array( 500, 2999 ), array( $written['body']['totals']['summary']['shipping_total_minor'], $written['body']['totals']['summary']['grand_minor'] ) );
		$this->assertCount( 2, $this->cookies, 'The write did not send the cart\'s cookie again.' );
		$this->assertSame( $this->cookies[0]['value'], $written['headers'][ CartTokenTransport::HEADER ] ?? null );
		$this->assertStringContainsString( 'no-store', $written['headers']['Cache-Control'] ?? '' );
		$this->assertSame( 1, $this->checkoutRows( CheckoutTables::SESSIONS ) );

		$replay = $this->store( 'PUT', CheckoutOperations::ROUTE, $body );

		$this->assertSame( 409, $replay['status'] );
		$this->assertErrorShape( $replay['body'], 'cart.version_stale', 409 );
		$this->assertSame( 2999, $replay['body']['data']['details']['totals']['summary']['grand_minor'] ?? null );
	}

	/**
	 * Tests that a shipping method key the session's column cannot store unchanged is refused, naming the field, and changes nothing.
	 *
	 * The column keeps ASCII only, and the database does not refuse other text: it stores it
	 * changed, `??` for two emoji, while the answer would echo what was sent.
	 *
	 * Planted violation: in CheckoutDetails::METHOD_KEY_PATTERN, accept any text
	 * (`/^.{1,64}\z/u`): the emoji key is then answered 200 and stored as question marks.
	 *
	 * @since 0.1.0
	 */
	public function test_a_method_key_that_is_not_one_is_refused(): void {
		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( self::variant() => 1 ) ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		foreach ( array( "\u{1F600}\u{1F600}", 'Flat', 'flat rate', 'été' ) as $key ) {
			$refused = $this->store(
				'PUT',
				CheckoutOperations::ROUTE,
				array(
					'cart_version'        => 1,
					'shipping_method_key' => $key,
				)
			);

			$this->assertSame( 422, $refused['status'], $key . ': ' . (string) wp_json_encode( $refused['body'] ) );
			$this->assertErrorShape( $refused['body'], 'checkout.invalid_method_key', 422 );
			$this->assertStringContainsString( 'shipping_method_key', (string) ( $refused['body']['message'] ?? '' ) );
		}

		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ), 'A refused method key was written.' );

		$accepted = $this->store(
			'PUT',
			CheckoutOperations::ROUTE,
			array(
				'cart_version'        => 1,
				'shipping_method_key' => 'carrier.express:next-day_1',
			)
		);

		$this->assertSame( 200, $accepted['status'], (string) wp_json_encode( $accepted['body'] ) );
		$this->assertSame( 'carrier.express:next-day_1', $accepted['body']['checkout_session']['shipping_method_key'] );
		$this->assertSame( 'carrier.express:next-day_1', $this->db->fetchValue( 'SELECT shipping_method_key FROM %i', $this->table( CheckoutTables::SESSIONS ) ) );
	}

	/**
	 * Tests that the write is refused without the Store API's header, without a cart, with an address it cannot use, and with a payment method it does not offer; each changes nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A payment method the store does not offer is refused by the store's gateways, `checkout.invalid_method_key`.
	 */
	public function test_a_write_the_checkout_cannot_take_is_refused_and_changes_nothing(): void {
		$headerless = $this->serve( $this->server, 'PUT', '/seocart/store/v1' . CheckoutOperations::ROUTE, array(), array( 'cart_version' => 1 ) );

		$this->assertSame( 403, $headerless['status'] );
		$this->assertErrorShape( $headerless['body'], 'store_api.header_missing', 403 );

		$cartless = $this->store( 'PUT', CheckoutOperations::ROUTE, array( 'cart_version' => 1 ) );

		$this->assertSame( 'store_api.cart_token_missing', $cartless['body']['code'] ?? null, (string) wp_json_encode( $cartless['body'] ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( self::variant() => 1 ) ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		$invalid = $this->store(
			'PUT',
			CheckoutOperations::ROUTE,
			array(
				'cart_version'    => 1,
				'billing_address' => array( 'country' => 'gb' ),
			)
		);

		$this->assertSame( 422, $invalid['status'] );
		$this->assertErrorShape( $invalid['body'], 'checkout.invalid_address', 422 );

		$unoffered = $this->store(
			'PUT',
			CheckoutOperations::ROUTE,
			array(
				'cart_version'       => 1,
				'payment_method_key' => 'card',
			)
		);

		// The method is checked against the store's gateways, which no `card` gateway is one of.
		$this->assertSame( 422, $unoffered['status'] );
		$this->assertErrorShape( $unoffered['body'], 'checkout.invalid_method_key', 422 );
		$this->assertStringContainsString( 'payment_method_key', (string) ( $unoffered['body']['message'] ?? '' ) );
		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}
}
