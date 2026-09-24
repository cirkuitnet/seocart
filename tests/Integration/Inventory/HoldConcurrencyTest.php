<?php
/**
 * Tests holding stock against concurrent holders, reclaimers, the sweep and adjustments, on two real connections
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Inventory\StockTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One test sets the connection's isolation level, and restores it.

/**
 * Parallel holders never oversell, and every path on one item waits for the item's lock.
 *
 * Connection A is the stock service over wpdb. Connection B is another request: it sends the
 * repository's own statements, built from its constants, or runs the service over a second
 * connection. B's side runs at the moment A is about to send a given statement, and B is shown
 * waiting by the server's process list; nothing waits on the clock.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class HoldConcurrencyTest extends StockTestCase {

	/**
	 * Tests that two holders of the last unit leave exactly one hold: the second is refused.
	 *
	 * A holds the unit. Just before A's hold row is written, B sends the same claim; the server
	 * shows it waiting for A's lock on the item. Once A commits, B's claim finds nothing
	 * available and changes nothing. Then B runs the whole service and is told
	 * `stock.insufficient`.
	 *
	 * Planted violation: in MysqlStockRepository::CLAIM, replace
	 * `( on_hand - allocated - held ) >= %d` with `%d > 0`: B's claim then takes a unit that is not
	 * there, and `held` becomes 2 on one unit.
	 *
	 * @since 0.1.0
	 */
	public function test_two_holders_of_the_last_unit_leave_exactly_one_hold(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$claim   = $this->raw( MysqlStockRepository::CLAIM, 1, $variant, 1 );

		$this->stockItem( $variant, 1 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::INSERT_HOLD ),
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'updating' );
			}
		);

		$hold = $this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( 0, $b->reap(), 'B claimed the unit A had just held.' );

		try {
			$this->secondService()->hold( array( new HoldLine( $variant, 1 ) ), 600 );
			$this->fail( 'A second holder was given the last unit.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
			$this->assertSame(
				array(
					'variant_id' => $variant,
					'requested'  => 1,
					'available'  => 0,
				),
				$refused->context()
			);
		}

		$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $variant ) );
		$this->assertCount( 1, $this->afterCommit, 'One reservation, the winner\'s.' );
		$this->assertInstanceOf( StockReserved::class, $this->afterCommit[0] );
		$this->assertSame( 0, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
	}

	/**
	 * Tests that a reclaimer waits for a holder that is reclaiming the same item, and then finds nothing left to claim.
	 *
	 * The item's only unit is held by an expired hold. A's claim is refused, so A reclaims. Just
	 * before A claims the expired rows, B sends the reclaim's first statement, the item lock; the
	 * server shows it waiting. A reclaims, claims again, holds and commits. B's lock then goes
	 * through, and B's claim of expired rows finds none. Both connections run at READ COMMITTED,
	 * where a refused claim keeps no lock on the item: the reclaim must take it explicitly.
	 *
	 * Planted violation: in MysqlStockRepository::reclaim(), remove the lockItem() call. Under READ
	 * COMMITTED, B's item lock is then not blocked while A claims and gives back the item's holds.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reclaimer_waits_for_a_holder_that_is_reclaiming(): void {
		global $wpdb;

		$variant = self::variant();
		$b       = $this->secondConnection();
		$lock    = $this->raw( MysqlStockRepository::LOCK_ITEM, $variant );

		$this->stockItem( $variant, 1 );
		$this->plantHold( $variant, 1, -60 );

		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );
		$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );

		try {
			$raced = $this->beforeStatement(
				self::shapeOf( MysqlStockRepository::CLAIM_EXPIRED ),
				function () use ( $b, $lock ): void {
					$b->queryAsync( $lock );
					$this->awaitWaiting( $b, $lock, 'updating' );
				}
			);

			$hold = $this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

			$this->assertTrue( $raced->fired );
			$this->assertSame( 1, $b->reap() );

			$b->query( $this->raw( MysqlStockRepository::CLAIM_EXPIRED, SequentialIdGenerator::nth( 800001 ), $variant ) );

			$this->assertSame( 0, $b->affectedRows(), 'The expired row was already reclaimed.' );
		} finally {
			$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		}

		$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $variant ) );
		$this->assertSame( 1, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
	}

	/**
	 * Tests that of two reclaims of the same expired rows, the second claims nothing once the first commits.
	 *
	 * Planted violation: in MysqlStockRepository::CLAIM_EXPIRED, drop `AND reclaim_token IS NULL`:
	 * B2's claim then overwrites B1's token, and the rows are claimed twice.
	 *
	 * @since 0.1.0
	 */
	public function test_two_reclaimers_claim_each_expired_row_once(): void {
		$variant = self::variant();
		$first   = $this->secondConnection();
		$second  = $this->secondConnection();
		$token   = SequentialIdGenerator::nth( 800001 );
		$other   = $this->raw( MysqlStockRepository::CLAIM_EXPIRED, SequentialIdGenerator::nth( 800002 ), $variant );

		$this->stockItem( $variant, 1 );
		$this->plantHold( $variant, 1, -60 );

		$first->query( 'START TRANSACTION' );
		$first->query( $this->raw( MysqlStockRepository::CLAIM_EXPIRED, $token, $variant ) );

		$this->assertSame( 1, $first->affectedRows() );

		$second->queryAsync( $other );
		$this->awaitWaiting( $second, $other, 'updating' );

		$first->query( 'COMMIT' );

		$this->assertSame( 0, $second->reap(), 'The second reclaim claimed a row the first had claimed.' );

		$first->query( $this->raw( MysqlStockRepository::GIVE_BACK, $token, $variant, $token ) );

		$this->assertSame( 1, $first->affectedRows() );

		$first->query( $this->raw( MysqlStockRepository::DELETE_CLAIMED, $token ) );

		$this->assertSame( 0, $this->committedItem( $first, $variant )['held'] ?? null );
		$this->assertSame( array(), $this->committedHolds( $first, $variant ) );
	}

	/**
	 * Tests that a holder waits for the sweep that is reclaiming the item, and then gets the unit it gave back.
	 *
	 * Just before the sweep gives the expired hold's unit back, B sends a claim of it; the server
	 * shows B waiting for the item's lock. When the sweep's transaction commits, B's claim takes
	 * the unit.
	 *
	 * Planted violation: in MysqlStockRepository::reclaim(), remove the lockItem() call. The sweep
	 * then claims the hold rows without locking the item, and B's claim is not blocked: it finds the
	 * unit still held and is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_holder_waits_for_the_sweep_and_gets_the_unit_back(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$claim   = $this->raw( MysqlStockRepository::CLAIM, 1, $variant, 1 );

		$this->stockItem( $variant, 1 );
		$this->plantHold( $variant, 1, -60 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::GIVE_BACK ),
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'updating' );
			}
		);

		( new SweepHolds( $this->service ) )->handle( array() );

		$this->assertTrue( $raced->fired );
		$this->assertSame( 1, $b->reap(), 'B did not get the unit the sweep gave back.' );

		$group = SequentialIdGenerator::nth( 800003 );

		$b->query( $this->raw( MysqlStockRepository::INSERT_HOLD, $variant, 0, 0, $group, 1, '2099-01-01 00:00:00' ) );

		$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array( $group . ':1' ), $this->committedHolds( $b, $variant ) );
		$this->assertTrue( $this->projectionCheck()->passed );
	}

	/**
	 * Tests that a holder waits for an adjustment that removes the last unit, and is then refused.
	 *
	 * Planted violation: the one of the two-holders test; B's claim then takes a unit the
	 * adjustment removed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_holder_waits_for_an_adjustment_that_removes_the_last_unit(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$claim   = $this->raw( MysqlStockRepository::CLAIM, 1, $variant, 1 );

		$this->stockItem( $variant, 1 );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::APPEND_LEDGER ),
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );
				$this->awaitWaiting( $b, $claim, 'updating' );
			}
		);

		$this->service->adjust( $variant, -1, LedgerReason::Damaged, Actor::user( 1 ) );

		$this->assertTrue( $raced->fired );
		$this->assertSame( 0, $b->reap(), 'B held a unit the adjustment had removed.' );
		$this->assertSame(
			array(
				'on_hand'   => 0,
				'allocated' => 0,
				'held'      => 0,
			),
			$this->committedItem( $b, $variant )
		);
	}

	/**
	 * Tests that an adjustment waits for a hold of the unit it removes, and then applies: the shortfall is a fact, not corruption.
	 *
	 * B adjusts with the repository's statements, in its own transaction: the update waits for
	 * the hold, applies once the hold commits, and B appends its ledger entry. on_hand is then 0
	 * with one unit held, available is −1, and every projection still agrees with its rows.
	 *
	 * @since 0.1.0
	 */
	public function test_an_adjustment_waits_for_a_hold_and_may_leave_a_shortfall(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$adjust  = $this->raw( MysqlStockRepository::ADJUST, -1, $variant, -1 );

		$this->stockItem( $variant, 1 );

		$b->query( 'START TRANSACTION' );

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::INSERT_HOLD ),
			function () use ( $b, $adjust ): void {
				$b->queryAsync( $adjust );
				$this->awaitWaiting( $b, $adjust, 'updating' );
			}
		);

		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$this->assertTrue( $raced->fired );
		$this->assertSame( 1, $b->reap(), 'The adjustment records the count although a unit is held.' );

		$b->query( $this->raw( MysqlStockRepository::APPEND_LEDGER, $variant, -1, 0, 'recount', 'user', 1, '' ) );
		$b->query( 'COMMIT' );

		$this->assertSame(
			array(
				'on_hand'   => 0,
				'allocated' => 0,
				'held'      => 1,
			),
			$this->committedItem( $b, $variant )
		);
		$this->assertSame( -1, $this->service->levels( array( $variant ) )[ $variant ]->available() );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that two holds of the same two items given in opposite orders never deadlock: lines are held in ascending order.
	 *
	 * A holds the higher and the lower item, given in that order. Just before A's second claim,
	 * B, heavier in its own transaction, claims the lower item. With ascending order A already
	 * holds it, so B waits; A finishes, and B then holds both. No pause, no deadlock.
	 *
	 * Planted violation: in StockService::normalise(), sort the lines descending (krsort). A then
	 * holds only the higher item when B arrives: B takes the lower one and waits for the higher,
	 * A's claim of the lower one closes the cycle, and InnoDB rolls A back; the sleeper runs, which
	 * this test forbids.
	 *
	 * @since 0.1.0
	 */
	public function test_opposite_order_holds_of_two_items_never_deadlock(): void {
		$lower  = self::variant();
		$higher = self::variant();
		$b      = $this->secondConnection();
		$first  = $this->raw( MysqlStockRepository::CLAIM, 1, $lower, 1 );
		$then   = $this->raw( MysqlStockRepository::CLAIM, 1, $higher, 1 );

		$this->stockItem( $lower, 2 );
		$this->stockItem( $higher, 2 );

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->insertRow( $id, 'seed' );
		}

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET value = 'b' WHERE id IN (1, 2, 3)", $this->rowsTable() ) );

		$waited = null;

		$this->beforeStatement(
			self::shapeOf( MysqlStockRepository::CLAIM ),
			function () use ( $b, $first, $then, &$waited ): void {
				$b->queryAsync( $first );

				$waited = $this->waitsOrAnswers( $b, $first, 'updating' );

				if ( ! $waited ) {
					// A did not hold the lower item: B holds it now, and asks for the higher one, which A holds.
					$this->assertSame( 1, $b->reap() );

					$b->queryAsync( $then );
					$this->awaitWaiting( $b, $then, 'updating' );
				}
			},
			2
		);

		$this->onSleep = function () use ( $b ): void {
			if ( $b->isReady( 5000 ) ) {
				$b->reap();
			}

			$b->query( 'COMMIT' );
		};

		$this->service->hold( array( new HoldLine( $higher, 1 ), new HoldLine( $lower, 1 ) ), 600 );

		$this->assertSame( array(), $this->sleeps, 'A deadlock happened where the lock order forbids one.' );
		$this->assertTrue( $waited, 'B was not blocked on the lower item, so A did not take it first.' );
		$this->assertSame( 1, $b->reap(), 'B saw no deadlock, and held the lower item once A committed.' );

		$b->query( $then );

		$this->assertSame( 1, $b->affectedRows() );

		$b->query( 'COMMIT' );

		$this->assertSame( 2, $this->committedItem( $b, $lower )['held'] ?? null );
		$this->assertSame( 2, $this->committedItem( $b, $higher )['held'] ?? null );
	}

	/**
	 * Tests that a deadlocked hold is rolled back and run again whole, once: one row per item and one reservation.
	 *
	 * Planted violation: in StockService::hold(), pass RetryPolicy::none(): the deadlock then
	 * reaches the caller.
	 *
	 * @since 0.1.0
	 */
	public function test_a_deadlocked_hold_runs_again_whole_and_once(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 2 );
		$this->stockItem( $second, 2 );

		$b    = $this->deadlockBeforeClaimOf( $first, $second );
		$hold = $this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 1 ) ), 600 );

		$this->assertSame( array( 50 ), $this->sleeps, 'InnoDB chose B as the deadlock victim: victim selection changed; raise B\'s weight.' );
		$this->assertSame( array( array( 0, 50 ) ), $this->randomRanges, 'The pause after the deadlock is drawn from [0, 50] ms.' );

		foreach ( array( $first, $second ) as $variant ) {
			$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $variant ), 'One row per item, from the attempt that committed.' );
			$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null );
		}

		$this->assertCount( 1, $this->afterCommit, 'One reservation, not one per attempt.' );
		$this->assertSame( $hold->holdGroup, $this->afterCommit[0]->holdId ?? null );
	}

	/**
	 * Tests that a two-line hold that reclaimed its first item, deadlocked with a holder of its second, is run again whole and loses and doubles nothing.
	 *
	 * The item lock serialises everything on one item, but not the gaps InnoDB locks on the hold
	 * table under REPEATABLE READ. A reclaims the first item's expired hold, which locks the gap its
	 * claim scanned up to the end of the hold index; A holds the first item and is about to claim
	 * the second. B, heavier in its own transaction, claims the second item, which A has not
	 * locked, and inserts its hold row, which falls into A's gap: the server shows B waiting for A.
	 * A's claim of the second item then waits for B: a deadlock across two items. InnoDB rolls back
	 * the lighter transaction, A, and the sleeper is the barrier that lets B commit. A's second
	 * attempt reclaims the first item again and holds both. Afterwards each item's `held` equals its
	 * hold rows, the expired hold was reported once, and the hold was reserved once.
	 *
	 * @since 0.1.0
	 */
	public function test_a_two_line_hold_after_a_reclaim_and_a_holder_of_its_second_item_lose_nothing(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 1 );
		$this->stockItem( $second, 2 );
		$this->plantHold( $first, 1, -60 );

		foreach ( range( 1, 10 ) as $id ) {
			$this->insertRow( $id, 'seed' );
		}

		$b      = $this->secondConnection();
		$group  = SequentialIdGenerator::nth( 800010 );
		$claim  = $this->raw( MysqlStockRepository::CLAIM, 1, $second, 1 );
		$insert = $this->raw( MysqlStockRepository::INSERT_HOLD, $second, 0, 0, $group, 1, '2099-01-01 00:00:00' );

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET value = 'b' WHERE id <= 10", $this->rowsTable() ) );

		$raced = $this->beforeStatement(
			'/^' . preg_quote( $claim, '/' ) . '$/',
			function () use ( $b, $claim, $insert ): void {
				$b->query( $claim );

				$this->assertSame( 1, $b->affectedRows(), 'B could not claim the second item, which A had not locked.' );

				$b->queryAsync( $insert );
				$this->awaitWaiting( $b, $insert, 'update' );
			}
		);

		$this->onSleep = function () use ( $b ): void {
			$this->assertTrue( $b->isReady( 5000 ), 'B\'s hold row must go in once A is rolled back.' );
			$this->assertSame( 1, $b->reap() );

			$b->query( 'COMMIT' );
		};

		$hold = $this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 1 ) ), 600 );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertSame( array( 50 ), $this->sleeps, 'InnoDB chose B as the deadlock victim: victim selection changed; raise B\'s weight.' );
		$this->assertSame( 1, $this->committedItem( $b, $first )['held'] ?? null );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $first ) );
		$this->assertSame( 2, $this->committedItem( $b, $second )['held'] ?? null );
		$this->assertSame( array( $group . ':1', $hold->holdGroup . ':1' ), $this->committedHolds( $b, $second ) );
		$this->assertSame( 1, $this->committedEvents( $b, StockHoldExpired::eventName() ), 'The expired hold is reported once, by the attempt that committed.' );
		$this->assertCount( 1, $this->afterCommit, 'One reservation, not one per attempt.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}
}
