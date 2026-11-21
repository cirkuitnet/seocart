<?php
/**
 * ChecksOutOverTheWire: a shopper's checkout walked over the Store API, with the store's checkout choices set between requests
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Application\CheckoutSettings;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Platform\Settings\Settings;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Doubles\RecordingGateway;

/**
 * Starts a cart, writes its checkout and places it, each a request of its own served through the production wiring; and sets the store's presence choices and order limits as staff would have.
 *
 * Owns one fact for the tests: what a request is. The kernel's container lives for one request,
 * and the checkout's rules with it, so each request here is served by a container of its own
 * (newRequest()), as WordPress serves each from the web; the cart's cookie is presented again.
 * Used with ServesStoreApi in a PlacementTestCase.
 *
 * @since 0.2.0
 */
trait ChecksOutOverTheWire {

	/**
	 * The replacements each request's container is booted with, such as a gateway that records what it is handed; none by default.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, callable(\SEOCart\Platform\Kernel\Container): object>
	 */
	protected array $wireReplacements = array();

	/**
	 * Serves every following request with the stand-in gateway recording what a placement hands it, and returns it.
	 *
	 * @since 0.2.0
	 *
	 * @return RecordingGateway The gateway: its authorizations list each payment request it was given.
	 */
	protected function recordingGatewayOverTheWire(): RecordingGateway {
		$gateway = new RecordingGateway( new StubGateway(), $this->db );

		$this->wireReplacements = array( PaymentGateway::class => static fn(): PaymentGateway => $gateway );

		return $gateway;
	}

	/**
	 * Starts a new cart with one line of a sellable variant, and presents its cookie.
	 *
	 * @since 0.2.0
	 *
	 * @param int $priceMinor Optional. The variant's price, in minor units. Default 1999.
	 * @param int $quantity   Optional. The units. Default 1.
	 * @return int The variant.
	 */
	protected function cartOverTheWire( int $priceMinor = 1999, int $quantity = 1 ): int {
		unset( $_COOKIE[ CartTokenTransport::COOKIE ] );

		$variant = $this->sellable( max( 5, $quantity ), $priceMinor );
		$started = $this->store( 'POST', CartOperations::LINES_ROUTE, self::linesBody( array( $variant => $quantity ) ) );

		$this->assertSame( 200, $started['status'], (string) wp_json_encode( $started['body'] ) );
		$this->presentCookie( $this->cookies[ count( $this->cookies ) - 1 ]['value'] );

		return $variant;
	}

	/**
	 * Writes the cart's checkout in a request of its own.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $body The session write's body, without the cart's version, which the cart gives.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	protected function writeOverTheWire( array $body ): array {
		$this->newRequest();

		$cart = $this->store( 'GET', CartOperations::CART_ROUTE );

		$this->assertSame( 200, $cart['status'], (string) wp_json_encode( $cart['body'] ) );

		return $this->store( 'PUT', CheckoutOperations::ROUTE, array( 'cart_version' => $cart['body']['version'] ) + $body );
	}

	/**
	 * Places the cart in a request of its own, at the version and grand total a session write answered.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $written The session write's answer.
	 * @param string               $key     The idempotency key.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	protected function placeOverTheWire( array $written, string $key ): array {
		$this->newRequest();

		return $this->serve(
			$this->server,
			'POST',
			'/' . RestBinding::STORE_NAMESPACE . CheckoutOperations::ROUTE,
			array(
				StoreRequestPolicy::HEADER             => StoreRequestPolicy::HEADER_VALUE,
				CheckoutOperations::IDEMPOTENCY_HEADER => $key,
			),
			array(
				'cart_version'      => $written['version'],
				'grand_total_minor' => $written['totals']['summary']['grand_minor'],
				'currency'          => $written['totals']['currency'],
				'payment_data'      => array( 'payment_token' => self::APPROVE ),
			)
		);
	}

	/**
	 * Stores the presences the store chose, as `checkout.update_fields` keeps them: only those that differ from a field's default.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $presences Presence by field key.
	 */
	protected function choosePresences( array $presences ): void {
		ksort( $presences );

		( new SettingsStore( Settings::registry(), $this->db ) )->writeScalars( array( CheckoutSettings::FIELD_PRESENCE => (string) wp_json_encode( (object) $presences ) ) );
	}

	/**
	 * Stores the store's order limits, as `checkout.update_order_limits` keeps them.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array{0: int|null, 1: int|null}> $limits The minimum and the maximum, by currency code.
	 */
	protected function chooseLimits( array $limits ): void {
		$stored = array();

		foreach ( $limits as $currency => $bounds ) {
			$stored[ $currency ] = array(
				'max' => $bounds[1],
				'min' => $bounds[0],
			);
		}

		ksort( $stored );

		( new SettingsStore( Settings::registry(), $this->db ) )->writeScalars( array( CheckoutSettings::ORDER_LIMITS => (string) wp_json_encode( (object) $stored ) ) );
	}

	/**
	 * Removes the store's checkout choices, which a test's own options outlive otherwise.
	 *
	 * @since 0.2.0
	 */
	protected function forgetCheckoutChoices(): void {
		foreach ( CheckoutSettings::settings() as $setting ) {
			delete_option( $setting->optionName() );
		}
	}

	/**
	 * Ends the request: the next one is served by a container of its own, which reads the store's choices afresh.
	 *
	 * @since 0.2.0
	 */
	protected function newRequest(): void {
		$cookie = isset( $_COOKIE[ CartTokenTransport::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ CartTokenTransport::COOKIE ] ) ) : null;

		$this->shutStoreApi();
		wp_cache_flush();
		$this->bootStoreApi( $this->wireReplacements );

		if ( null !== $cookie ) {
			$this->presentCookie( (string) $cookie );
		}
	}
}
