<?php
/**
 * Tests the checkout session write: behind the cart's version, invalidating the quotes, and priced for the shipping address
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\CheckoutTestCase;

/**
 * `checkout.update_session` through its service: the cart's version moves on with every write, the session's quotes go with it, and the answer's totals charge shipping once there is a shipping address.
 *
 * The cart holds one line of a variant at 19.99 net; the calculation is the kernel's, with the
 * stub flat rate of 5.00 net and the stub tax rate of 20 %.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class UpdateCheckoutSessionTest extends CheckoutTestCase {

	/**
	 * The variant of the test's cart.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $shirt;

	/**
	 * Prices the test's variant.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->shirt = self::variant();
		$this->price( array( $this->shirt => 1999 ) );
	}

	/**
	 * Tests that a write moves the cart's version on, stores the details, and drops the quotes the session held.
	 *
	 * Planted violation: in CartService::changeWith(), run the closure in a transaction of its own,
	 * without the compare-and-swap: the version then stays where it was, so the quotes of the old
	 * address would still look current.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_moves_the_version_on_stores_the_details_and_drops_the_quotes(): void {
		$cart  = $this->startCart( array( $this->shirt => 1 ) );
		$first = $this->checkout->update( $this->input( 1, array( 'shipping_address' => AddressDocument::of( self::address( 'GB' ) ) ) ), self::guest() );

		$this->assertSame( 2, $first['version'] );
		$this->assertTrue( $this->sessions->storeQuotes( $cart->id, 2, new FrozenQuotes( null, self::digest( 'tax quote' ) ) ) );

		$issued = count( $this->tokens->issued );
		$answer = $this->checkout->update(
			$this->input(
				2,
				array(
					'billing_address'     => AddressDocument::of( self::address( 'GB', 'ada@example.com' ) ),
					'shipping_address'    => AddressDocument::of( self::address( 'DE' ) ),
					'shipping_method_key' => 'flat',
					'payment_method_key'  => 'stub',
				)
			),
			self::guest()
		);

		$this->assertSame( 3, $answer['version'] );
		$this->assertSame( 3, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null );
		$this->assertSame(
			array(
				'billing_address'     => AddressDocument::of( self::address( 'GB', 'ada@example.com' ) ),
				'shipping_address'    => AddressDocument::of( self::address( 'DE' ) ),
				'shipping_method_key' => 'flat',
				'payment_method_key'  => 'stub',
			),
			$answer['checkout_session']
		);

		$session = $this->sessions->find( $cart->id );

		$this->assertNotNull( $session );
		$this->assertSame( array( 0, null ), array( $session->quotedAtCartVersion, $session->quotes ) );
		$this->assertFalse( $session->quotesAreCurrentAt( 3 ) );
		$this->assertTrue( self::address( 'DE' )->equals( $session->details->shippingAddress ?? self::address( 'US' ) ) );
		$this->assertLivesFor( $cart->id, self::TTL_SECONDS, 'The write did not extend the cart.' );
		$this->assertCount( $issued + 1, $this->tokens->issued, 'A write that changes the cart did not issue its token again.' );
	}

	/**
	 * Tests that the answer's totals charge the stub flat rate once a shipping address is set, and that the cart's own read then charges it too.
	 *
	 * Planted violation: in CartService::calculate(), leave the delivery out of the calculation
	 * request: the totals then charge no shipping wherever the order goes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_totals_charge_shipping_once_a_shipping_address_is_set(): void {
		$this->startCart( array( $this->shirt => 1 ) );

		$billed = $this->checkout->update( $this->input( 1, array( 'billing_address' => AddressDocument::of( self::address( 'GB' ) ) ) ), self::guest() );

		$this->assertSame( array( 0, 2399 ), array( $billed['totals']['summary']['shipping_total_minor'], $billed['totals']['summary']['grand_minor'] ), 'A cart without a shipping address was charged shipping.' );

		$shipped = $this->checkout->update( $this->input( 2, array( 'shipping_address' => AddressDocument::of( self::address( 'GB' ) ) ) ), self::guest() );

		$this->assertSame( 500, $shipped['totals']['summary']['shipping_total_minor'], 'The stub flat rate was not charged to the shipping address.' );
		$this->assertSame( 2999, $shipped['totals']['summary']['grand_minor'], '19.99 and 5.00 shipping, each with 20 % tax.' );
		$this->assertContains( 'shipping:flat', array_column( $shipped['totals']['adjustments'], 'source' ) );

		$read = $this->service->getCart( array(), self::guest() );

		$this->assertSame( array( 3, 2999 ), array( $read['version'], $read['totals']['summary']['grand_minor'] ), 'The cart\'s own read does not charge the shipping its checkout chose.' );
	}

	/**
	 * Tests that a write based on an older version changes neither the cart nor its session, and is told the version and totals the cart has now.
	 *
	 * Planted violation: in MysqlCartRepository's compare-and-swap condition, replace
	 * `version = %d` with `%d > 0`: the stale write is then applied, and replaces the session's
	 * shipping address.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_write_changes_nothing_and_is_told_the_carts_totals(): void {
		$cart = $this->startCart( array( $this->shirt => 1 ) );

		$this->checkout->update( $this->input( 1, array( 'shipping_address' => AddressDocument::of( self::address( 'GB' ) ) ) ), self::guest() );

		try {
			$this->checkout->update( $this->input( 1, array( 'shipping_address' => AddressDocument::of( self::address( 'FR' ) ) ) ), self::guest() );
			$this->fail( 'A write based on version 1 was applied to the cart at version 2.' );
		} catch ( CodedException $stale ) {
			$this->assertSame( CartError::VersionStale, $stale->errorCode() );
			$this->assertSame( array( 'current_version' => 2 ), $stale->context() );
			$this->assertSame( 2999, $stale->details()['totals']['summary']['grand_minor'] ?? null, 'The refusal\'s totals do not charge the shipping of the cart\'s session.' );
		}

		$session = $this->sessions->find( $cart->id );

		$this->assertNotNull( $session );
		$this->assertTrue( self::address( 'GB' )->equals( $session->details->shippingAddress ?? self::address( 'US' ) ), 'The stale write changed the session.' );
		$this->assertSame( 2, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null );
	}

	/**
	 * Tests that an address the checkout cannot use is refused, naming its field, before anything is written.
	 *
	 * Planted violation: in UpdateCheckoutSession::address(), leave out the e-mail check: a billing
	 * address with an e-mail address that is not one is then stored.
	 *
	 * @since 0.1.0
	 */
	public function test_an_invalid_address_is_refused_before_anything_is_written(): void {
		$cart    = $this->startCart( array( $this->shirt => 1 ) );
		$invalid = array(
			'shipping_address.country' => array( 'shipping_address' => array( 'country' => 'gb' ) + AddressDocument::of( self::address( 'GB' ) ) ),
			'billing_address.country'  => array( 'billing_address' => array( 'country' => 'GBR' ) + AddressDocument::of( self::address( 'GB' ) ) ),
			'billing_address.email'    => array( 'billing_address' => AddressDocument::of( self::address( 'GB', 'not an e-mail address' ) ) ),
		);

		foreach ( $invalid as $field => $details ) {
			try {
				$this->checkout->update( $this->input( 1, $details ), self::guest() );
				$this->fail( $field . ' was accepted.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( CheckoutError::InvalidAddress, $refused->errorCode() );
				$this->assertSame( array( 'field' => $field ), $refused->context() );
			}
		}

		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
		$this->assertSame( 1, $this->committedCart( $this->secondConnection(), $cart->id )['version'] ?? null );
	}

	/**
	 * Tests that a cart an order is being placed from refuses the write, and a request without a cart is told there is none.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placing_cart_and_a_missing_cart_refuse_the_write(): void {
		$cart = $this->startCart( array( $this->shirt => 1 ) );

		$this->plantStatus( $cart->id, CartStatus::Placing, 41 );

		$refusals = array(
			'placing' => CartError::NotOpen,
			'none'    => CartError::NotFound,
		);

		foreach ( $refusals as $case => $code ) {
			if ( 'none' === $case ) {
				$this->tokens->presented = null;
			}

			try {
				$this->checkout->update( $this->input( 1, array( 'shipping_address' => AddressDocument::of( self::address( 'GB' ) ) ) ), self::guest() );
				$this->fail( 'The write was applied to a cart it may not change: ' . $case . '.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( $code, $refused->errorCode(), $case );
			}
		}

		$this->assertSame( 0, $this->checkoutRows( CheckoutTables::SESSIONS ) );
	}

	/**
	 * Builds the prepared input of a write.
	 *
	 * @since 0.1.0
	 *
	 * @param int                  $version The cart version.
	 * @param array<string, mixed> $details The details sent.
	 * @return array<string, mixed> The input.
	 */
	private function input( int $version, array $details ): array {
		return array( 'cart_version' => $version ) + $details;
	}
}
