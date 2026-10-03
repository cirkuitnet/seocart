<?php
/**
 * Tests allocating an accepted order's units from its hold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Inventory\Domain\Event\StockAllocated;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;
use SEOCart\Tests\Support\SecondConnection;

/**
 * An order's hold becomes its allocation, by the hold rows' own quantities and under each item's lock, all or nothing.
 *
 * `held` and `allocated` equal their rows after every case: the projection check passes. Each
 * test runs allocate() inside a transaction of its own, as the settlement of a placement does.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class AllocateTest extends StockTestCase {

	/**
	 * The order every test allocates to.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ORDER = 4242;

	/**
	 * Tests that a hold of two items becomes two open allocations: `held` moves to `allocated`, the hold rows go, and one event is written.
	 *
	 * Planted violation: in MysqlStockRepository::convertHold(), return true right after
	 * CONVERT_HOLD, without deleting the hold's row: the rows then outlive the units they held, and
	 * the projection check reports `held` below its rows.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hold_becomes_the_orders_allocation(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 5 );
		$this->stockItem( $second, 5 );

		$hold = $this->service->hold( array( new HoldLine( $first, 2 ), new HoldLine( $second, 1 ) ), 900 );
		$b    = $this->secondConnection();

		$log = $this->captureQueries(
			fn() => $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 11, $second, 1 ), new Allocation( 10, $first, 2 ) ) ) )
		);

		foreach ( array(
			$first  => 2,
			$second => 1,
		) as $variant => $units ) {
			$item = $this->committedItem( $b, $variant );

			$this->assertSame( array( 0, $units ), array( $item['held'] ?? null, $item['allocated'] ?? null ), 'The units moved from held to allocated.' );
			$this->assertSame( array(), $this->committedHolds( $b, $variant ), 'The hold\'s rows are gone.' );
		}

		$this->assertSame( array( array( 10, $first, 2 ), array( 11, $second, 1 ) ), $this->committedAllocations( $b ) );
		$this->assertSame( 1, $this->committedEvents( $b, StockAllocated::eventName() ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
		$stock = '/`(' . implode( '|', array_map( fn( string $name ): string => preg_quote( $this->table( $name ), '/' ), array( InventoryTables::ITEMS, InventoryTables::HOLDS, InventoryTables::ALLOCATIONS ) ) ) . ')`/';

		$this->assertQueryCount( 1 + 2 * 2 + 1, $log->matching( $stock ), 'One configuration read, then per item the conversion and the delete of its row, then one insert of both allocation rows' );
	}

	/**
	 * Tests that a hold whose row expired and was reclaimed by another checkout is allocated afresh from what is available.
	 *
	 * The other checkout's own hold is left as it is.
	 *
	 * Planted violation: in StockService::allocateInside(), skip claimAllocation() when the
	 * conversion finds no row: the allocation row then claims units `allocated` never counted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_hold_reclaimed_by_another_checkout_is_allocated_afresh(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 2 );

		$ours = $this->plantHold( $variant, 1, -60 );

		$this->service->hold( array( new HoldLine( $variant, 2 ) ), 900 );

		$b = $this->secondConnection();

		$this->assertNotContains( $ours . ':1', $this->committedHolds( $b, $variant ), 'The other checkout reclaimed the expired hold to take its units.' );

		$this->service->adjust( $variant, 1, LedgerReason::Received, Actor::user( 1 ) );

		$this->db->transaction( fn(): array => $this->service->allocate( $ours, self::ORDER, array( new Allocation( 10, $variant, 1 ) ) ) );

		$item = $this->committedItem( $b, $variant );

		$this->assertSame( array( 2, 1 ), array( $item['held'] ?? null, $item['allocated'] ?? null ), 'The other checkout\'s hold is untouched; the order is allocated from what was free.' );
		$this->assertSame( array( array( 10, $variant, 1 ) ), $this->committedAllocations( $b ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that a hold whose row is gone, while another hold of the item would cover its units, is not taken for that other hold's: the conversion is undone.
	 *
	 * Planted violation: in MysqlStockRepository::convertHold(), return true when DELETE_HOLD_ROW
	 * deletes nothing: the other checkout's `held` is then spent on this order, and `held` falls
	 * below the other hold's row.
	 *
	 * @since 0.1.0
	 */
	public function test_another_holds_units_are_never_converted(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 2 );

		$other = $this->service->hold( array( new HoldLine( $variant, 1 ) ), 900 );
		$gone  = '01928c3e-7b3c-7d1e-9a2b-000000000001';
		$b     = $this->secondConnection();

		$this->db->transaction( fn(): array => $this->service->allocate( $gone, self::ORDER, array( new Allocation( 10, $variant, 1 ) ) ) );

		$item = $this->committedItem( $b, $variant );

		$this->assertSame( array( 1, 1 ), array( $item['held'] ?? null, $item['allocated'] ?? null ) );
		$this->assertSame( array( $other->holdGroup . ':1' ), $this->committedHolds( $b, $variant ), 'The other checkout\'s hold row stays.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that an allocation that cannot be made, its hold gone and its units sold, is refused whole, and the caller's transaction goes on.
	 *
	 * The second item's units are another checkout's hold. The first item's conversion is undone
	 * with the allocation's savepoint, and the caller's transaction commits around the refusal.
	 *
	 * @since 0.1.0
	 */
	public function test_an_allocation_that_cannot_be_made_is_refused_whole(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 1 );
		$this->stockItem( $second, 1 );

		$hold = $this->service->hold( array( new HoldLine( $first, 1 ) ), 900 );

		$this->service->hold( array( new HoldLine( $second, 1 ) ), 900 );

		$refused = null;

		$this->db->transaction(
			function () use ( $hold, $first, $second, &$refused ): void {
				try {
					$this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $first, 1 ), new Allocation( 11, $second, 1 ) ) );
				} catch ( CodedException $insufficient ) {
					$refused = $insufficient;
				}
			}
		);

		$b = $this->secondConnection();

		$this->assertInstanceOf( CodedException::class, $refused );
		$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
		$this->assertSame( array( 1, 0 ), array( $this->committedItem( $b, $first )['held'] ?? null, $this->committedItem( $b, $first )['allocated'] ?? null ), 'The first item\'s conversion was undone with the rest.' );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $first ) );
		$this->assertSame( array(), $this->committedAllocations( $b ) );
		$this->assertSame( 0, $this->committedEvents( $b, StockAllocated::eventName() ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that an untracked item is not allocated, and that allocate() refuses to run outside a transaction.
	 *
	 * @since 0.1.0
	 */
	public function test_an_untracked_item_is_not_allocated_and_a_transaction_is_required(): void {
		$tracked   = self::variant();
		$untracked = self::variant();

		$this->stockItem( $tracked, 2 );
		$this->plantItem( $untracked, 0, 0, 0, false );

		$hold = $this->service->hold( array( new HoldLine( $tracked, 1 ), new HoldLine( $untracked, 3 ) ), 900 );

		$allocated = $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $tracked, 1 ), new Allocation( 11, $untracked, 3 ) ) ) );

		$this->assertEquals( array( new Allocation( 10, $tracked, 1 ) ), $allocated );
		$this->assertSame( array( array( 10, $tracked, 1 ) ), $this->committedAllocations( $this->secondConnection() ) );

		$this->expectException( \LogicException::class );

		$this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $tracked, 1 ) ) );
	}

	/**
	 * Tests that an expired hold row the sweep has not reached yet is still the order's: it is converted, not reclaimed.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_hold_row_not_yet_reclaimed_is_still_converted(): void {
		$variant = self::variant();

		$this->stockItem( $variant, 1 );

		$group = $this->plantHold( $variant, 1, -60 );

		$this->db->transaction( fn(): array => $this->service->allocate( $group, self::ORDER, array( new Allocation( 10, $variant, 1 ) ) ) );

		$b = $this->secondConnection();

		$this->assertSame( array( 0, 1 ), array( $this->committedItem( $b, $variant )['held'] ?? null, $this->committedItem( $b, $variant )['allocated'] ?? null ) );
		$this->assertSame( 0, $this->committedEvents( $b, StockHoldExpired::eventName() ), 'Nothing was reclaimed.' );
	}

	/**
	 * Tests that a second allocation of an order fails on the order line's unique key at its first variant, though a later variant is sold out by the first allocation, and changes nothing.
	 *
	 * The first variant's hold is gone and it still has units, so its fresh claim succeeds; the
	 * second variant has none left. The rows claimed so far are written right after the fresh
	 * claim, so the duplicate is refused there, as it is when every row is written as its units
	 * are claimed, and never reported as units that are gone.
	 *
	 * Planted violation: in StockService::allocateInside(), write the rows only once every variant
	 * is claimed, after a fresh claim too: the second allocation is then refused with
	 * `stock.insufficient` for the second variant, which a settlement takes for an order whose
	 * units are gone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_allocation_of_an_order_fails_at_its_first_variant(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 3 );
		$this->stockItem( $second, 1 );

		$hold  = $this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 1 ) ), 900 );
		$lines = array( new Allocation( 10, $first, 1 ), new Allocation( 11, $second, 1 ) );

		$this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, $lines ) );

		$refused = null;

		try {
			$this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, $lines ) );
		} catch ( DuplicateKey $duplicate ) {
			$refused = $duplicate;
		}

		$b = $this->secondConnection();

		$this->assertInstanceOf( DuplicateKey::class, $refused, 'The second allocation is refused by the order line\'s unique key.' );

		foreach ( array( $first, $second ) as $variant ) {
			$item = $this->committedItem( $b, $variant );

			$this->assertSame( array( 0, 1 ), array( $item['held'] ?? null, $item['allocated'] ?? null ), 'Nothing was allocated twice.' );
		}

		$this->assertSame( array( array( 10, $first, 1 ), array( 11, $second, 1 ) ), $this->committedAllocations( $b ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that a hold and an order with more lines than one insert carries are written in bounded statements, and every line gets its rows.
	 *
	 * Planted violation: in MysqlStockRepository::insertRows(), send every row in one statement,
	 * without array_chunk(): one insert carries every hold row, and one every allocation row.
	 *
	 * @since 0.1.0
	 */
	public function test_a_large_hold_and_its_allocation_are_written_in_bounded_inserts(): void {
		$lines    = MysqlStockRepository::ROWS_PER_INSERT + 1;
		$holds    = array();
		$expected = array();

		for ( $line = 1; $line <= $lines; $line++ ) {
			$variant = self::variant();

			$this->stockItem( $variant, 1 );

			$holds[]    = new HoldLine( $variant, 1 );
			$expected[] = array( $line, $variant, 1 );
		}

		$hold = null;
		$held = $this->captureQueries(
			function () use ( $holds, &$hold ): void {
				$hold = $this->service->hold( $holds, 900 );
			}
		);

		$this->assertNotNull( $hold );
		$this->assertQueryCount( 2, $held->matching( $this->insertInto( InventoryTables::HOLDS ) ), 'A full insert of hold rows, then one of the row left over' );
		$this->assertSame( (string) $lines, $this->secondConnection()->fetchValue( sprintf( "SELECT COUNT(*) FROM `%s` WHERE hold_group = '%s'", $this->table( InventoryTables::HOLDS ), $hold->holdGroup ) ), 'Every line has its hold row.' );

		$allocated = $this->captureQueries(
			fn() => $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array_map( static fn( array $row ): Allocation => new Allocation( ...$row ), $expected ) ) )
		);

		$this->assertQueryCount( 2, $allocated->matching( $this->insertInto( InventoryTables::ALLOCATIONS ) ), 'A full insert of allocation rows, then one of the row left over' );
		$this->assertSame( $expected, $this->committedAllocations( $this->secondConnection() ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Returns the pattern of an insert into one of the stock tables.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table The table's unprefixed name.
	 * @return string The pattern.
	 */
	private function insertInto( string $table ): string {
		return '/^INSERT INTO `' . preg_quote( $this->table( $table ), '/' ) . '`/';
	}

	/**
	 * Reads the committed allocation rows, as connection B sees them.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return list<array{0: int, 1: int, 2: int}> Each open row's order line, variant and units, by order line.
	 */
	private function committedAllocations( SecondConnection $b ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( order_line_id, ':', variant_id, ':', quantity ) ORDER BY order_line_id SEPARATOR ',' ) FROM `%s` WHERE order_id = %d AND state = 'open'", $this->table( InventoryTables::ALLOCATIONS ), self::ORDER ) );

		return '' === $rows ? array() : array_map( static fn( string $row ): array => array_map( 'intval', explode( ':', $row ) ), explode( ',', $rows ) );
	}
}
