<?php
/**
 * Tests a currency switch against a write raced at the same cart version: another switch, and an order placement
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Tests\Support\Checkout\CurrencySwitchTestCase;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Of two writes based on the same version of a cart, one applies; a switch and a placement never leave an order in one currency on a cart in another.
 *
 * Connection A runs the plugin's code over wpdb. The other side starts at the moment A, past its
 * own compare-and-swap, is about to send its next statement, and the server's process list shows
 * it waiting for A's lock on the cart; nothing waits on the clock. The other side is either
 * connection B sending the switch's own compare-and-swap, then the whole switch over a second
 * connection once A has committed, or a placement in a process of its own.
 *
 * Planted violations, each named on its test.
 *
 * @group concurrency
 * @group international
 *
 * @since 0.1.0
 */
final class CurrencySwitchConcurrencyTest extends CurrencySwitchTestCase {

	/**
	 * The statement after a switch's compare-and-swap: its drop of the session's quotes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const QUOTES_DROPPED = '/^UPDATE `[^`]+checkout_sessions` SET quoted_at_cart_version = 0, shipping_quote_json = NULL/';

	/**
	 * Tests that a stale switch loses to the switch it raced: B waits for A's lock, then changes nothing, and is told the version and the totals A left, in A's currency.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, replace `version = %d` with
	 * `%d > 0`: B's compare-and-swap then goes through once A commits, both switches win, and the
	 * cart ends two versions on, in B's currency.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_switch_loses_to_the_switch_it_raced(): void {
		$cart = $this->readyCart( array( $this->sellable( 5, 1000 ) => 1 ) );
		$b    = $this->secondConnection();
		$swap = $this->rawSwitch( $cart->id, $cart->version, 'GBP' );

		$raced = $this->beforeStatement(
			self::QUOTES_DROPPED,
			function () use ( $b, $swap ): void {
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $swap );
				$this->awaitWaiting( $b, $swap, 'updating' );
			}
		);

		$won = $this->switchTo( 'EUR', $cart->version );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( $cart->version + 1, $won['version'] );
		$this->assertSame( 0, $b->reap(), 'B\'s compare-and-swap changed the cart A had moved on.' );

		$b->query( 'ROLLBACK' );

		$stale = $this->assertRefused( CartError::VersionStale, fn() => $this->secondSwitch()->change( self::switchInput( $cart->version, 'GBP' ), self::guest() ), 'B, the whole switch, at the version both read' );

		$this->assertSame( array( 'current_version' => $cart->version + 1 ), $stale->context() );
		$this->assertSame(
			array( 'EUR', $won['totals']['summary']['grand_minor'] ),
			array( $stale->details()['totals']['currency'] ?? null, $stale->details()['totals']['summary']['grand_minor'] ?? null ),
			'The refusal carries the totals of the cart the winner left, in its currency.'
		);
		$this->assertSame( array( (string) ( $cart->version + 1 ), 'EUR' ), array( $this->committedSwitch( $b, $cart->id )['version'] ?? null, $this->committedSwitch( $b, $cart->id )['currency'] ?? null ), 'The currency changed exactly once.' );
	}

	/**
	 * Tests a switch racing a placement that claimed the cart first: the switch waits for the placement, then changes nothing and is refused naming the order, whose currency is its intent's and the cart's.
	 *
	 * Planted violation: in MysqlCartRepository::SWAP_CURRENCY, use the condition
	 * `WHERE id = %d AND expires_at > UTC_TIMESTAMP()`, without the version and the status: B's
	 * compare-and-swap then lands on the placing cart once the placement commits, and the order in
	 * USD sits on a cart in EUR.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_racing_a_placement_that_claimed_the_cart_first_changes_nothing(): void {
		$cart  = $this->readyCart( array( $this->sellable( 5, 1000 ) => 1 ) );
		$input = $this->placeInput( 'raced', StubGateway::REQUIRES_ACTION );
		$b     = $this->secondConnection();
		$swap  = $this->rawSwitch( $cart->id, $cart->version, 'EUR' );

		$raced = $this->beforeStatement(
			'/^INSERT INTO `[^`]+idempotency_keys`/',
			function () use ( $b, $swap ): void {
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $swap );
				$this->awaitWaiting( $b, $swap, 'updating' );
			}
		);

		$placed = $this->placement->place( $input, self::guest() );

		$this->assertTrue( $raced->fired, 'The switch never raced the placement.' );
		$this->assertSame( 'requires_action', $placed['outcome'] );
		$this->assertSame( 0, $b->reap(), 'The switch changed the cart the placement had claimed.' );

		$b->query( 'ROLLBACK' );

		$refused = $this->assertRefused( CartError::NotOpen, fn() => $this->secondSwitch()->change( self::switchInput( $cart->version, 'EUR' ), self::guest() ), 'The whole switch, at the version both read' );

		$this->assertSame( $placed['order_uuid'], $refused->details()['order_uuid'] ?? null );
		$this->assertOneApplied( $b, $cart->id, 'placing' );
	}

	/**
	 * Tests a placement racing a switch that took the cart first: the placement, in a process of its own, waits for the switch, then is refused as stale and places nothing, and the cart is in the new currency.
	 *
	 * The placement priced the cart in USD before its unit of work, while the switch had not
	 * committed.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, replace `version = %d` with
	 * `%d > 0`: the placement's claim then goes through once the switch commits, and places an
	 * order in USD from a cart now in EUR.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_racing_a_switch_that_took_the_cart_first_places_nothing(): void {
		$cart      = $this->readyCart( array( $this->sellable( 5, 1000 ) => 1 ) );
		$input     = $this->placeInput( 'raced', StubGateway::REQUIRES_ACTION );
		$token     = $this->tokens->presented;
		$placement = null;
		$claim     = sprintf( "UPDATE `%s` SET version = version + 1, status = 'placing'", $this->table( CartTables::CARTS ) );

		$this->assertNotNull( $token );

		$raced = $this->beforeStatement(
			self::QUOTES_DROPPED,
			function () use ( $token, $input, $claim, &$placement ): void {
				$placement = $this->startPlacement( $token, $input );

				$this->awaitProbeSending( $placement, $claim, 'updating' );
			}
		);

		$won    = $this->switchTo( 'EUR', $cart->version );
		$report = $placement?->finish() ?? array();

		$this->assertTrue( $raced->fired, 'The placement never raced the switch.' );
		$this->assertSame( 'EUR', $won['totals']['currency'] );
		$this->assertSame( CartError::VersionStale->value, $report['refused'] ?? $report, 'The placement found the cart moved on.' );
		$this->assertOneApplied( $this->secondConnection(), $cart->id, 'open' );
	}

	/**
	 * Returns a switch's input.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $version  The cart version the switch is based on.
	 * @param string $currency The currency to switch to.
	 * @return array<string, int|string> The input.
	 */
	private static function switchInput( int $version, string $currency ): array {
		return array(
			'cart_version' => $version,
			'currency'     => $currency,
		);
	}

	/**
	 * Returns the currency switch of a second runner: the kernel over a connection of its own, presenting the test's cart token.
	 *
	 * @since 0.1.0
	 *
	 * @return ChangeCartCurrency The switch.
	 */
	private function secondSwitch(): ChangeCartCurrency {
		$tokens            = new FakeCartTokens();
		$tokens->presented = $this->tokens->presented;

		return $this->secondKernel( $tokens )->get( ChangeCartCurrency::class );
	}

	/**
	 * Asserts that exactly one of the two raced writes applied: an order whose currency is its intent's and its placing cart's, or a cart in EUR and no order at all.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @param string           $status The cart's status the winner leaves: placing when the placement won, open when the switch did.
	 */
	private function assertOneApplied( SecondConnection $b, int $cartId, string $status ): void {
		$cart   = $this->committedSwitch( $b, $cartId );
		$orders = $this->committedCount( $b, OrderTables::ORDERS );

		$this->assertSame( $status, $cart['status'] ?? null );

		if ( 'placing' === $status ) {
			$order  = (string) $b->fetchValue( sprintf( 'SELECT currency FROM `%s`', $this->table( OrderTables::ORDERS ) ) );
			$intent = (string) $b->fetchValue( sprintf( 'SELECT currency FROM `%s`', $this->table( PaymentTables::INTENTS ) ) );

			$this->assertSame( 1, $orders, 'One order.' );
			$this->assertSame( array( 'USD', 'USD', 'USD' ), array( $order, $intent, $cart['currency'] ?? null ), 'The order, its intent and its cart are in one currency.' );

			return;
		}

		$this->assertSame( 0, $orders, 'No order.' );
		$this->assertSame( 0, $this->committedCount( $b, PaymentTables::INTENTS ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 0, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ) );
		$this->assertSame( 'EUR', $cart['currency'] ?? null, 'The cart is in the currency it was switched to.' );
	}
}
