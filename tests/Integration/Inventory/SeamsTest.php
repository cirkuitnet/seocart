<?php
/**
 * Tests the calls the catalog makes into inventory: creating items, deleting variants and releasing their holds
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant allocations and fixture rows directly.

/**
 * The calls the catalog makes, createItems(), deleteVariants() and releaseVariants(), each inside the caller's transaction.
 *
 * The delete must never remove an item while an order has units of it allocated. Order placement
 * converts a hold to an allocation by updating the item first, so the delete's check of open
 * allocations is made under the item's lock, with a locking read: an allocation committed before
 * the lock is seen even by a caller whose transaction read stock earlier, and one that arrives
 * after it waits for the delete and then finds the item gone.
 *
 * Planted violations: in StockService::deleteVariants(), check for an open allocation before
 * taking the item's lock (move the hasOpenAllocation() test above lockItem()): at READ COMMITTED
 * the allocation B commits at the moment of the lock is missed and the item deleted (at
 * REPEATABLE READ the early check's gap lock makes B wait until its lock-wait timeout). In
 * MysqlStockRepository::OPEN_ALLOCATION, drop `FOR UPDATE`: at REPEATABLE READ the check reads
 * the caller's older snapshot and misses the allocation too.
 *
 * @since 0.1.0
 */
final class SeamsTest extends StockTestCase {

	/**
	 * Tests that items are created at zero, once, with no ledger entry and no event, and only inside a transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_items_are_created_at_zero_idempotently_inside_the_callers_transaction(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->db->transaction(
			function () use ( $first, $second ): void {
				$this->service->createItems( array( $second, $first ) );
				$this->service->createItems( array( $first, $second ) );
			}
		);

		foreach ( array( $first, $second ) as $variant ) {
			$this->assertSame(
				array(
					'on_hand'   => 0,
					'allocated' => 0,
					'held'      => 0,
				),
				$this->committedItem( $b, $variant )
			);
			$this->assertSame( array(), $this->committedLedger( $b, $variant ) );
		}

		$this->assertSame( 0, $this->committedEvents( $b, StockAdjusted::eventName() ) );

		$third = self::variant();
		$log   = $this->captureQueries(
			function () use ( $third ): void {
				try {
					$this->service->createItems( array( $third ) );
					$this->fail( 'An item was created outside the caller\'s transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'createItems() at depth 0' );
		$this->assertNull( $this->committedItem( $b, $third ) );
	}

	/**
	 * Tests that deleting a variant releases its holds, writes off its stock with a final entry, removes the item and keeps the ledger.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_a_variant_releases_writes_off_and_removes_its_item(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );

		$hold = $this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$this->afterCommit = array();

		$this->db->transaction(
			function () use ( $variant ): void {
				$this->service->deleteVariants( array( $variant ), Actor::user( 4 ) );
			}
		);

		$this->assertNull( $this->committedItem( $b, $variant ), 'The item is gone.' );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ), 'Its hold was given back and deleted.' );

		$ledger = $this->committedLedger( $b, $variant );

		$this->assertCount( 2, $ledger, 'The entry that stocked it stays, with the final one.' );
		$this->assertSame( array( '-3', '0', 'variant_deleted', 'user', '4' ), array( $ledger[1]['delta'], $ledger[1]['on_hand_after'], $ledger[1]['reason'], $ledger[1]['actor_type'], $ledger[1]['actor_id'] ) );
		$this->assertSame( 2, $this->committedEvents( $b, StockAdjusted::eventName() ), 'The write-off is an event too.' );

		$this->assertCount( 1, $this->afterCommit );
		$this->assertInstanceOf( StockReservationReleased::class, $this->afterCommit[0] );
		$this->assertSame( array( $hold->holdGroup, 'variant_deleted', array( $variant ), array( 1 ) ), array( $this->afterCommit[0]->holdId, $this->afterCommit[0]->reason, $this->afterCommit[0]->variantIds, $this->afterCommit[0]->quantities ) );
		$this->assertTrue( $this->projectionCheck()->passed, 'The kept ledger of a deleted variant is normal.' );
	}

	/**
	 * Tests that a variant with no stock item is skipped, and one at zero still gets its final entry.
	 *
	 * @since 0.1.0
	 */
	public function test_deleting_a_variant_at_zero_records_the_final_entry_and_one_without_an_item_is_skipped(): void {
		$zero    = self::variant();
		$without = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $zero, 0 );

		$this->db->transaction(
			function () use ( $zero, $without ): void {
				$this->service->deleteVariants( array( $without, $zero ), Actor::system( 'cli', 4 ) );
			}
		);

		$this->assertNull( $this->committedItem( $b, $zero ) );
		$this->assertSame( array( '0', '0', 'variant_deleted', 'system' ), array_values( array_intersect_key( $this->committedLedger( $b, $zero )[0], array_flip( array( 'delta', 'on_hand_after', 'reason', 'actor_type' ) ) ) ) );
		$this->assertSame( array(), $this->committedLedger( $b, $without ) );
	}

	/**
	 * Tests that an open allocation refuses the delete, and the caller's whole transaction rolls back unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_an_open_allocation_blocks_the_delete_and_changes_nothing(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );
		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
		$this->plantAllocation( $b, $variant );

		$before = $this->committedItem( $b, $variant );

		try {
			$this->db->transaction(
				function () use ( $variant ): void {
					$this->insertRow( 1, 'the caller wrote this first' );
					$this->service->deleteVariants( array( $variant ), Actor::user( 4 ) );
				}
			);
			$this->fail( 'A variant with an open allocation was deleted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::DeleteBlocked, $refused->errorCode() );
			$this->assertSame( array( 'variant_id' => $variant ), $refused->context() );
		}

		$this->assertSame( $before, $this->committedItem( $b, $variant ) );
		$this->assertCount( 1, $this->committedHolds( $b, $variant ) );
		$this->assertCount( 1, $this->committedLedger( $b, $variant ) );
		$this->assertSame( 0, $this->committedRows( $b ), 'The caller\'s transaction rolled back whole.' );
	}

	/**
	 * Returns the isolation levels the allocation race runs at.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The levels, as SET SESSION TRANSACTION ISOLATION LEVEL names them.
	 */
	public static function isolationLevels(): array {
		return array(
			'repeatable read' => array( 'REPEATABLE READ' ),
			'read committed'  => array( 'READ COMMITTED' ),
		);
	}

	/**
	 * Tests that an allocation committed at the moment of the delete's lock is seen, although the caller read stock earlier.
	 *
	 * B plays order placement converting a hold: it updates the item, inserts an open allocation
	 * and commits, just before the delete's item lock leaves. The race runs at both isolation
	 * levels a site may use, on both connections. At REPEATABLE READ the caller's transaction read
	 * the item before B committed, so a plain read would still see no allocation. At READ COMMITTED
	 * there are no gap locks, so a check made before the item lock does not stop B's allocation, and
	 * misses it; at REPEATABLE READ such a check's own gap lock would make B wait instead.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider isolationLevels
	 *
	 * @param string $isolation The isolation level of both connections.
	 */
	public function test_an_allocation_committed_at_the_moment_of_the_lock_blocks_the_delete( string $isolation ): void {
		global $wpdb;

		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );
		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$b->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
		$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation );
		$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL ' . $isolation ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- one of two literal levels, restored below.

		$converted = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::LOCK_ITEM ),
			function () use ( $b, $variant ): void {
				// A conversion: the item first, then the hold row gives way to the allocation.
				$b->query( 'START TRANSACTION' );
				$b->query( sprintf( 'UPDATE `%s` SET held = held - 1, allocated = allocated + 1 WHERE variant_id = %d AND held >= 1', $this->table( InventoryTables::ITEMS ), $variant ) );
				$b->query( sprintf( 'DELETE FROM `%s` WHERE variant_id = %d', $this->table( InventoryTables::HOLDS ), $variant ) );
				$this->plantAllocation( $b, $variant );
				$b->query( 'COMMIT' );
			}
		);

		try {
			$this->db->transaction(
				function () use ( $variant ): void {
					// The caller reads stock first, which fixes its snapshot before B's commit at REPEATABLE READ.
					$this->assertSame( 0, $this->service->levels( array( $variant ) )[ $variant ]->allocated );

					$this->service->deleteVariants( array( $variant ), Actor::user( 4 ) );
				}
			);
			$this->fail( 'The item was deleted although an allocation exists.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::DeleteBlocked, $refused->errorCode() );
		} finally {
			$wpdb->query( 'SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		}

		$this->assertTrue( $converted->fired, 'B never converted, so nothing raced the delete.' );
		$this->assertSame( 1, $this->committedItem( $b, $variant )['allocated'] ?? null, 'The item and its allocation stand.' );
	}

	/**
	 * Tests that a conversion arriving after the delete's lock waits for it, and then finds no item to convert.
	 *
	 * @since 0.1.0
	 */
	public function test_a_conversion_arriving_after_the_lock_finds_the_item_gone(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();
		$convert = sprintf( 'UPDATE `%s` SET held = held - 1, allocated = allocated + 1 WHERE variant_id = %d AND held >= 1', $this->table( InventoryTables::ITEMS ), $variant );

		$this->stockItem( $variant, 3 );
		$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

		$waited = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::DELETE_ITEM ),
			function () use ( $b, $convert ): void {
				$b->queryAsync( $convert );
				$this->awaitWaiting( $b, $convert, 'updating' );
			}
		);

		$this->db->transaction(
			function () use ( $variant ): void {
				$this->service->deleteVariants( array( $variant ), Actor::user( 4 ) );
			}
		);

		$this->assertTrue( $waited->fired );
		$this->assertSame( 0, $b->reap(), 'The conversion found no item, so no allocation can follow it.' );
		$this->assertNull( $this->committedItem( $b, $variant ) );
	}

	/**
	 * Tests that releasing variants gives back every hold of them, live or expired, one event per hold, and leaves items and ledgers alone.
	 *
	 * @since 0.1.0
	 */
	public function test_releasing_variants_gives_back_every_hold_and_keeps_the_items(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->stockItem( $first, 5 );
		$this->stockItem( $second, 5 );

		$both    = $this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 2 ) ), 600 );
		$expired = $this->plantHold( $first, 1, -60 );
		$other   = self::variant();

		$this->stockItem( $other, 5 );
		$this->service->hold( array( new HoldLine( $other, 1 ) ), 600 );

		$this->afterCommit = array();
		$ledgerBefore      = array( $this->committedLedger( $b, $first ), $this->committedLedger( $b, $second ) );

		$this->db->transaction(
			function () use ( $first, $second ): void {
				$this->service->releaseVariants( array( $second, $first ), 'product_trashed' );
			}
		);

		foreach ( array( $first, $second ) as $variant ) {
			$this->assertSame(
				array(
					'on_hand'   => 5,
					'allocated' => 0,
					'held'      => 0,
				),
				$this->committedItem( $b, $variant )
			);
			$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		}

		$this->assertSame( $ledgerBefore, array( $this->committedLedger( $b, $first ), $this->committedLedger( $b, $second ) ), 'Releasing holds moves no stock.' );
		$this->assertCount( 1, $this->committedHolds( $b, $other ), 'Another variant\'s hold is untouched.' );

		$released = array();

		foreach ( $this->afterCommit as $event ) {
			$this->assertInstanceOf( StockReservationReleased::class, $event );
			$released[ $event->holdId ] = array( $event->reason, $event->variantIds, $event->quantities );
		}

		$this->assertSame(
			array(
				$both->holdGroup => array( 'product_trashed', array( $first, $second ), array( 1, 2 ) ),
				$expired         => array( 'product_trashed', array( $first ), array( 1 ) ),
			),
			$released
		);

		// Outside a caller's transaction it runs in its own.
		$this->service->releaseVariants( array( $other ), 'product_trashed' );

		$this->assertSame( array(), $this->committedHolds( $b, $other ) );
	}

	/**
	 * Inserts an open allocation of one unit through connection B, committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b         Connection B.
	 * @param int              $variantId The variant.
	 */
	private function plantAllocation( SecondConnection $b, int $variantId ): void {
		$b->query( sprintf( "INSERT INTO `%s` ( variant_id, order_id, order_line_id, quantity, state, created_at ) VALUES ( %d, 1, %d, 1, 'open', UTC_TIMESTAMP(6) )", $this->table( InventoryTables::ALLOCATIONS ), $variantId, $variantId ) );
	}
}
