<?php
/**
 * Tests checkout.change_currency over the wire: the Store API's route, its policy and its answer
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * `POST seocart/store/v1/cart/currency`, served through the production wiring: a write the Store API's policy guards, which rides the cart's version and answers with the cart in its new currency.
 *
 * The store sells in USD and EUR; CHF is enabled without a current rate, and JPY has a rate but
 * is not enabled.
 *
 * Planted violations, each named on its test.
 *
 * @group international
 *
 * @since 0.1.0
 */
final class ChangeCartCurrencyOperationTest extends CheckoutTestCase {

	use ServesStoreApi;
	use PricesInCurrencies;

	/**
	 * Enables the currencies, records their rates as current, wires the kernel and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createRateTables();
		$this->enableCurrency( 'EUR' );
		$this->enableCurrency( 'CHF' );
		$this->enableCurrency( 'JPY', true, RoundingMode::HalfUp, 0, false );
		$this->plantBootRecord( self::ratesOver( $this->db, static function (): void {} )->saveVersion( array( self::rateTo( 'EUR', '0.91230' ), self::rateTo( 'JPY', '149.50' ) ), Actor::user( 0 ) ) );
		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals, discards the server and restores the installation record.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->shutStoreApi();
		$this->removeBootRecord();

		parent::tear_down();
	}

	/**
	 * Tests a switch over the wire: it moves the cart's version on, answers the cart priced in the new currency, sends the cart's cookie again, and a replay is refused as stale with the totals in the new currency.
	 *
	 * Planted violation: in CheckoutOperations::changeCurrency(), declare the route with
	 * WriteMethod::Put: the route then answers POST with no route, 404.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_answers_the_cart_in_the_new_currency(): void {
		$shirt = self::variant();

		$this->price( array( $shirt => 1999 ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $shirt => 1 ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[0]['value'] );

		$body     = array(
			'cart_version' => 1,
			'currency'     => 'EUR',
		);
		$switched = $this->store( 'POST', CheckoutOperations::CURRENCY_ROUTE, $body );

		$this->assertSame( 200, $switched['status'], (string) wp_json_encode( $switched['body'] ) );
		$this->assertSame( array( 'version', 'lines', 'promotion_codes', 'totals', 'unpriced_lines' ), array_keys( $switched['body'] ) );
		$this->assertSame( array( 2, 'EUR', array() ), array( $switched['body']['version'], $switched['body']['totals']['currency'], $switched['body']['unpriced_lines'] ) );
		$this->assertSame( array( $shirt ), array_column( $switched['body']['lines'], 'variant_id' ) );
		$this->assertSame( 1824, $switched['body']['totals']['lines'][0]['unit_price_minor'] ?? null, '19.99 USD at 0.91230 is 18.24 EUR.' );
		$this->assertCount( 2, $this->cookies, 'The switch did not send the cart\'s cookie again.' );
		$this->assertSame( $this->cookies[0]['value'], $switched['headers'][ CartTokenTransport::HEADER ] ?? null );
		$this->assertStringContainsString( 'no-store', $switched['headers']['Cache-Control'] ?? '' );

		$replay = $this->store( 'POST', CheckoutOperations::CURRENCY_ROUTE, $body );

		$this->assertSame( 409, $replay['status'] );
		$this->assertErrorShape( $replay['body'], 'cart.version_stale', 409 );
		$this->assertSame( 'EUR', $replay['body']['data']['details']['totals']['currency'] ?? null );
		$this->assertSame( array( 2, 'EUR' ), array( $this->store( 'GET', CartOperations::CART_ROUTE )['body']['version'] ?? null, $this->store( 'GET', CartOperations::CART_ROUTE )['body']['totals']['currency'] ?? null ) );
	}

	/**
	 * Tests that a currency the store does not sell in gets one answer, byte for byte but for its correlation id, whatever the reason; it sends no cookie and changes nothing.
	 *
	 * Planted violation: in ChangeCartCurrency::sold(), let Currency::of() refuse a code that is
	 * not one itself (drop its try): `eur` then answers `support.unknown_currency`, another body.
	 *
	 * @since 0.1.0
	 */
	public function test_a_currency_the_store_does_not_sell_in_gets_one_answer(): void {
		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( self::variant() => 1 ) ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		$bodies = array();

		foreach ( array( 'JPY', 'CHF', 'eur' ) as $code ) {
			$refused = $this->store(
				'POST',
				CheckoutOperations::CURRENCY_ROUTE,
				array(
					'cart_version' => 1,
					'currency'     => $code,
				)
			);

			$this->assertSame( 422, $refused['status'], $code . ': ' . (string) wp_json_encode( $refused['body'] ) );
			$this->assertErrorShape( $refused['body'], 'checkout.currency_not_enabled', 422 );

			unset( $refused['body']['data']['correlation_id'] );

			$bodies[ $code ] = (string) wp_json_encode( $refused['body'] );
		}

		$this->assertCount( 1, array_unique( $bodies ), 'The refusals differ: ' . implode( "\n", $bodies ) );
		$this->assertStringNotContainsString( 'EUR', $bodies['JPY'], 'The refusal names a currency the store sells in.' );
		$this->assertCount( 1, $this->cookies, 'A refused switch sent the cart\'s cookie.' );
		$this->assertSame( array( 1, 'USD' ), array( $this->store( 'GET', CartOperations::CART_ROUTE )['body']['version'] ?? null, $this->store( 'GET', CartOperations::CART_ROUTE )['body']['totals']['currency'] ?? null ) );
	}

	/**
	 * Tests that a switch is refused without the Store API's header, without a cart token, and with a currency longer than a code; each changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_the_store_api_cannot_take_is_refused(): void {
		$body       = array(
			'cart_version' => 1,
			'currency'     => 'EUR',
		);
		$headerless = $this->serve( $this->server, 'POST', '/seocart/store/v1' . CheckoutOperations::CURRENCY_ROUTE, array(), $body );

		$this->assertSame( 403, $headerless['status'] );
		$this->assertErrorShape( $headerless['body'], 'store_api.header_missing', 403 );

		$cartless = $this->store( 'POST', CheckoutOperations::CURRENCY_ROUTE, $body );

		$this->assertSame( 'store_api.cart_token_missing', $cartless['body']['code'] ?? null, (string) wp_json_encode( $cartless['body'] ) );

		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( self::variant() => 1 ) ) );

		$this->assertSame( 200, $started['status'] );
		$this->presentCookie( $this->cookies[0]['value'] );

		$long = $this->store(
			'POST',
			CheckoutOperations::CURRENCY_ROUTE,
			array(
				'cart_version' => 1,
				'currency'     => 'EURO',
			)
		);

		$this->assertSame( array( 400, 'rest_invalid_param' ), array( $long['status'], $long['body']['code'] ?? null ) );
		$this->assertSame( 1, $this->store( 'GET', CartOperations::CART_ROUTE )['body']['version'] ?? null );
	}
}
