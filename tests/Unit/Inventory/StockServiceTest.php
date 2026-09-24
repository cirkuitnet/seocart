<?php
/**
 * Tests how the stock service sequences the repository's statement groups, with no database
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Inventory;

use PHPUnit\Framework\TestCase;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\Event\StockReserved;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\FakeStockRepository;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingEventPublisher;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;

/**
 * The service's own decisions: the order of statements, when it reclaims and retries, which error
 * a refusal becomes, and which events it publishes. The statements themselves, their locks and
 * their races are the integration suite's.
 *
 * @since 0.1.0
 */
final class StockServiceTest extends TestCase {

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeTransactionManager
	 */
	private FakeTransactionManager $tx;

	/**
	 * The statements, in memory.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeStockRepository
	 */
	private FakeStockRepository $stock;

	/**
	 * What the service published, recorded once committed.
	 *
	 * @since 0.1.0
	 *
	 * @var RecordingEventPublisher
	 */
	private RecordingEventPublisher $events;

	/**
	 * The service under test.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $service;

	/**
	 * Builds the service over the doubles.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->tx      = new FakeTransactionManager();
		$this->stock   = new FakeStockRepository( $this->tx );
		$this->events  = new RecordingEventPublisher( $this->tx );
		$this->service = new StockService( $this->stock, $this->tx, $this->events, new SequentialIdGenerator(), FrozenClock::at( '2026-09-24 12:00:00' ), new CorrelationId( new SequentialIdGenerator( 9000 ) ) );
	}

	/**
	 * Tests that lines of one variant are added up and every variant is claimed in ascending order, in one unit of work.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hold_adds_up_duplicate_lines_and_claims_in_ascending_order(): void {
		$this->stock->plantItem( 9, 5 );
		$this->stock->plantItem( 4, 5 );

		$hold = $this->service->hold( array( new HoldLine( 9, 1 ), new HoldLine( 4, 2 ), new HoldLine( 9, 2 ) ), 600 );

		$this->assertSame( array( 'configuration:4,9', 'claim:4:2', 'insertHold:4:2', 'claim:9:3', 'insertHold:9:3' ), $this->stock->calls() );
		$this->assertEquals( array( new HoldLine( 4, 2 ), new HoldLine( 9, 3 ) ), $hold->lines );
		$this->assertSame( 1, $this->tx->attempts() );

		$reserved = $this->events->publishedOf( StockReserved::class );

		$this->assertCount( 1, $reserved );
		$this->assertSame( array( 4, 9 ), $reserved[0]->variantIds );
		$this->assertSame( array( 2, 3 ), $reserved[0]->quantities );
		$this->assertSame( $hold->holdGroup, $reserved[0]->holdId );
	}

	/**
	 * Tests that a refused claim reclaims the item's expired holds and claims exactly once more.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_claim_reclaims_expired_holds_and_claims_once_more(): void {
		$this->stock->plantItem( 4, 1 );
		$this->stock->plantHold( 4, 1, -60 );

		$this->service->hold( array( new HoldLine( 4, 1 ) ), 600 );

		$this->assertSame( array( 'configuration:4', 'claim:4:1', 'reclaimExpired:4', 'lockItem:4', 'claim:4:1', 'insertHold:4:1' ), $this->stock->calls() );
		$this->assertCount( 1, $this->events->publishedOf( StockHoldExpired::class ) );
		$this->assertCount( 1, $this->events->publishedOf( StockReserved::class ) );
	}

	/**
	 * Tests that a second refusal is `stock.insufficient` with what was asked and what is available, after exactly two claims.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_refusal_is_insufficient_stock(): void {
		$this->stock->plantItem( 4, 3, 1, 1 );

		try {
			$this->service->hold( array( new HoldLine( 4, 2 ) ), 600 );
			$this->fail( 'A hold of more than is available succeeded.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
			$this->assertSame(
				array(
					'variant_id' => 4,
					'requested'  => 2,
					'available'  => 1,
				),
				$refused->context()
			);
		}

		$this->assertCount( 2, array_filter( $this->stock->calls(), static fn( string $call ): bool => str_starts_with( $call, 'claim:' ) ) );
		$this->assertSame( array(), $this->events->published() );
	}

	/**
	 * Tests that an untracked variant gets no claim and no row, and is listed apart.
	 *
	 * @since 0.1.0
	 */
	public function test_an_untracked_variant_is_neither_claimed_nor_held(): void {
		$this->stock->plantItem( 4, 0, 0, 0, false );
		$this->stock->plantItem( 5, 1 );

		$hold = $this->service->hold( array( new HoldLine( 4, 3 ), new HoldLine( 5, 1 ) ), 600 );

		$this->assertSame( array( 4 ), $hold->untracked );
		$this->assertSame( array( 'configuration:4,5', 'claim:5:1', 'insertHold:5:1' ), $this->stock->calls() );
	}

	/**
	 * Tests that a missing item is refused after the configuration read, before any write.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_item_is_refused_before_any_write(): void {
		$this->stock->plantItem( 4, 1 );

		try {
			$this->service->hold( array( new HoldLine( 4, 1 ), new HoldLine( 8, 1 ) ), 600 );
			$this->fail( 'A hold of a variant without a stock item succeeded.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::ItemMissing, $refused->errorCode() );
			$this->assertSame( array( 'variant_id' => 8 ), $refused->context() );
		}

		$this->assertSame( array( 'configuration:4,8' ), $this->stock->calls() );
	}

	/**
	 * Tests the programming errors a hold refuses before opening a transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hold_refuses_programming_errors_before_any_transaction(): void {
		$attempts = array(
			fn() => $this->service->hold( array(), 600 ),
			fn() => $this->service->hold( array( new HoldLine( 4, 1 ) ), 0 ),
			fn() => $this->service->hold( array( new HoldLine( 4, 1 ) ), 600, 0 ),
			// @phpstan-ignore argument.type (The test passes what a caller must not.)
			fn() => $this->service->hold( array( 'not a line' ), 600 ),
			static fn() => new HoldLine( 4, 0 ),
			static fn() => new HoldLine( 0, 1 ),
		);

		foreach ( $attempts as $index => $attempt ) {
			try {
				$attempt();
				$this->fail( "Attempt {$index} was accepted." );
			} catch ( \InvalidArgumentException $expected ) {
				$this->assertNotSame( '', $expected->getMessage() );
			}
		}

		$this->assertSame( 0, $this->tx->attempts() );
	}

	/**
	 * Tests that an adjustment of zero is refused before any transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_a_zero_adjustment_is_refused_before_any_transaction(): void {
		try {
			$this->service->adjust( 4, 0, LedgerReason::Recount, Actor::user( 1 ) );
			$this->fail( 'A zero adjustment was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::ZeroDelta, $refused->errorCode() );
		}

		$this->assertSame( 0, $this->tx->attempts() );
	}

	/**
	 * Tests that a system reason is refused for an adjustment.
	 *
	 * @since 0.1.0
	 */
	public function test_a_system_reason_is_not_an_adjustment(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->service->adjust( 4, 1, LedgerReason::VariantDeleted, Actor::user( 1 ) );
	}

	/**
	 * Tests that a refused adjustment is classified from one read: missing, conflicting or below zero.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refused_adjustment_is_classified_from_one_read(): void {
		$this->stock->plantItem( 4, 5 );

		$cases = array(
			array( 8, -1, null, InventoryError::ItemMissing, array( 'variant_id' => 8 ) ),
			array(
				4,
				1,
				4,
				InventoryError::OnHandConflict,
				array(
					'variant_id' => 4,
					'expected'   => 4,
					'on_hand'    => 5,
				),
			),
			array(
				4,
				-6,
				5,
				InventoryError::AdjustmentBelowZero,
				array(
					'variant_id' => 4,
					'on_hand'    => 5,
					'delta'      => -6,
				),
			),
		);

		foreach ( $cases as $case ) {
			try {
				$this->service->adjust( $case[0], $case[1], LedgerReason::Recount, Actor::user( 1 ), $case[2] );
				$this->fail( 'A refused adjustment was reported as applied.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( $case[3], $refused->errorCode() );
				$this->assertSame( $case[4], $refused->context() );
			}
		}

		$this->assertSame( array(), $this->stock->ledger() );
		$this->assertSame( array(), $this->events->published() );
	}

	/**
	 * Tests that an adjustment appends its entry with the level read after it, the actor and the correlation id, and publishes it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_adjustment_records_the_level_after_it(): void {
		$this->stock->plantItem( 4, 2, 0, 3 );

		$adjustment = $this->service->adjust( 4, 5, LedgerReason::Received, Actor::system( 'cli', 7 ) );

		$this->assertSame( 7, $adjustment->level->onHand );
		$this->assertSame( 4, $adjustment->level->available() );
		$this->assertSame( array( 'adjust:4:5', 'lockedLevel:4', 'appendLedger:4:5' ), $this->stock->calls() );
		$this->assertSame( 'system', $this->stock->ledger()[0]['actor_type'] );
		$this->assertSame( 7, $this->stock->ledger()[0]['actor_id'] );
		$this->assertSame( SequentialIdGenerator::nth( 9000 ), $this->stock->ledger()[0]['correlation_id'] );

		$event = $this->events->publishedOf( StockAdjusted::class )[0];

		$this->assertSame( array( 4, 5, 7, 4, 'received', 'system', 7, $adjustment->ledgerEntryId ), array( $event->variantId, $event->delta, $event->onHand, $event->available, $event->reason, $event->actorType, $event->actorId, $event->ledgerEntryId ) );
	}

	/**
	 * Tests that the two seams refuse to run outside the caller's transaction, before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_the_seams_refuse_to_run_outside_a_transaction(): void {
		foreach ( array( fn() => $this->service->createItems( array( 4 ) ), fn() => $this->service->deleteVariants( array( 4 ), Actor::user( 1 ) ) ) as $call ) {
			try {
				$call();
				$this->fail( 'A seam ran outside a transaction.' );
			} catch ( \LogicException $expected ) {
				$this->assertStringContainsString( 'transaction', $expected->getMessage() );
			}
		}

		$this->assertSame( array(), $this->stock->calls() );
	}

	/**
	 * Tests the delete seam's order: lock, the allocation check, the release, the write-off, the delete.
	 *
	 * @since 0.1.0
	 */
	public function test_the_delete_seam_locks_checks_releases_writes_off_and_deletes(): void {
		$this->stock->plantItem( 4, 3 );
		$this->stock->plantHold( 4, 1, 300, 'group-a' );

		$this->tx->transaction(
			fn() => $this->service->deleteVariants( array( 4 ), Actor::user( 2 ) )
		);

		$this->assertSame(
			array( 'lockItem:4', 'hasOpenAllocation:4', 'reclaimVariant:4', 'lockItem:4', 'lockedLevel:4', 'adjust:4:-3', 'lockedLevel:4', 'appendLedger:4:-3', 'deleteItem:4' ),
			$this->stock->calls()
		);

		$released = $this->events->publishedOf( StockReservationReleased::class );

		$this->assertCount( 1, $released );
		$this->assertSame( array( 'group-a', 'variant_deleted', array( 4 ), array( 1 ) ), array( $released[0]->holdId, $released[0]->reason, $released[0]->variantIds, $released[0]->quantities ) );
		$this->assertSame( -3, $this->events->publishedOf( StockAdjusted::class )[0]->delta );
	}

	/**
	 * Tests that an open allocation refuses the delete before any hold is released.
	 *
	 * @since 0.1.0
	 */
	public function test_an_open_allocation_refuses_the_delete(): void {
		$this->stock->plantItem( 4, 3 );
		$this->stock->plantOpenAllocation( 4 );

		try {
			$this->tx->transaction( fn() => $this->service->deleteVariants( array( 4 ), Actor::user( 2 ) ) );
			$this->fail( 'A variant with an open allocation was deleted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::DeleteBlocked, $refused->errorCode() );
		}

		$this->assertSame( array( 'lockItem:4', 'hasOpenAllocation:4' ), $this->stock->calls() );
	}

	/**
	 * Tests that a release gives back the hold's rows item by item, ascending, and publishes one release.
	 *
	 * @since 0.1.0
	 */
	public function test_a_release_gives_back_each_item_of_the_hold_and_publishes_once(): void {
		$this->stock->plantItem( 9, 5 );
		$this->stock->plantItem( 4, 5 );
		$this->stock->plantHold( 9, 2, 300, 'group-a' );
		$this->stock->plantHold( 4, 1, 300, 'group-a' );
		$this->stock->plantHold( 4, 1, 300, 'group-b' );

		$this->assertSame( 2, $this->service->release( 'group-a', 'payment_declined' ) );
		$this->assertSame( 0, $this->service->release( 'group-a', 'payment_declined' ), 'A released hold has nothing left to release.' );

		$released = $this->events->publishedOf( StockReservationReleased::class );

		$this->assertCount( 1, $released );
		$this->assertSame( array( 4, 9 ), $released[0]->variantIds );
		$this->assertSame( array( 1, 2 ), $released[0]->quantities );
		$this->assertCount( 1, $this->stock->holdRows(), 'The other hold is untouched.' );
	}

	/**
	 * Tests that releasing variants gives back every hold of them, expired or not, one event per hold, and keeps the items.
	 *
	 * @since 0.1.0
	 */
	public function test_releasing_variants_gives_back_every_hold_one_event_per_hold(): void {
		$this->stock->plantItem( 4, 5 );
		$this->stock->plantItem( 9, 5 );
		$this->stock->plantHold( 4, 1, 300, 'group-a' );
		$this->stock->plantHold( 4, 2, -300, 'group-b' );
		$this->stock->plantHold( 9, 1, 300, 'group-a' );

		$this->service->releaseVariants( array( 9, 4 ), 'product_trashed' );

		$released = $this->events->publishedOf( StockReservationReleased::class );

		$this->assertCount( 2, $released );
		$this->assertSame( array( 'group-a', array( 4, 9 ), array( 1, 1 ) ), array( $released[0]->holdId, $released[0]->variantIds, $released[0]->quantities ) );
		$this->assertSame( array( 'group-b', array( 4 ), array( 2 ) ), array( $released[1]->holdId, $released[1]->variantIds, $released[1]->quantities ) );
		$this->assertSame( array(), $this->stock->holdRows() );
		$this->assertSame( 0, $this->service->levels( array( 4 ) )[4]->held );
		$this->assertSame( array(), $this->stock->ledger(), 'Releasing holds moves no stock.' );
	}

	/**
	 * Tests that a reclaim that finds `held` below its rows is `stock.projection_corrupt`.
	 *
	 * @since 0.1.0
	 */
	public function test_held_below_its_rows_is_a_corrupt_projection(): void {
		$this->stock->plantItem( 4, 1 );
		$this->stock->plantHold( 4, 2, -60 );

		// The item is planted again with `held` 1, below the 2-unit row that is still there.
		$this->stock->plantItem( 4, 1, 0, 1 );

		try {
			$this->service->reclaimExpired( 4 );
			$this->fail( 'A reclaim gave back more than was held.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::ProjectionCorrupt, $refused->errorCode() );
		}
	}
}
