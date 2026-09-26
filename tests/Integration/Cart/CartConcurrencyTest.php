<?php
/**
 * Tests two writes to one cart based on the same version, on two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\Doubles\FakeCartTokens;

/**
 * Of two writes based on the same version of a cart, one wins and the other is told the cart is stale.
 *
 * Connection A is the cart service over wpdb. Connection B is another request for the same cart:
 * it sends the repository's own compare-and-swap, built from its constant, and then runs the
 * whole service over a second connection. B's side runs at the moment A, past its own
 * compare-and-swap, is about to add its lines, and B is shown waiting by the server's process
 * list; nothing waits on the clock.
 *
 * Planted violation, named on the test.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class CartConcurrencyTest extends CartTestCase {

	/**
	 * Tests that a stale write loses to the write it raced: B waits for A's lock, then changes nothing, and is told the version A left.
	 *
	 * A and B both read the cart at version 1 and both write. A's compare-and-swap takes the cart
	 * row. Just before A adds its lines, B sends the same compare-and-swap for version 1; the
	 * server shows it waiting for A's lock. A commits version 2. B's statement then finds the
	 * cart at version 2 and changes nothing. B then runs the whole service, and is refused with
	 * `cart.version_stale` naming version 2 and carrying the totals of A's cart. The cart changed
	 * exactly once: version 2, with A's line added and B's not.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, replace `version = %d` with
	 * `%d > 0`: B's compare-and-swap then goes through once A commits, both writes win, and the
	 * cart reaches version 3 with B's line in it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_write_loses_to_the_write_it_raced(): void {
		$shirt = self::variant();
		$mug   = self::variant();
		$hat   = self::variant();
		$this->price(
			array(
				$shirt => 1000,
				$mug   => 500,
				$hat   => 700,
			)
		);

		$cart = $this->startCart( array( $shirt => 1 ) );
		$b    = $this->secondConnection();
		$swap = $this->raw( MysqlCartRepository::COMPARE_AND_SWAP, $this->table( CartTables::CARTS ), self::TTL_SECONDS, $cart->id, 1 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlCartRepository::forRows( 1 ) ),
			function () use ( $b, $swap ): void {
				$b->query( 'START TRANSACTION' );
				$b->queryAsync( $swap );
				$this->awaitWaiting( $b, $swap, 'updating' );
			}
		);

		$won = $this->service->add( self::lines( array( $mug => 1 ) ), 1, self::guest() );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 2, $won->version );
		$this->assertSame( 0, $b->reap(), 'B\'s compare-and-swap for version 1 changed the cart A had moved to version 2.' );

		$b->query( 'ROLLBACK' );

		$tokens            = new FakeCartTokens();
		$tokens->presented = $this->tokens->presented;

		try {
			$this->secondService( $tokens )->add( self::lines( array( $hat => 1 ) ), 1, self::guest() );
			$this->fail( 'A write based on version 1 was applied after the cart reached version 2.' );
		} catch ( CodedException $stale ) {
			$this->assertSame( CartError::VersionStale, $stale->errorCode() );
			$this->assertSame( array( 'current_version' => 2 ), $stale->context() );
			$this->assertSame( array( $shirt, $mug ), array_column( $stale->details()['totals']['lines'] ?? array(), 'variant_id' ), 'The refusal carries the totals of the cart the winner left.' );
			$this->assertSame( 1800, $stale->details()['totals']['summary']['grand_minor'] ?? null, 'A shirt at 10.00 and a mug at 5.00, with 20 % tax.' );
		}

		$this->assertSame( 2, $this->committedCart( $b, $cart->id )['version'] ?? null, 'The cart changed more than once.' );
		$this->assertSame( array( $shirt . ':1', $mug . ':1' ), $this->committedLines( $b, $cart->id ) );
	}
}
