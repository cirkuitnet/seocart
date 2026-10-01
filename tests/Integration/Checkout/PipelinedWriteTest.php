<?php
/**
 * Tests a cart write sent at the version another write is leaving, whose cart was read before that write committed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\CurrencySwitchTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * A write whose cart was read before another write committed is refused as stale, even at the version that write left, and changes nothing.
 *
 * A write reads the cart's row before its transaction, and answers with that row's promotion codes
 * and currency. A client that sends the version a write of its own is about to leave, before it
 * has the answer, can meet that write committing between the read and the compare-and-swap; the
 * swap alone would then match, and the answer would describe a cart that no longer is. Connection
 * A runs the plugin's code over wpdb; at the moment A opens its transaction, after its read,
 * connection B commits the other write with the repository's own statement. A is refused with
 * `cart.version_stale`, whose details carry the cart as B left it, and a write sent again at that
 * version goes through.
 *
 * Planted violation: in CartService::change() and CartService::changeCurrency(), drop the guard
 * `$expectedVersion !== $cart->version ||`: each write is then accepted, and answered from the
 * row read before B committed.
 *
 * @group concurrency
 * @group international
 *
 * @since 0.1.0
 */
final class PipelinedWriteTest extends CurrencySwitchTestCase {

	/**
	 * The first statement of a write's transaction, sent after its read of the cart.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TRANSACTION_OPENS = '/^SET TRANSACTION ISOLATION LEVEL/';

	/**
	 * Tests that a checkout write read before a switch committed is refused, and the session keeps its details.
	 *
	 * @since 0.1.0
	 */
	public function test_a_session_write_read_before_a_switch_is_refused(): void {
		$cart  = $this->readyCart( array( $this->sellable( 5, 1000 ) => 1 ) );
		$b     = $this->secondConnection();
		$raced = $this->commitsFirst( $b, $this->rawSwitch( $cart->id, $cart->version, 'EUR' ) );

		$stale = $this->assertRefused(
			CartError::VersionStale,
			fn() => $this->kernel->get( UpdateCheckoutSession::class )->update(
				array(
					'cart_version'       => $cart->version + 1,
					'payment_method_key' => 'stub',
				),
				self::guest()
			)
		);

		$this->assertTrue( $raced->fired, 'B never wrote between the read and the transaction.' );
		$this->assertStaleAt( $stale, $cart->version + 1, 'EUR' );
		$this->assertNotNull( $this->sessions->find( $cart->id )?->details->billingAddress, 'The refused write replaced the session\'s details.' );
		$this->assertSame( array( (string) ( $cart->version + 1 ), 'EUR' ), array( $this->committedSwitch( $b, $cart->id )['version'] ?? null, $this->committedSwitch( $b, $cart->id )['currency'] ?? null ) );
	}

	/**
	 * Tests that a switch read before a code was applied is refused, and its refusal carries the code's discount.
	 *
	 * @since 0.1.0
	 */
	public function test_a_switch_read_before_a_code_was_applied_is_refused(): void {
		$this->plantPromotion( 'TENOFF' );

		$cart  = $this->readyCart( array( $this->sellable( 5, 3000 ) => 1 ) );
		$b     = $this->secondConnection();
		$raced = $this->commitsFirst( $b, $this->raw( MysqlCartRepository::SWAP_PROMOTION_CODES, $this->table( CartTables::CARTS ), '["TENOFF"]', self::TTL_SECONDS, $cart->id, $cart->version ) );
		$stale = $this->assertRefused( CartError::VersionStale, fn() => $this->switchTo( 'EUR', $cart->version + 1 ) );

		$this->assertTrue( $raced->fired, 'B never wrote between the read and the transaction.' );
		$this->assertStaleAt( $stale, $cart->version + 1, 'USD' );
		$this->assertLessThan( 0, $stale->details()['totals']['summary']['discount_total_minor'] ?? 0, 'The refusal carries the totals with the code B applied.' );
		$this->assertSame( 'USD', $this->committedSwitch( $b, $cart->id )['currency'] ?? null, 'The refused switch changed the currency.' );
	}

	/**
	 * Tests that adding lines read before a switch committed is refused and adds nothing, and that the same lines sent again at the version the refusal names are added.
	 *
	 * @since 0.1.0
	 */
	public function test_adding_lines_read_before_a_switch_is_refused(): void {
		$mug   = $this->sellable( 5, 1000, 'Mug' );
		$tee   = $this->sellable( 5, 2500, 'Tee' );
		$cart  = $this->readyCart( array( $mug => 1 ) );
		$b     = $this->secondConnection();
		$carts = $this->kernel->get( CartService::class );
		$raced = $this->commitsFirst( $b, $this->rawSwitch( $cart->id, $cart->version, 'EUR' ) );
		$stale = $this->assertRefused( CartError::VersionStale, fn() => $carts->add( self::lines( array( $tee => 1 ) ), $cart->version + 1, self::guest() ) );

		$this->assertTrue( $raced->fired, 'B never wrote between the read and the transaction.' );
		$this->assertStaleAt( $stale, $cart->version + 1, 'EUR' );
		$this->assertSame( array( $mug . ':1' ), $this->committedLines( $b, $cart->id ), 'The refused write added its line.' );

		$again = $carts->add( self::lines( array( $tee => 1 ) ), $cart->version + 1, self::guest() );

		$this->assertSame( array( $cart->version + 2, 'EUR' ), array( $again->version, $again->currency->code() ), 'Sent again at the version the refusal names, the write goes through.' );
		$this->assertSame( array( $mug . ':1', $tee . ':1' ), $this->committedLines( $b, $cart->id ) );
	}

	/**
	 * Has connection B commit a statement at the moment wpdb's connection opens its next transaction, after the write's read of the cart.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param string           $statement The other write's statement, prepared.
	 * @return object{fired: bool, seen: int} Whether B wrote.
	 */
	private function commitsFirst( SecondConnection $b, string $statement ): object {
		return $this->beforeStatement(
			self::TRANSACTION_OPENS,
			function () use ( $b, $statement ): void {
				$b->query( $statement );

				$this->assertSame( 1, $b->affectedRows(), 'B\'s write did not commit.' );
			}
		);
	}

	/**
	 * Asserts that a stale refusal names a version and carries the totals of the cart at it, in a currency.
	 *
	 * @since 0.1.0
	 *
	 * @param CodedException $stale    The refusal.
	 * @param int            $version  The version the cart is at now.
	 * @param string         $currency The cart's currency now.
	 */
	private function assertStaleAt( CodedException $stale, int $version, string $currency ): void {
		$this->assertSame( array( 'current_version' => $version ), $stale->context() );
		$this->assertSame( $currency, $stale->details()['totals']['currency'] ?? null, 'The refusal carries the totals of the cart as B left it.' );
	}
}
