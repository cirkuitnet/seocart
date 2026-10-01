<?php
/**
 * Tests the retention sweep of the cart tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\Jobs\SweepExpiredCarts;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\MysqlCheckoutSessions;
use SEOCart\Support\Address;
use SEOCart\Tests\Support\Cart\CartTestCase;

/**
 * After the sweep runs over carts kept past their policy, no expired cart and no line of one is
 * left, and every live cart keeps its lines; a run stops at its budget and leaves the rest to
 * the next.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class SweepExpiredCartsTest extends CartTestCase {

	/**
	 * Tests that the sweep leaves the cart tables at their policy: every expired cart and its lines gone, every live cart and its lines kept.
	 *
	 * Seven carts are kept past their policy, from a second to thirty days after they expired, and
	 * three are live; the sweep runs in pages of two, so it needs four of them.
	 *
	 * Planted violations: in SweepExpiredCarts::handle(), stop after the first page (`while
	 * ( false )`): five expired carts are left. In MysqlCartRepository::DELETE_EXPIRED, delete
	 * from the carts alone (`DELETE c FROM`): the expired carts' lines are left behind them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_leaves_the_cart_tables_at_their_policy(): void {
		$live    = array();
		$expired = array();

		foreach ( array( 1, 60, 3600, 86400, 7 * 86400, 14 * 86400, 30 * 86400 ) as $ago ) {
			$cart = $this->startCart(
				array(
					self::variant() => 1,
					self::variant() => 2,
				)
			);

			$this->expire( $cart->id, $ago );
			$expired[] = $cart->id;
		}

		for ( $cart = 0; $cart < 3; $cart++ ) {
			$live[] = $this->startCart( array( self::variant() => 1 ) )->id;
		}

		( new SweepExpiredCarts( $this->repository, 2 ) )->handle( array() );

		$b = $this->secondConnection();

		foreach ( $expired as $cartId ) {
			$this->assertNull( $this->committedCart( $b, $cartId ), 'An expired cart was left.' );
			$this->assertSame( array(), $this->committedLines( $b, $cartId ), 'An expired cart\'s lines were left.' );
		}

		foreach ( $live as $cartId ) {
			$this->assertNotNull( $this->committedCart( $b, $cartId ), 'A live cart was swept.' );
			$this->assertCount( 1, $this->committedLines( $b, $cartId ) );
		}

		$this->assertSame( 3, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( 3, $this->rowsOf( CartTables::LINES ) );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE expires_at <= UTC_TIMESTAMP()', $this->table( CartTables::CARTS ) ) );
	}

	/**
	 * Tests that the sweep deletes an expired cart's checkout session with it, and keeps a live cart's.
	 *
	 * Planted violation: in MysqlCartRepository::DELETE_EXPIRED, delete from the carts and their
	 * lines alone (`DELETE c, l FROM`): the expired cart's session, addresses and all, outlives it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_deletes_an_expired_carts_checkout_session_with_it(): void {
		$sessions = new MysqlCheckoutSessions( $this->db );
		$expired  = $this->startCart( array( self::variant() => 1 ) );
		$live     = $this->startCart( array( self::variant() => 1 ) );

		foreach ( array( $expired, $live ) as $cart ) {
			$this->db->transaction( fn() => $sessions->save( $cart->id, new CheckoutDetails( null, new Address( 'GB' ), null, null ) ) );
		}

		$this->expire( $expired->id );

		( new SweepExpiredCarts( $this->repository ) )->handle( array() );

		$this->assertNull( $sessions->find( $expired->id ), 'The expired cart\'s checkout session outlived it.' );
		$this->assertNotNull( $sessions->find( $live->id ), 'A live cart\'s checkout session was swept.' );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( CheckoutTables::SESSIONS ) ) );
	}

	/**
	 * Tests that a run stops once its budget is spent, and that the next run deletes the rest.
	 *
	 * @since 0.1.0
	 */
	public function test_a_run_stops_at_its_budget_and_the_next_run_goes_on(): void {
		for ( $cart = 0; $cart < 5; $cart++ ) {
			$this->expire( $this->startCart( array( self::variant() => 1 ) )->id );
		}

		$ticks = 0;
		$clock = static function () use ( &$ticks ): int {
			// The deadline is taken first; every later look finds the budget spent.
			return 0 === $ticks++ ? 0 : SweepExpiredCarts::BUDGET_SECONDS * 1000000000;
		};

		( new SweepExpiredCarts( $this->repository, 2, $clock ) )->handle( array() );

		$this->assertSame( 3, $this->rowsOf( CartTables::CARTS ), 'One page of two, then the budget was spent.' );

		( new SweepExpiredCarts( $this->repository, 2 ) )->handle( array() );

		$this->assertSame( 0, $this->rowsOf( CartTables::CARTS ) );
		$this->assertSame( 0, $this->rowsOf( CartTables::LINES ) );
	}
}
