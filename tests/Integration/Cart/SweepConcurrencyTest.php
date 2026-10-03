<?php
/**
 * Tests the sweep against a write that extends the life of a cart the sweep found expired, on two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Cart\Infrastructure\Jobs\SweepExpiredCarts;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Infrastructure\MysqlCheckoutSessions;
use SEOCart\Support\Address;
use SEOCart\Tests\Support\Cart\CartTestCase;

/**
 * A cart whose life a write extends while the sweep runs keeps every line.
 *
 * The sweep finds expired carts with a plain read, which sees only what is committed. So it can
 * find a cart that a write has already extended without committing yet: the write's
 * compare-and-swap ran while the cart was live, and the cart's old expiry passed before the write
 * committed. Each test plays the write and the sweep on two connections, one of them on a clock
 * set apart from the database's by the hour that separates the two moments, so the race is set
 * up by the statements' own clocks, and the order of the two sides by a barrier; nothing waits
 * on the clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class SweepConcurrencyTest extends CartTestCase {

	/**
	 * Tests that a write committed after the sweep found its cart, and before the sweep deletes anything, keeps the cart and every line.
	 *
	 * The cart expired a minute ago. B is a write that began before that: its clock is an hour
	 * behind the database's, so its compare-and-swap, the repository's own statement, finds the
	 * cart live and extends it, and B's transaction stays open. The sweep runs over wpdb, and its
	 * search finds the cart expired, since B's change is not committed. Just before the sweep's
	 * first deleting statement, B commits. The cart is then live at version 2, and must keep both
	 * its lines.
	 *
	 * Planted violation: in MysqlCartRepository::deleteExpired(), the old order: first delete the
	 * page's lines (`DELETE FROM {cart_lines} WHERE cart_id IN (...)`), then the page's carts that
	 * are still expired. The cart B kept alive is then left with no line.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_that_extends_a_cart_the_sweep_found_keeps_every_line(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$cart  = $this->startCart(
			array(
				$shirt => 1,
				$mug   => 2,
			)
		);
		$b     = $this->secondConnection();

		$this->expire( $cart->id, 60 );

		$b->query( 'SET timestamp = UNIX_TIMESTAMP() - 3600' );
		$b->query( 'START TRANSACTION' );
		$b->query( $this->raw( MysqlCartRepository::COMPARE_AND_SWAP, $this->table( CartTables::CARTS ), self::TTL_SECONDS, $cart->id, 1 ) );

		$this->assertSame( 1, $b->affectedRows(), 'B\'s write did not find the cart live, so nothing raced the sweep.' );

		$raced = $this->beforeStatement(
			'/^DELETE /',
			static function () use ( $b ): void {
				$b->query( 'COMMIT' );
			}
		);

		( new SweepExpiredCarts( $this->repository ) )->handle( array() );

		$this->assertTrue( $raced->fired, 'The sweep never came to delete the cart, so it never raced B.' );
		$this->assertSame( 2, $this->committedCart( $b, $cart->id )['version'] ?? null, 'The sweep deleted the cart B had extended.' );
		$this->assertSame( array( $shirt . ':1', $mug . ':2' ), $this->committedLines( $b, $cart->id ), 'The sweep deleted lines of the cart B had kept alive.' );
	}

	/**
	 * Tests that the sweep's deletion waits for a write that is extending the cart, and then keeps the cart and every line.
	 *
	 * A is the cart service over wpdb, adding a line: its compare-and-swap has extended the cart,
	 * and its transaction is open. B is the sweep, on a clock two hours ahead of the database's, so
	 * that the cart's committed expiry, an hour away, has passed for B, and the sweep's own search
	 * finds the cart. Just before A adds its line, B sends the sweep's deleting statement, built
	 * from its constant, and the server shows it waiting for A's lock. A commits. B's statement
	 * then decides on the cart as A left it, live for seven days, and deletes nothing.
	 *
	 * Planted violation: in MysqlCartRepository::DELETE_EXPIRED, drop
	 * `AND c.expires_at <= UTC_TIMESTAMP()`: once A commits, B's statement deletes the cart A has
	 * just extended, with its lines.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_waits_for_a_write_that_is_extending_a_cart_and_keeps_it(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$cart  = $this->startCart( array( $shirt => 1 ) );
		$b     = $this->secondConnection();
		$carts = $this->table( CartTables::CARTS );

		$this->expiresIn( $cart->id, 3600 );

		$b->query( 'SET timestamp = UNIX_TIMESTAMP() + 7200' );

		$delete = $this->raw( MysqlCartRepository::forIds( MysqlCartRepository::DELETE_EXPIRED, 1 ), $carts, $this->table( CartTables::LINES ), $this->table( MysqlCartRepository::CHECKOUT_SESSIONS ), $cart->id );
		$raced  = $this->beforeStatement(
			self::shapeOf( MysqlCartRepository::ADD_LINES ),
			function () use ( $b, $carts, $cart, $delete ): void {
				$this->assertSame( (string) $cart->id, $b->fetchValue( $this->raw( MysqlCartRepository::EXPIRED, $carts, SweepExpiredCarts::BATCH ) ), 'The sweep\'s search did not find the cart, so nothing raced A.' );

				$b->queryAsync( $delete );

				// One cart named by its key is read, and its lock waited for, while the server plans the statement.
				$this->awaitWaiting( $b, $delete, 'statistics' );
			}
		);

		$won = $this->service->add( self::lines( array( $mug => 1 ) ), 1, self::guest() );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 2, $won->version );
		$this->assertSame( 0, $b->reap(), 'The sweep deleted rows of the cart A was extending.' );
		$this->assertSame( 2, $this->committedCart( $b, $cart->id )['version'] ?? null );
		$this->assertSame( array( $shirt . ':1', $mug . ':1' ), $this->committedLines( $b, $cart->id ) );
	}

	/**
	 * Tests that the sweep's deletion waits for a checkout write that is extending the cart, and then keeps the cart and its session.
	 *
	 * As the test above, with the checkout's session write in A's place: its compare-and-swap has
	 * extended the cart, which already has a session, and just before A writes the new details B
	 * sends the sweep's deleting statement, which waits for A's lock on the cart. A commits. B then
	 * finds the cart live, and deletes neither it nor its session.
	 *
	 * Planted violation: in MysqlCartRepository::DELETE_EXPIRED, drop
	 * `AND c.expires_at <= UTC_TIMESTAMP()`: once A commits, B's statement deletes the cart A has
	 * just extended, with the session A has just written.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_waits_for_a_checkout_write_and_keeps_the_session(): void {
		$sessions = new MysqlCheckoutSessions( $this->db );
		$cart     = $this->startCart( array( self::variant() => 1 ) );
		$b        = $this->secondConnection();
		$carts    = $this->table( CartTables::CARTS );

		$this->db->transaction( fn() => $sessions->save( $cart->id, new CheckoutDetails( null, new Address( 'GB' ), null, null ) ) );
		$this->expiresIn( $cart->id, 3600 );

		$b->query( 'SET timestamp = UNIX_TIMESTAMP() + 7200' );

		$delete = $this->raw( MysqlCartRepository::forIds( MysqlCartRepository::DELETE_EXPIRED, 1 ), $carts, $this->table( CartTables::LINES ), $this->table( MysqlCartRepository::CHECKOUT_SESSIONS ), $cart->id );
		$raced  = $this->beforeStatement(
			self::shapeOf( MysqlCheckoutSessions::SAVE ),
			function () use ( $b, $delete ): void {
				$b->queryAsync( $delete );
				$this->awaitWaiting( $b, $delete, 'statistics' );
			}
		);

		$answer = $this->service->changeWith(
			1,
			self::guest(),
			static function ( int $cartId ) use ( $sessions ): void {
				$sessions->save( $cartId, new CheckoutDetails( null, new Address( 'DE' ), null, null ) );
			}
		);

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 2, $answer['version'] );
		$this->assertSame( 0, $b->reap(), 'The sweep deleted rows of the cart A was extending.' );
		$this->assertSame( 2, $this->committedCart( $b, $cart->id )['version'] ?? null );
		$this->assertSame( 'DE', $sessions->find( $cart->id )?->details->shippingAddress?->country(), 'The sweep deleted the session A wrote.' );
	}
}
