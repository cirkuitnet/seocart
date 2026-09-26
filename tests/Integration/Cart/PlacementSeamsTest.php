<?php
/**
 * Tests the three steps order placement takes on a cart, inside its own transaction
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\CartTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * The claim, the order's binding and the settlement each run only inside the caller's
 * transaction and send exactly one conditional statement; the claim refuses as every cart write
 * does; and while a cart is claimed, the shopper's writes are refused until the settlement opens
 * it again.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class PlacementSeamsTest extends CartTestCase {

	/**
	 * Tests that each step refuses to run outside a transaction, before it sends anything.
	 *
	 * The repository refuses a write outside a transaction too; the service refuses first, so a
	 * step that reads before it writes reads nothing either.
	 *
	 * Planted violation: remove the requireCallersTransaction() call from CartService::settle():
	 * the refusal then comes from the repository, not the service. Remove the repository's guard
	 * of settle() as well: the settlement then runs on its own, and this test counts its statement.
	 *
	 * @since 0.1.0
	 */
	public function test_each_step_refuses_to_run_outside_a_transaction(): void {
		$cart  = $this->startCart( array( self::variant() => 1 ) );
		$steps = array(
			'claimForPlacement' => fn() => $this->service->claimForPlacement( $cart->id, 1 ),
			'bindOrder'         => fn() => $this->service->bindOrder( $cart->id, 7 ),
			'settle'            => fn() => $this->service->settle( $cart->id, 7, true ),
		);

		foreach ( $steps as $step => $run ) {
			$log = $this->captureQueries(
				function () use ( $run, $step ): void {
					try {
						$run();
						$this->fail( sprintf( '%s() ran outside a transaction.', $step ) );
					} catch ( \LogicException $refused ) {
						$this->assertStringContainsString( 'CartService::' . $step . '()', $refused->getMessage(), 'The service did not refuse the step itself.' );
					}
				}
			);

			$this->assertQueryCount( 0, $log, $step . '() outside a transaction' );
		}

		$this->assertSame( 1, $this->service->current()?->version );
	}

	/**
	 * Tests that the claim moves an open cart to placing and to its next version in one conditional statement.
	 *
	 * @since 0.1.0
	 */
	public function test_the_claim_moves_an_open_cart_to_placing_in_one_statement(): void {
		$cart = $this->startCart( array( self::variant() => 1 ) );
		$b    = $this->secondConnection();

		$this->db->transaction(
			function () use ( $cart ): void {
				$log = $this->captureQueries(
					function () use ( $cart ): void {
						$this->assertSame( 2, $this->service->claimForPlacement( $cart->id, 1 ) );
					}
				);

				$this->assertQueryCount( 1, $log->forTable( $this->table( CartTables::CARTS ) ), 'The claim' );
				$this->assertQueryCount( 1, $log, 'The claim' );
			}
		);

		$this->assertSame( array( 2, 'placing', null ), $this->state( $b, $cart->id ) );
	}

	/**
	 * Tests that the claim refuses as every write does: a stale version, a cart already claimed, and a cart that has expired.
	 *
	 * Planted violation: in MysqlCartRepository::OPEN_AT_VERSION, drop
	 * `AND expires_at > UTC_TIMESTAMP()`: the claim then takes the expired cart, which the claim
	 * reaches by id with no read before it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_claim_refuses_a_stale_version_a_claimed_cart_and_an_expired_cart(): void {
		$stale   = $this->startCart( array( self::variant() => 1 ) );
		$claimed = $this->startCart( array( self::variant() => 1 ) );
		$expired = $this->startCart( array( self::variant() => 1 ) );

		$this->plantStatus( $claimed->id, CartStatus::Placing, 5 );
		$this->expire( $expired->id );

		$cases = array(
			'stale'   => array( $stale->id, 2, CartError::VersionStale, array( 'current_version' => 1 ) ),
			'claimed' => array( $claimed->id, 1, CartError::NotOpen, array( 'status' => 'placing' ) ),
			'expired' => array( $expired->id, 1, CartError::NotFound, array() ),
			'missing' => array( $expired->id + 1000, 1, CartError::NotFound, array() ),
		);

		foreach ( $cases as $case => list( $cartId, $version, $code, $context ) ) {
			try {
				$this->db->transaction( fn() => $this->service->claimForPlacement( $cartId, $version ) );
				$this->fail( sprintf( 'The claim of a %s cart went through.', $case ) );
			} catch ( CodedException $refused ) {
				$this->assertSame( $code, $refused->errorCode(), $case );
				$this->assertSame( $context, $refused->context(), $case );
			}
		}

		$b = $this->secondConnection();

		$this->assertSame( array( 1, 'open', null ), $this->state( $b, $stale->id ) );
		$this->assertSame( array( 1, 'placing', 5 ), $this->state( $b, $claimed->id ) );
		$this->assertSame( array( 1, 'open', null ), $this->state( $b, $expired->id ) );
	}

	/**
	 * Tests that binding an order to a placing cart is one conditional statement, and is refused for a cart that is not placing.
	 *
	 * Planted violation: in MysqlCartRepository::BIND_ORDER, drop `AND status = 'placing'`: an
	 * open cart is then given an order.
	 *
	 * @since 0.1.0
	 */
	public function test_binding_an_order_takes_one_statement_and_only_a_placing_cart(): void {
		$cart = $this->startCart( array( self::variant() => 1 ) );
		$open = $this->startCart( array( self::variant() => 1 ) );
		$b    = $this->secondConnection();

		$this->db->transaction(
			function () use ( $cart ): void {
				$this->service->claimForPlacement( $cart->id, 1 );

				$this->assertQueryCount( 1, $this->captureQueries( fn() => $this->service->bindOrder( $cart->id, 41 ) ), 'The binding' );
			}
		);

		$this->assertSame( array( 2, 'placing', 41 ), $this->state( $b, $cart->id ) );

		try {
			$this->db->transaction( fn() => $this->service->bindOrder( $open->id, 42 ) );
			$this->fail( 'An order was bound to an open cart.' );
		} catch ( \LogicException $refused ) {
			$this->assertStringContainsString( 'is not placing', $refused->getMessage() );
		}

		$this->assertSame( array( 1, 'open', null ), $this->state( $b, $open->id ) );
	}

	/**
	 * Tests that the settlement converts the cart, or opens it again, in one conditional statement, and only for the order it is placing.
	 *
	 * Planted violation: in MysqlCartRepository::SETTLE, drop `AND order_id = %d` (and its value):
	 * a settlement of another order then settles the cart.
	 *
	 * @since 0.1.0
	 */
	public function test_the_settlement_converts_or_reopens_only_for_the_order_being_placed(): void {
		$accepted = $this->placing( 51 );
		$declined = $this->placing( 52 );
		$b        = $this->secondConnection();

		$this->db->transaction(
			function () use ( $accepted, $declined ): void {
				$this->assertFalse( $this->service->settle( $accepted, 99, true ), 'Another order settled the cart.' );
				$this->assertQueryCount( 1, $this->captureQueries( fn() => $this->assertTrue( $this->service->settle( $accepted, 51, true ) ) ), 'The settlement' );
				$this->assertTrue( $this->service->settle( $declined, 52, false ) );
				$this->assertFalse( $this->service->settle( $declined, 52, false ), 'A cart that is no longer placing was settled again.' );
			}
		);

		$this->assertSame( array( 2, 'converted', 51 ), $this->state( $b, $accepted ) );
		$this->assertSame( array( 2, 'open', 52 ), $this->state( $b, $declined ), 'A declined cart is open again, still naming the order.' );
	}

	/**
	 * Tests that a claimed cart refuses the shopper's writes until the settlement opens it again, and then takes them at its new version.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claimed_cart_refuses_writes_until_it_is_opened_again(): void {
		$shirt = self::variant();
		$cart  = $this->startCart( array( $shirt => 1 ) );

		$this->db->transaction(
			function () use ( $cart ): void {
				$this->service->claimForPlacement( $cart->id, 1 );
				$this->service->bindOrder( $cart->id, 61 );
			}
		);

		try {
			$this->service->add( self::lines( array( $shirt => 1 ) ), 2, self::guest() );
			$this->fail( 'A placing cart took a line.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CartError::NotOpen, $refused->errorCode() );
		}

		$this->db->transaction( fn() => $this->service->settle( $cart->id, 61, false ) );

		$after = $this->service->add( self::lines( array( $shirt => 1 ) ), 2, self::guest() );

		$this->assertSame( 3, $after->version );
		$this->assertSame( array( $shirt . ':2' ), self::summary( $after ) );
	}

	/**
	 * Starts a cart, and claims it for an order, as a placement's first transaction would.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return int The cart's id.
	 */
	private function placing( int $orderId ): int {
		$cart = $this->startCart( array( self::variant() => 1 ) );

		$this->db->transaction(
			function () use ( $cart, $orderId ): void {
				$this->service->claimForPlacement( $cart->id, 1 );
				$this->service->bindOrder( $cart->id, $orderId );
			}
		);

		return $cart->id;
	}

	/**
	 * Reads a cart's committed version, status and order.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b      Connection B.
	 * @param int              $cartId The cart.
	 * @return array{0: int, 1: string, 2: int|null} The three.
	 */
	private function state( SecondConnection $b, int $cartId ): array {
		$row = $this->committedCart( $b, $cartId );

		$this->assertNotNull( $row );

		return array( $row['version'], $row['status'], $row['order_id'] );
	}
}
