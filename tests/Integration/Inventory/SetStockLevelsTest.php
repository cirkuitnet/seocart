<?php
/**
 * Tests SetStockLevels against MySQL: several stock cells in one unit, each only while unchanged since it was read
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\SetStockLevels;
use SEOCart\Inventory\Domain\Event\StockAdjusted;
use SEOCart\Inventory\Domain\StockOutcome;
use SEOCart\Inventory\Domain\StockTarget;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * Twelve cells in one READ COMMITTED unit, taken in ascending order of the variant and the location; a cell whose count moved is a conflict and the others stand; each applied cell has its `recount` ledger entry and its StockAdjusted; a hold that races a cell leaves both standing, and one on an item no cell names only waits for the unit; a missing location refuses the unit whole.
 *
 * Planted violations, each shown failing and put back:
 * - In SetStockLevels::apply(), drop the isolation argument: the unit runs at the server's
 *   default, the `SET TRANSACTION` statement is gone, and the unit test fails.
 * - In SetStockLevels::applySorted(), adjust without the count the cell was read at: the moved
 *   cell is applied over the change it did not see, and the conflict test fails.
 * - In MysqlStockRepository::LOCKED_LEVELS, lock the rows read: a hold on an item the cells span
 *   but do not name deadlocks with the unit, and both tests of that hold fail.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class SetStockLevelsTest extends StockTestCase {

	/**
	 * The unit under test.
	 *
	 * @since 0.2.0
	 *
	 * @var SetStockLevels
	 */
	private SetStockLevels $unit;

	/**
	 * Builds the unit over the test's connection and publisher.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->unit = new SetStockLevels( $this->repository, $this->db, $this->publisherOver( $this->db ), FrozenClock::at( self::NOW ), $this->correlation );
	}

	/**
	 * Tests that twelve cells of six variants at two locations are applied in one READ COMMITTED unit, in ascending order, with a ledger entry and an event each.
	 *
	 * @since 0.2.0
	 */
	public function test_twelve_cells_are_one_unit_in_ascending_order(): void {
		$b        = $this->secondConnection();
		$second   = $this->plantLocation( 2 );
		$targets  = array();
		$variants = array();

		for ( $index = 0; $index < 6; $index++ ) {
			$variants[] = self::variant();

			$this->plantItem( $variants[ $index ] );
		}

		// Given in descending order, applied in ascending order.
		foreach ( array_reverse( $variants ) as $variant ) {
			foreach ( array( $second, $this->defaultLocation ) as $location ) {
				$targets[] = new StockTarget( $variant, $location, 10 + $variant % 7, 0 );
			}
		}

		$outcomes = array();
		$log      = $this->captureQueries(
			function () use ( $targets, &$outcomes ): void {
				$outcomes = $this->unit->apply( $targets, Actor::user( 1 ) );
			}
		);

		$sqls = $log->sqls();

		$this->assertSame( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED', $sqls[0] ?? null, 'The unit asks for READ COMMITTED.' );
		$this->assertSame( 1, $log->matching( '/^START TRANSACTION$/' )->count(), 'One unit of work.' );

		$adjusted = array();

		foreach ( $log->matching( '/^UPDATE `[^`]+stock_items` SET on_hand = on_hand \+/' )->sqls() as $sql ) {
			preg_match( '/WHERE variant_id = (\d+) AND location_id = (\d+)/', $sql, $item );
			$adjusted[] = array( (int) $item[1], (int) $item[2] );
		}

		$ascending = $adjusted;

		sort( $ascending );

		$this->assertCount( 12, $adjusted );
		$this->assertSame( $ascending, $adjusted, 'The cells are taken in ascending order of the variant, then the location.' );
		$this->assertSame( array( StockOutcome::APPLIED ), array_values( array_unique( array_map( static fn( StockOutcome $outcome ): string => $outcome->outcome, $outcomes ) ) ) );
		$this->assertSame( 12, $this->committedEvents( $b, StockAdjusted::eventName() ) );
		$this->assertSame( '12', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE reason = 'recount' AND actor_id = 1 AND correlation_id IS NOT NULL", $this->table( InventoryTables::LEDGER ) ) ) );
		$this->assertSame( 10 + $variants[0] % 7, $this->committedItem( $b, $variants[0], $second )['on_hand'] ?? null, 'The item at the second location was created by the unit.' );

		$entries = array_map( static fn( StockOutcome $outcome ): ?int => $outcome->ledgerEntryId, $outcomes );

		foreach ( $outcomes as $outcome ) {
			$this->assertSame( (string) $outcome->onHand, $b->fetchValue( sprintf( 'SELECT on_hand_after FROM `%s` WHERE id = %d AND variant_id = %d AND location_id = %d', $this->table( InventoryTables::LEDGER ), (int) $outcome->ledgerEntryId, $outcome->variantId, $outcome->locationId ) ), 'Each outcome names its own ledger entry.' );
		}

		$this->assertSame( count( $entries ), count( array_unique( $entries ) ) );
		$this->assertCount( 1, $log->matching( '/^INSERT INTO `[^`]+stock_ledger`/' )->sqls(), 'One insert writes every ledger entry.' );
	}

	/**
	 * Tests that a cell whose count moved since it was read is a conflict, with the count it has now, while the others are applied; one already at its count is skipped.
	 *
	 * @since 0.2.0
	 */
	public function test_a_cell_whose_count_moved_is_a_conflict_and_the_others_stand(): void {
		$b      = $this->secondConnection();
		$moved  = self::variant();
		$fine   = self::variant();
		$steady = self::variant();

		$this->plantItem( $moved, 5 );
		$this->plantItem( $fine );
		$this->plantItem( $steady, 3 );

		$outcomes = $this->unit->apply(
			array(
				new StockTarget( $moved, $this->defaultLocation, 20, 0 ),
				new StockTarget( $fine, $this->defaultLocation, 7, 0 ),
				new StockTarget( $steady, $this->defaultLocation, 3, 3 ),
			),
			Actor::user( 1 )
		);

		$this->assertSame(
			array(
				array( $moved, StockOutcome::CONFLICT, 5 ),
				array( $fine, StockOutcome::APPLIED, 7 ),
				array( $steady, StockOutcome::SKIPPED, 3 ),
			),
			array_map( static fn( StockOutcome $outcome ): array => array( $outcome->variantId, $outcome->outcome, $outcome->onHand ), $outcomes )
		);
		$this->assertSame( 5, $this->committedItem( $b, $moved )['on_hand'] ?? null, 'The change the cell did not see stands.' );
		$this->assertSame( array( 0, 1, 0 ), array( count( $this->committedLedger( $b, $moved ) ), count( $this->committedLedger( $b, $fine ) ), count( $this->committedLedger( $b, $steady ) ) ), 'An entry for the applied cell alone.' );
		$this->assertSame( 1, $this->committedEvents( $b, StockAdjusted::eventName() ) );
	}

	/**
	 * Tests that a hold taken on another connection while a cell holds the item's lock waits, then stands beside the applied cell.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_racing_a_cell_leaves_both_standing(): void {
		$b       = $this->secondConnection();
		$variant = self::variant();

		$this->plantItem( $variant, 4 );

		$claim = $this->raw( MysqlStockRepository::CLAIM, 1, $variant, $this->defaultLocation, 1 );

		$this->beforeStatement(
			'/^UPDATE `[^`]+stock_items` SET on_hand = on_hand \+/',
			function () use ( $b, $claim ): void {
				$b->queryAsync( $claim );

				$this->assertTrue( $this->waitsOrAnswers( $b, $claim, 'updating' ), 'The hold waits on the item the unit holds.' );
			}
		);

		$outcomes = $this->unit->apply( array( new StockTarget( $variant, $this->defaultLocation, 9, 4 ) ), Actor::user( 1 ) );

		$this->assertSame( 1, $b->reap(), 'The hold claimed its unit once the cell committed.' );
		$this->assertSame( StockOutcome::APPLIED, $outcomes[0]->outcome ?? null );
		$this->assertSame(
			array(
				'on_hand'   => 9,
				'allocated' => 0,
				'held'      => 1,
			),
			$this->committedItem( $b, $variant )
		);
	}

	/**
	 * Tests that a hold taking an item no cell names, then one the unit holds, only waits for the unit: neither side is rolled back, and the cells apply once with their ledger entries and events.
	 *
	 * The unit's cells are (v1, l1) and (v2, l2); the hold takes (v1, l2), which the cells'
	 * variants and locations span but no cell names, then waits on (v2, l2). A levels read that
	 * locked (v1, l2) as well would wait on the hold in turn, and the server would roll one side back.
	 *
	 * Planted violation: in MysqlStockRepository::LOCKED_LEVELS, lock the rows read (FOR UPDATE);
	 * the hold is then rolled back as the deadlock's victim.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_on_an_item_no_cell_names_only_waits_for_the_unit(): void {
		list( $b, $v1, $v2, $l1, $l2, $hold ) = $this->holdAcrossTheCells();

		$this->beforeStatement(
			'/^SELECT i\.variant_id, i\.location_id, i\.on_hand/',
			function () use ( $b, $hold ): void {
				$b->queryAsync( $hold );

				$this->assertTrue( $this->waitsOrAnswers( $b, $hold, 'updating' ), 'The hold waits on the item the unit holds.' );
			}
		);

		$outcomes = array();
		$log      = $this->captureQueries(
			function () use ( $v1, $v2, $l1, $l2, &$outcomes ): void {
				$outcomes = $this->unit->apply( array( new StockTarget( $v1, $l1, 9, 5 ), new StockTarget( $v2, $l2, 8, 5 ) ), Actor::user( 1 ) );
			}
		);

		$this->assertSame( 2, $b->reap(), 'The hold took both its items once the unit committed.' );
		$this->assertTheUnitAppliedOnceBesideTheHold( $b, $log->matching( '/^START TRANSACTION$/' )->count(), $log->matching( '/^ROLLBACK/' )->count(), $outcomes, $v1, $v2, $l1, $l2 );
	}

	/**
	 * Tests the same race with the hold the heavier transaction, so a deadlock would make the unit its victim: the unit runs once, never rolled back.
	 *
	 * The hold's transaction has changed eighty other items first, so the server, choosing the
	 * lighter side, would roll the unit back and run it again.
	 *
	 * Planted violation: in MysqlStockRepository::LOCKED_LEVELS, lock the rows read (FOR UPDATE);
	 * the unit is then rolled back once and runs again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_heavier_hold_on_an_item_no_cell_names_never_rolls_the_unit_back(): void {
		list( $b, $v1, $v2, $l1, $l2, $hold ) = $this->holdAcrossTheCells();

		$first = self::variant();

		for ( $index = 0; $index < 80; $index++ ) {
			$this->plantItem( 0 === $index ? $first : self::variant(), 1 );
		}

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( 'UPDATE `%s` SET held = held + 1 WHERE variant_id BETWEEN %d AND %d', $this->table( InventoryTables::ITEMS ), $first, $first + 79 ) );

		$this->beforeStatement(
			'/^SELECT i\.variant_id, i\.location_id, i\.on_hand/',
			function () use ( $b, $hold ): void {
				$b->queryAsync( $hold );

				$this->assertTrue( $this->waitsOrAnswers( $b, $hold, 'updating' ), 'The hold waits on the item the unit holds.' );
			}
		);

		$held = null;

		// A unit run again would wait on the hold's open transaction: the hold is let finish first.
		$this->beforeStatement(
			'/^SELECT variant_id FROM `[^`]+stock_policies` WHERE variant_id IN/',
			function () use ( $b, &$held ): void {
				$held = $b->reap();

				$b->query( 'COMMIT' );
			},
			2
		);

		$outcomes = array();
		$log      = $this->captureQueries(
			function () use ( $v1, $v2, $l1, $l2, &$outcomes ): void {
				$outcomes = $this->unit->apply( array( new StockTarget( $v1, $l1, 9, 5 ), new StockTarget( $v2, $l2, 8, 5 ) ), Actor::user( 1 ) );
			}
		);

		if ( null === $held ) {
			$held = $b->reap();

			$b->query( 'COMMIT' );
		}

		$this->assertSame( 2, $held, 'The hold took both its items once the unit committed.' );
		$this->assertTheUnitAppliedOnceBesideTheHold( $b, $log->matching( '/^START TRANSACTION$/' )->count(), $log->matching( '/^ROLLBACK/' )->count(), $outcomes, $v1, $v2, $l1, $l2 );
	}

	/**
	 * Tests that a location that does not exist refuses the unit whole: no item created, no count set.
	 *
	 * @since 0.2.0
	 */
	public function test_a_missing_location_refuses_the_unit_whole(): void {
		$b       = $this->secondConnection();
		$variant = self::variant();

		$this->plantItem( $variant, 4 );

		try {
			$this->unit->apply(
				array(
					new StockTarget( $variant, $this->defaultLocation, 9, 4 ),
					new StockTarget( $variant, 999999, 1, 0 ),
				),
				Actor::user( 1 )
			);

			$this->fail( 'The unit ran with a missing location.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::LocationMissing, $refused->errorCode() );
		}

		$this->assertSame( 4, $this->committedItem( $b, $variant )['on_hand'] ?? null );
		$this->assertNull( $this->committedItem( $b, $variant, 999999 ) );
		$this->assertSame( array(), $this->committedLedger( $b, $variant ) );
	}

	/**
	 * Tests that the unit refuses to run inside another transaction, before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_the_unit_refuses_to_run_inside_a_transaction(): void {
		$variant = self::variant();

		$this->plantItem( $variant );
		$this->expectException( \LogicException::class );

		$this->db->transaction( fn() => $this->unit->apply( array( new StockTarget( $variant, $this->defaultLocation, 1, 0 ) ), Actor::user( 1 ) ) );
	}

	/**
	 * Plants the items (v1, l1), (v1, l2) and (v2, l2), each with 5 units on hand, and a second connection at READ COMMITTED, as a placement's, with the statement of its hold of one unit at (v1, l2) and (v2, l2).
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: SecondConnection, 1: int, 2: int, 3: int, 4: int, 5: string} The connection, v1, v2, l1, l2
	 *         and the hold's statement.
	 */
	private function holdAcrossTheCells(): array {
		$b  = $this->secondConnection();
		$l1 = $this->defaultLocation;
		$l2 = $this->plantLocation( 2 );
		$v1 = self::variant();
		$v2 = self::variant();

		$this->plantItem( $v1, 5 );
		$this->plantItem( $v1, 5, 0, 0, true, $l2 );
		$this->plantItem( $v2, 5, 0, 0, true, $l2 );

		$b->query( 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED' );

		return array( $b, $v1, $v2, $l1, $l2, sprintf( 'UPDATE `%s` SET held = held + 1 WHERE ( variant_id, location_id ) IN ( ( %d, %d ), ( %d, %d ) )', $this->table( InventoryTables::ITEMS ), $v1, $l2, $v2, $l2 ) );
	}

	/**
	 * Asserts that the unit ran once, never rolled back, and applied both cells beside the hold: the counts set, the hold's units held, one ledger entry and one event per cell.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b          The hold's connection, done.
	 * @param int              $started    How many times the unit started its transaction.
	 * @param int              $rolledBack How many times it rolled back.
	 * @param StockOutcome[]   $outcomes   The unit's outcomes.
	 * @param int              $v1         The first variant.
	 * @param int              $v2         The second variant.
	 * @param int              $l1         The first location.
	 * @param int              $l2         The second location.
	 *
	 * @phpstan-param list<StockOutcome> $outcomes
	 */
	private function assertTheUnitAppliedOnceBesideTheHold( SecondConnection $b, int $started, int $rolledBack, array $outcomes, int $v1, int $v2, int $l1, int $l2 ): void {
		$this->assertSame( array( 1, 0 ), array( $started, $rolledBack ), 'One unit of work, never rolled back.' );
		$this->assertSame(
			array( array( $v1, $l1, StockOutcome::APPLIED, 9 ), array( $v2, $l2, StockOutcome::APPLIED, 8 ) ),
			array_map( static fn( StockOutcome $outcome ): array => array( $outcome->variantId, $outcome->locationId, $outcome->outcome, $outcome->onHand ), $outcomes )
		);
		$this->assertSame(
			array(
				array( 9, 0, 0 ),
				array( 5, 0, 1 ),
				array( 8, 0, 1 ),
			),
			array_map(
				fn( array $item ): array => array_values( $this->committedItem( $b, $item[0], $item[1] ) ?? array() ),
				array( array( $v1, $l1 ), array( $v1, $l2 ), array( $v2, $l2 ) )
			),
			'The cells are set, and the hold holds its units.'
		);
		$this->assertSame( array( 1, 1 ), array( count( $this->committedLedger( $b, $v1 ) ), count( $this->committedLedger( $b, $v2 ) ) ), 'One entry per cell.' );
		$this->assertSame( 2, $this->committedEvents( $b, StockAdjusted::eventName() ), 'One event per cell.' );
	}
}
