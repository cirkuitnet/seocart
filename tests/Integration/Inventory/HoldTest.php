<?php
/**
 * Tests holding stock: synchronous reclamation, rollback, nesting in a caller's transaction, a lost connection, untracked and missing items
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
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;

/**
 * A hold as one unit of work: what it sends, what it leaves when anything fails, and how it
 * behaves inside a caller's transaction.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class HoldTest extends StockTestCase {

	/**
	 * Tests that with the sweep never run, an expired hold delays a sale by one retry: the claim is refused, the item reclaimed, the claim accepted.
	 *
	 * The statements are counted from the query log: the claim exactly twice, and the four
	 * statements of the reclaim once each.
	 *
	 * Planted violation: in StockService::claim(), return false instead of reclaiming when the
	 * first claim is refused (leaving expired holds to the sweep): the hold fails with
	 * `stock.insufficient` although the unit was free.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_hold_delays_a_sale_by_at_most_one_retry(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 1 );
		$this->plantHold( $variant, 1, -60 );

		$hold = null;
		$log  = $this->captureQueries(
			function () use ( $variant, &$hold ): void {
				$hold = $this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
			}
		);

		$this->assertNotNull( $hold );
		$this->assertQueryCount( 2, $log->matching( self::shapeOf( MysqlStockRepository::CLAIM ) ), 'The claim: refused, then accepted' );

		foreach ( array( 'LOCK_ITEM', 'CLAIM_EXPIRED', 'GIVE_BACK', 'CLAIMED_ROWS', 'DELETE_CLAIMED' ) as $statement ) {
			$this->assertQueryCount( 1, $log->matching( self::shapeOf( (string) constant( MysqlStockRepository::class . '::' . $statement ) ) ), $statement );
		}

		$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $variant ), 'The expired row is gone; the new hold is the only one.' );
		$this->assertSame( 1, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
		$this->assertCount( 1, $this->afterCommit );
		$this->assertInstanceOf( StockReserved::class, $this->afterCommit[0] );
	}

	/**
	 * Tests that a claim refused again after the reclaim is final: exactly one retry, then `stock.insufficient`.
	 *
	 * Planted violation: in StockService::claim(), reclaim and claim a second time when the
	 * retry is refused: the claim is sent three times.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_hold_is_retried_exactly_once(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 1 );
		$this->plantHold( $variant, 1, 600 );

		$log = $this->captureQueries(
			function () use ( $variant ): void {
				try {
					$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
					$this->fail( 'A unit that another live hold holds was held again.' );
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
			}
		);

		$this->assertQueryCount( 2, $log->matching( self::shapeOf( MysqlStockRepository::CLAIM ) ), 'The claim: refused, and refused once more after the reclaim' );
	}

	/**
	 * Tests that a hold that fails after all its statements leaves nothing: no held units, no row, no event, no reclaim.
	 *
	 * The failure is raised at the last moment, as the transaction is about to commit, after the
	 * reclaim, the hold row and the outbox row were all written.
	 *
	 * Planted violation: in StockService::hold(), run holdInside() directly instead of inside
	 * transaction(): each statement then commits on its own (or the repository refuses to run
	 * outside a transaction), and B sees the changes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rolled_back_hold_emits_and_keeps_nothing(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 1 );

		$expired = $this->plantHold( $variant, 1, -60 );

		$this->beforeStatement(
			'/^RELEASE SAVEPOINT sc_0$/',
			static function (): void {
				throw new \RuntimeException( 'The request died just before COMMIT.' );
			}
		);

		try {
			$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
			$this->fail( 'The hold committed although it failed.' );
		} catch ( \RuntimeException $failed ) {
			$this->assertSame( 'The request died just before COMMIT.', $failed->getMessage() );
		}

		$this->assertSame( 1, $this->committedItem( $b, $variant )['held'] ?? null, 'held is what it was: the expired hold, unreclaimed.' );
		$this->assertSame( array( $expired . ':1' ), $this->committedHolds( $b, $variant ) );
		$this->assertSame( 0, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
		$this->assertSame( array(), $this->afterCommit );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * Tests that a failure before the hold row is written also leaves the claim undone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failure_between_the_claim_and_the_row_undoes_the_claim(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 3 );

		$this->beforeStatement(
			self::shapeOf( MysqlStockRepository::INSERT_HOLD ),
			static function (): void {
				throw new \RuntimeException( 'The request died between the claim and the row.' );
			}
		);

		try {
			$this->service->hold( array( new HoldLine( $variant, 2 ) ), 600 );
			$this->fail( 'The hold committed although it failed.' );
		} catch ( \RuntimeException $failed ) {
			$this->assertSame( 'The request died between the claim and the row.', $failed->getMessage() );
		}

		$this->assertSame( 0, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
	}

	/**
	 * Tests that inside a caller's transaction a deadlock re-runs the caller's whole unit of work, not the hold alone.
	 *
	 * Planted violation: in Database::transaction(), at an inner level, re-run the savepoint when
	 * the policy allows instead of letting the failure go up. The inner level cannot run again
	 * after a deadlock, so the caller's unit of work is not re-run as a whole.
	 *
	 * @since 0.1.0
	 */
	public function test_a_deadlock_inside_a_callers_transaction_re_runs_the_caller(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 2 );
		$this->stockItem( $second, 2 );

		$b     = $this->deadlockBeforeClaimOf( $first, $second );
		$reads = $this->beforeStatement( self::shapeOf( MysqlStockRepository::CONFIGURATION ), static fn() => null, PHP_INT_MAX );
		$outer = 0;

		$this->db->transaction(
			function () use ( $first, $second, &$outer ): void {
				++$outer;

				$this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 1 ) ), 600 );
			},
			RetryPolicy::deadlocks()
		);

		$this->assertSame( array( 50 ), $this->sleeps, 'InnoDB chose B as the deadlock victim: victim selection changed; raise B\'s weight.' );
		$this->assertSame( 2, $outer, 'The caller\'s unit of work ran twice.' );
		$this->assertSame( 2, $reads->seen, 'The hold inside it ran once per attempt, never on its own.' );
		$this->assertSame( 1, $this->committedItem( $b, $first )['held'] ?? null );
		$this->assertSame( 1, $this->committedItem( $b, $second )['held'] ?? null );
	}

	/**
	 * Tests that a caller that fails after a successful hold takes the hold back with it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_caller_that_fails_after_the_hold_leaves_nothing_held(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		try {
			$this->db->transaction(
				function () use ( $variant ): void {
					$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );

					throw new \RuntimeException( 'The caller failed later.' );
				}
			);
		} catch ( \RuntimeException $failed ) {
			$this->assertSame( 'The caller failed later.', $failed->getMessage() );
		}

		$this->assertSame( 0, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		$this->assertSame( array(), $this->afterCommit );
	}

	/**
	 * Tests that a refused hold inside a caller's transaction undoes only its own lines, and the caller decides.
	 *
	 * The first line is held and an expired hold of the second item is reclaimed before the
	 * second line is refused: both are undone with the hold's savepoint. What the caller wrote
	 * before and after stands.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_hold_inside_a_callers_transaction_undoes_only_its_own_lines(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->stockItem( $first, 2 );
		$this->stockItem( $second, 1 );

		$expired = $this->plantHold( $second, 1, -60 );

		$this->db->transaction(
			function () use ( $first, $second ): void {
				$this->insertRow( 1, 'before the hold' );

				try {
					$this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 2 ) ), 600 );
					$this->fail( 'Two units of an item with one were held.' );
				} catch ( CodedException $refused ) {
					$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
				}

				$this->insertRow( 2, 'after the hold' );
			}
		);

		$this->assertSame( 2, $this->committedRows( $b ), 'The caller\'s own writes stand.' );
		$this->assertSame( 0, $this->committedItem( $b, $first )['held'] ?? null, 'The first line was undone with the hold.' );
		$this->assertSame( array( $expired . ':1' ), $this->committedHolds( $b, $second ), 'The reclaim was undone with the hold.' );
		$this->assertSame( 0, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
	}

	/**
	 * Tests that a connection lost inside the hold refuses the commit and leaves nothing committed.
	 *
	 * B kills the connection just before the commit's probe, and wpdb reconnects. The one
	 * statement wpdb then sends, the probe, fails on the new connection; nothing of the hold was
	 * committed. A statement that changes data and meets such a reconnect would be committed on
	 * its own before the transaction manager can refuse it; that limit is the transaction
	 * manager's, and its own tests pin it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reconnect_inside_the_hold_commits_nothing(): void {
		global $wpdb;

		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		$this->beforeStatement(
			'/^RELEASE SAVEPOINT sc_0$/',
			function () use ( $b, $wpdb ): void {
				$b->kill( $this->db->threadId() );
				$wpdb->check_connection();
			}
		);

		try {
			$this->service->hold( array( new HoldLine( $variant, 1 ) ), 600 );
			$this->fail( 'The hold committed on a connection that is not the one it began on.' );
		} catch ( TransactionIntegrityLost $lost ) {
			$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $lost->reason() );
		}

		$this->assertSame( 0, $this->committedItem( $b, $variant )['held'] ?? null );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * Tests that an untracked item gets no update and no row, and is listed on the hold as untracked.
	 *
	 * Planted violation: in StockService::holdInside(), ignore the configuration read's tracking
	 * and treat every line as tracked: the untracked item is then claimed, reclaimed and read.
	 *
	 * @since 0.1.0
	 */
	public function test_an_untracked_item_is_not_touched(): void {
		$untracked = self::variant();
		$tracked   = self::variant();
		$b         = $this->secondConnection();

		$this->plantItem( $untracked, 0, 0, 0, false );
		$this->stockItem( $tracked, 1 );

		$hold = null;
		$log  = $this->captureQueries(
			function () use ( $untracked, $tracked, &$hold ): void {
				$hold = $this->service->hold( array( new HoldLine( $untracked, 3 ), new HoldLine( $tracked, 1 ) ), 600 );
			}
		);

		$this->assertNotNull( $hold );
		$this->assertSame( array( $untracked ), $hold->untracked );
		$this->assertEquals( array( new HoldLine( $tracked, 1 ) ), $hold->lines );
		$this->assertQueryCount( 0, $log->ofType( 'UPDATE', 'INSERT', 'DELETE' )->matching( '/variant_id = ' . $untracked . '\b|\( ' . $untracked . ',/' ), 'Writes naming the untracked item' );
		$this->assertSame( array(), $this->committedHolds( $b, $untracked ) );
		$this->assertSame( array( $tracked ), $this->afterCommit[0]->variantIds ?? null );
	}

	/**
	 * Tests that an unknown variant is `stock.item_missing` before any write, and that programming errors send nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_item_and_a_bad_line_write_nothing(): void {
		$known   = self::variant();
		$unknown = self::variant();

		$this->stockItem( $known, 3 );

		$log = $this->captureQueries(
			function () use ( $known, $unknown ): void {
				try {
					$this->service->hold( array( new HoldLine( $known, 1 ), new HoldLine( $unknown, 1 ) ), 600 );
					$this->fail( 'A variant with no stock item was held.' );
				} catch ( CodedException $refused ) {
					$this->assertSame( InventoryError::ItemMissing, $refused->errorCode() );
					$this->assertSame( array( 'variant_id' => $unknown ), $refused->context() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->ofType( 'UPDATE', 'INSERT', 'DELETE' ), 'Writes of a hold with a missing item' );

		$log = $this->captureQueries(
			function () use ( $known ): void {
				foreach ( array( static fn() => new HoldLine( $known, 0 ), fn() => $this->service->hold( array(), 600 ), fn() => $this->service->hold( array( new HoldLine( $known, 1 ) ), 0 ) ) as $attempt ) {
					try {
						$attempt();
						$this->fail( 'A hold that is a programming error was accepted.' );
					} catch ( \InvalidArgumentException $expected ) {
						$this->assertNotSame( '', $expected->getMessage() );
					}
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'Statements of holds that are programming errors' );
	}
}
