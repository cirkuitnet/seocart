<?php
/**
 * Tests a placement against what runs beside it: a product save, a placement of the last unit, a settlement, and a retry of the same request
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read inside the placement's transaction, on its own connection.

/**
 * Each test drives a placement in this process to a statement, and runs the other side there: on a second connection, or in a process of its own. Barriers, never sleeps.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class PlacementConcurrencyTest extends PlacementTestCase {

	/**
	 * The facts read of the sale decision.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FACTS_READ = '/^SELECT v\.id AS variant_id, v\.is_enabled/';

	/**
	 * Tests that the sale decision sees a product save committed after the placement's unit of work began reading.
	 *
	 * The unit of work has read before its sale decision, so under REPEATABLE READ its snapshot
	 * would predate the save's mark: the test reads the product's state on the placement's own
	 * connection when its key is about to be claimed, and connection B then commits the mark a
	 * save sends before it writes. The facts read sees the mark, and the line is refused as
	 * updating: nothing is held, ordered or claimed, and the cart is open at its version.
	 *
	 * Planted violation: in PlaceOrder::place(), open the first unit of work without
	 * Isolation::ReadCommitted: the facts read answers from the snapshot, `complete`, and the
	 * order is placed for a product being saved.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sale_decision_sees_a_product_save_committed_after_the_unit_began_reading(): void {
		global $wpdb;

		$mug   = $this->sellable();
		$cart  = $this->readyCart( array( $mug => 1 ) );
		$input = $this->placeInput();
		$b     = $this->secondConnection();
		$read  = null;

		$this->beforeStatement(
			'/^INSERT INTO `[^`]+idempotency_keys`/',
			function () use ( $wpdb, $b, $mug, &$read ): void {
				$read = $wpdb->get_var( $wpdb->prepare( 'SELECT generation_state FROM %i WHERE id = %d', $this->table( CatalogTables::PRODUCTS ), $mug ) );

				$b->query( sprintf( "UPDATE `%s` SET generation_state = 'updating' WHERE id = %d", $this->table( CatalogTables::PRODUCTS ), $mug ) );
			}
		);

		try {
			$this->placement->place( $input, self::guest() );
			$this->fail( 'The placement sold a product being saved.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( CheckoutError::LineUnsellable, $refused->errorCode(), $refused->getMessage() );
			$this->assertSame(
				array(
					'variant_id' => $mug,
					'reason'     => 'updating',
				),
				$refused->context()
			);
		}

		$this->assertSame( 'complete', $read, 'The unit of work read the product before the save marked it.' );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 0, $this->committedCount( $b, OrderTables::ORDERS ) );
		$this->assertSame( 0, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ) );
		$this->assertSame( array( $cart->version, 'open' ), array( $this->committedCart( $b, $cart->id )['version'] ?? null, $this->committedCart( $b, $cart->id )['status'] ?? null ) );
	}

	/**
	 * Tests that two placements of the last unit leave one order: the second waits for the first's claim of the item, then finds it gone, and rolls back whole.
	 *
	 * Planted violations:
	 * - in MysqlStockRepository::CLAIM, drop `AND ( on_hand - allocated - held ) >= %d`: the
	 *   second claim goes through after the wait, and two orders share one unit;
	 * - in PlaceOrder::placeInside(), catch `stock.insufficient` around the hold and go on: the
	 *   second placement orders without a hold.
	 *
	 * @since 0.1.0
	 */
	public function test_two_placements_of_the_last_unit_leave_one_order(): void {
		$mug      = $this->sellable( 1 );
		$other    = $this->readyCart( array( $mug => 1 ) );
		$theirs   = $this->placeInput( 'theirs' );
		$token    = $this->tokens->presented;
		$cart     = $this->readyCart( array( $mug => 1 ) );
		$input    = $this->placeInput();
		$second   = null;
		$prefix   = sprintf( 'UPDATE `%s` SET held = held + ', $this->table( InventoryTables::ITEMS ) );
		$barriers = $this->beforeStatement(
			'/^INSERT INTO `[^`]+stock_holds`/',
			function () use ( $token, $theirs, $prefix, &$second ): void {
				$this->assertNotNull( $token );

				$second = $this->startPlacement( $token, $theirs );

				$this->awaitProbeSending( $second, $prefix, 'updating' );
			}
		);

		$answer = $this->placement->place( $input, self::guest() );
		$report = $second?->finish() ?? array();
		$b      = $this->secondConnection();

		$this->assertTrue( $barriers->fired );
		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertSame( InventoryError::Insufficient->value, $report['refused'] ?? $report, 'The second placement found the unit gone.' );
		$this->assertSame( 1, $this->committedCount( $b, OrderTables::ORDERS ), 'One order.' );
		$this->assertSame( array( 1, 1, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( 0, $this->committedCount( $b, InventoryTables::HOLDS ) );
		$this->assertSame( 1, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ), 'The second placement\'s key went with its rollback.' );
		$this->assertSame( array( $other->version, 'open' ), array( $this->committedCart( $b, $other->id )['version'] ?? null, $this->committedCart( $b, $other->id )['status'] ?? null ), 'The second cart is as it was.' );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
		$this->assertSame( array(), $this->sleeps, 'No deadlock.' );
	}

	/**
	 * Tests that a placement and the settlement of another order take the items they share in the same order, so the settlement waits and neither deadlocks.
	 *
	 * Order X holds items 1 and 2, waiting for its shopper. Cart Y's placement claims item 1 and
	 * is about to claim item 2 when X's approval is settled in a process of its own: the
	 * settlement waits for item 1, which the placement holds, while the placement takes item 2,
	 * which the settlement has not touched. Both end; neither retries.
	 *
	 * Planted violation: in StockService::allocateInside(), convert the items in descending
	 * order (`krsort`): the settlement converts item 2 first and waits for item 1, the placement
	 * then waits for item 2, InnoDB breaks the cycle, and one of the two retries.
	 *
	 * @since 0.1.0
	 */
	public function test_a_placement_and_a_settlement_take_shared_items_in_the_same_order(): void {
		$mug     = $this->sellable( 5, 1000, 'Mug' );
		$tee     = $this->sellable( 5, 2500, 'Tee' );
		$waiting = $this->readyCart(
			array(
				$mug => 1,
				$tee => 1,
			)
		);
		$x       = $this->placement->place( $this->placeInput( 'x', StubGateway::REQUIRES_ACTION ), self::guest() );

		$this->assertSame( 'requires_action', $x['outcome'] );

		$this->readyCart(
			array(
				$mug => 1,
				$tee => 1,
			)
		);

		$input      = $this->placeInput( 'y' );
		$settlement = null;
		$prefix     = sprintf( 'UPDATE `%s` SET held = held - ', $this->table( InventoryTables::ITEMS ) );

		$this->beforeStatement(
			'/^UPDATE `[^`]+stock_items` SET held = held \+ /',
			function () use ( $x, $prefix, &$settlement ): void {
				$settlement = $this->startSettlement( $x['order_uuid'] );

				$this->awaitProbeSending( $settlement, $prefix, 'updating' );
			},
			2
		);

		$answer = $this->placement->place( $input, self::guest() );
		$report = $settlement?->finish() ?? array();
		$b      = $this->secondConnection();

		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertSame(
			array(
				'outcome' => 'approved',
				'retries' => 0,
			),
			$report,
			'The settlement waited, and never retried.'
		);
		$this->assertSame( array(), $this->sleeps, 'The placement never retried.' );
		$this->assertSame( array( 5, 2, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( array( 5, 2, 0 ), $this->committedStock( $b, $tee ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $waiting->id )['status'] ?? null );
	}

	/**
	 * Tests that the same request sent while the first is in flight waits for it, and then answers what the first placed, writing nothing.
	 *
	 * The second request presents the same cart and key; it waits on the cart, which the first
	 * claimed. Once the first commits, the cart has moved on, and the second answers with the
	 * placement the key keeps: the same order and order key, pending or approved by the time it
	 * reads it, as the first request's settlement runs beside it.
	 *
	 * Planted violation: in PlaceOrder::answerRefusedClaim(), skip the key's replay: the second
	 * request is then refused with `cart.not_open`, though its own request placed the order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_submit_racing_an_in_flight_one_answers_what_it_placed(): void {
		$mug    = $this->sellable();
		$cart   = $this->readyCart( array( $mug => 1 ) );
		$input  = $this->placeInput();
		$token  = $this->tokens->presented;
		$second = null;
		$prefix = sprintf( "UPDATE `%s` SET version = version + 1, status = 'placing'", $this->table( CartTables::CARTS ) );

		$this->beforeStatement(
			self::FACTS_READ,
			function () use ( $token, $input, $prefix, &$second ): void {
				$this->assertNotNull( $token );

				$second = $this->startPlacement( $token, $input );

				$this->awaitProbeSending( $second, $prefix, 'updating' );
			}
		);

		$answer   = $this->placement->place( $input, self::guest() );
		$report   = $second?->finish() ?? array();
		$b        = $this->secondConnection();
		$replayed = json_decode( (string) ( $report['answer_json'] ?? '' ), true );

		$this->assertSame( 'approved', $answer['outcome'] );
		$this->assertIsArray( $replayed, (string) wp_json_encode( $report ) );
		$this->assertSame( array( $answer['order_uuid'], $answer['order_key'] ), array( $replayed['order_uuid'] ?? null, $replayed['order_key'] ?? null ), 'The second request answers with the first one\'s order.' );
		$this->assertContains( $replayed['outcome'] ?? null, array( 'pending', 'approved' ) );
		$this->assertSame( 1, $this->committedCount( $b, OrderTables::ORDERS ) );
		$this->assertSame( 1, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS ) );
		$this->assertSame( 1, $this->committedCount( $b, InventoryTables::ALLOCATIONS ) );
		$this->assertSame( $cart->version + 1, $this->committedCart( $b, $cart->id )['version'] ?? null, 'The cart moved on once.' );
	}

	/**
	 * Tests that the same request sent while the first is in flight places the order itself when the first rolls back.
	 *
	 * The first placement loses its unit at its claim of the item and rolls back whole: its claim
	 * of the cart and of the key go with it. The second, which waited on the cart, then claims
	 * both, and places.
	 *
	 * Planted violation: in PlaceOrder::place(), claim the key in a transaction of its own before
	 * the unit of work: the first's claim outlives its rollback, and the second is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_submit_racing_one_that_rolls_back_places_the_order(): void {
		$mug    = $this->sellable();
		$cart   = $this->readyCart( array( $mug => 1 ) );
		$input  = $this->placeInput();
		$token  = $this->tokens->presented;
		$second = null;
		$prefix = sprintf( "UPDATE `%s` SET version = version + 1, status = 'placing'", $this->table( CartTables::CARTS ) );

		$this->beforeStatement(
			self::FACTS_READ,
			function () use ( $token, $input, $prefix, &$second ): void {
				$this->assertNotNull( $token );

				$second = $this->startPlacement( $token, $input );

				$this->awaitProbeSending( $second, $prefix, 'updating' );
			}
		);
		$this->beforeStatement(
			'/^UPDATE `[^`]+stock_items` SET held = held \+ /',
			static function () use ( $mug ): void {
				CodedException::raise(
					InventoryError::Insufficient,
					array(
						'variant_id' => $mug,
						'requested'  => 1,
						'available'  => 0,
					)
				);
			}
		);

		try {
			$this->placement->place( $input, self::guest() );
			$this->fail( 'The first placement did not roll back.' );
		} catch ( CodedException $lost ) {
			$this->assertSame( InventoryError::Insufficient, $lost->errorCode() );
		}

		$report = $second?->finish() ?? array();
		$b      = $this->secondConnection();
		$answer = json_decode( (string) ( $report['answer_json'] ?? '' ), true );

		$this->assertSame( 'approved', $answer['outcome'] ?? $report, 'The second request placed the order.' );
		$this->assertSame( 1, $this->committedCount( $b, OrderTables::ORDERS ) );
		$this->assertSame( 1, $this->committedCount( $b, CheckoutTables::IDEMPOTENCY_KEYS, "state = 'placed'" ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $b, $mug ) );
		$this->assertSame( 'converted', $this->committedCart( $b, $cart->id )['status'] ?? null );
	}
}
