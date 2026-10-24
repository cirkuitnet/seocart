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
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
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
		$stock = '/`(' . implode( '|', array_map( fn( string $name ): string => preg_quote( $this->table( $name ), '/' ), array( InventoryTables::POLICIES, InventoryTables::ITEMS, InventoryTables::HOLDS, InventoryTables::ALLOCATIONS ) ) ) . ')`/';

		$this->assertQueryCount( 1 + 1 + 2 * 2 + 1, $log->matching( $stock ), 'One configuration read, one read of the hold\'s rows, then per item the conversion and the delete of its row, then one insert of both allocation rows' );
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

		$this->service->adjust( $variant, $this->defaultLocation, 1, LedgerReason::Received, Actor::user( 1 ) );

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
	 * Tests that two order lines of one variant, held together in one hold row, are allocated from that row: each line its units, nothing left held.
	 *
	 * @since 0.2.0
	 */
	public function test_two_lines_of_one_variant_are_allocated_from_the_hold_that_covers_both(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 4 );

		$hold      = $this->service->hold( array( new HoldLine( $variant, 3 ), new HoldLine( $variant, 1 ) ), 900 );
		$allocated = $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $variant, 3 ), new Allocation( 11, $variant, 1 ) ) ) );

		$this->assertEquals( array( new Allocation( 10, $variant, 3, $this->defaultLocation ), new Allocation( 11, $variant, 1, $this->defaultLocation ) ), $allocated );
		$this->assertSame( array( 0, 4 ), array( $this->committedItem( $b, $variant )['held'] ?? null, $this->committedItem( $b, $variant )['allocated'] ?? null ) );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that a hold row larger than the order's units of its item converts the units the order needs and leaves the rest held by the hold.
	 *
	 * Every unit on hand is held; the order takes three of the four. The conversion moves three
	 * units from `held` to `allocated` under the item's lock and shrinks the hold's row to one, so
	 * the hold keeps its last unit until it is released or expires.
	 *
	 * Planted violation: in StockService::convert(), convert a row only when it fits whole (skip it
	 * when `$held` exceeds the units still needed): nothing converts, the fresh claim finds every
	 * unit held, and the allocation is refused with `stock.insufficient`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_larger_than_the_order_converts_the_units_the_order_needs(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 4 );

		$hold      = $this->service->hold( array( new HoldLine( $variant, 4 ) ), 900 );
		$allocated = $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $variant, 3 ) ) ) );

		$this->assertEquals( array( new Allocation( 10, $variant, 3, $this->defaultLocation ) ), $allocated );
		$this->assertSame( array( 1, 3 ), array( $this->committedItem( $b, $variant )['held'] ?? null, $this->committedItem( $b, $variant )['allocated'] ?? null ) );
		$this->assertSame( array( $hold->holdGroup . ':1' ), $this->committedHolds( $b, $variant ), 'The hold keeps its last unit.' );
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

		$this->assertEquals( array( new Allocation( 10, $tracked, 1, $this->defaultLocation ) ), $allocated );
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
	 * Tests that a hold split across two locations becomes allocations where its units are, shared out to the order's lines in their order, an order line allocated at both.
	 *
	 * Planted violation: in StockService::convert(), convert every row at the location of the
	 * hold's first row (`(int) array_key_first( $rows )` in place of `$locationId`): the second
	 * location's row stays held, and the order's units are all taken at the default location.
	 *
	 * @since 0.2.0
	 */
	public function test_a_split_hold_is_allocated_where_its_units_are(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 4 );
		$this->service->adjust( $variant, $second, 4, LedgerReason::Received, Actor::user( 1 ) );

		$hold      = $this->service->hold( array( new HoldLine( $variant, 2 ), new HoldLine( $variant, 2, $second ) ), 900 );
		$allocated = $this->db->transaction( fn(): array => $this->service->allocate( $hold->holdGroup, self::ORDER, array( new Allocation( 10, $variant, 3 ), new Allocation( 11, $variant, 1 ) ) ) );

		$this->assertEquals(
			array( new Allocation( 10, $variant, 2, $this->defaultLocation ), new Allocation( 10, $variant, 1, $second ), new Allocation( 11, $variant, 1, $second ) ),
			$allocated,
			'The first line takes the default location\'s units, then the second location\'s; the second line the rest.'
		);
		$this->assertSame( array( 0, 2 ), array( $this->committedItem( $b, $variant )['held'] ?? null, $this->committedItem( $b, $variant )['allocated'] ?? null ) );
		$this->assertSame( array( 0, 2 ), array( $this->committedItem( $b, $variant, $second )['held'] ?? null, $this->committedItem( $b, $variant, $second )['allocated'] ?? null ) );
		$this->assertSame( array( '10:' . $this->defaultLocation . ':2', '10:' . $second . ':1', '11:' . $second . ':1' ), $this->committedLocatedAllocations( $b ) );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );

		$payload = $this->committedPayload( $b, StockAllocated::eventName() );

		$this->assertSame(
			array( array( $variant ), array( 4 ), array( $variant, $variant ), array( $this->defaultLocation, $second ), array( 2, 2 ) ),
			array( $payload['variant_ids'], $payload['quantities'], $payload['location_variant_ids'], $payload['location_ids'], $payload['location_quantities'] ),
			'The allocation names the variant once with its four units, and each of its two items with its own.'
		);
		$this->assertSame( array( $variant => 4 ), array_combine( $payload['variant_ids'], $payload['quantities'] ), 'A reader of the variants alone sees the variant once, with all its units.' );
	}

	/**
	 * Tests that units claimed afresh, for a hold that is gone, are brought to the order's destination from wherever they are, whatever the store's choice for orders no one location ships whole.
	 *
	 * Under `refuse` the order needs more than any one location has: claimed with transfer
	 * semantics it takes the default location's unit and the second location's two. Under `split`
	 * the units are at a location that ships no online orders, which only a transfer may take
	 * from. A paid order is never refused for the way the store prefers to ship.
	 *
	 * Planted violation: in StockService::claimAfresh(), plan under the store's mode instead of
	 * FulfilmentMode::Transfer: under `refuse` the order is refused although the units are there,
	 * and under `split` the location that ships nothing is not taken from.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_modes
	 *
	 * @param string $mode The store's mode.
	 */
	public function test_units_claimed_afresh_are_brought_to_the_destination_whatever_the_mode( string $mode ): void {
		$this->mode = FulfilmentMode::from( $mode );

		$variant = self::variant();
		$other   = 'refuse' === $mode ? $this->plantLocation() : $this->plantLocation( 5, true, false );
		$b       = $this->secondConnection();
		$wanted  = 'refuse' === $mode ? 3 : 2;

		$this->stockItem( $variant, 'refuse' === $mode ? 1 : 0 );
		$this->service->adjust( $variant, $other, 2, LedgerReason::Received, Actor::user( 1 ) );

		$allocated = $this->db->transaction( fn(): array => $this->service->allocate( '01928c3e-7b3c-7d1e-9a2b-000000000002', self::ORDER, array( new Allocation( 10, $variant, $wanted ) ) ) );

		$this->assertEquals(
			'refuse' === $mode ? array( new Allocation( 10, $variant, 1, $this->defaultLocation ), new Allocation( 10, $variant, 2, $other ) ) : array( new Allocation( 10, $variant, 2, $other ) ),
			$allocated
		);
		$this->assertSame( 2, $this->committedItem( $b, $variant, $other )['allocated'] ?? null );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Returns the store's modes the fresh claim is tested under.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The modes.
	 */
	public static function data_modes(): array {
		return array(
			'refuse' => array( 'refuse' ),
			'split'  => array( 'split' ),
		);
	}

	/**
	 * Tests that units claimed afresh that no active location has between them are `stock.insufficient`, the sum they have named, and nothing is allocated.
	 *
	 * @since 0.2.0
	 */
	public function test_units_claimed_afresh_that_no_location_has_are_insufficient(): void {
		$variant = self::variant();
		$second  = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 1 );
		$this->service->adjust( $variant, $second, 2, LedgerReason::Received, Actor::user( 1 ) );

		try {
			$this->db->transaction( fn(): array => $this->service->allocate( '01928c3e-7b3c-7d1e-9a2b-000000000003', self::ORDER, array( new Allocation( 10, $variant, 5 ) ) ) );
			$this->fail( 'Five units were allocated where there are three.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::Insufficient, $refused->errorCode() );
			$this->assertSame( array( 5, 3, $this->defaultLocation ), array( $refused->context()['requested'], $refused->context()['available'], $refused->details()['location_id'] ?? null ) );
		}

		$this->assertSame( array(), $this->committedLocatedAllocations( $b ) );
	}

	/**
	 * Tests that a reclaim committing between the read of the hold's rows and their conversion takes effect once: the units are claimed afresh, and nothing is counted twice or refused.
	 *
	 * The order's hold row has expired. Just before the allocation converts it, another checkout,
	 * connection B, reclaims it with the repository's own statements and commits. The conversion
	 * then finds `held` without the row, so the units are claimed afresh from what is available.
	 * The allocation runs at READ COMMITTED, as a settlement does: every statement sees what is
	 * committed when it is sent, so it is never refused for a row changed after a snapshot, on
	 * MySQL or on MariaDB.
	 *
	 * Planted violation, on MariaDB: run the unit at the server's REPEATABLE READ (drop
	 * `Isolation::ReadCommitted`): the conversion updates an item B changed after the unit's
	 * snapshot, MariaDB refuses it (error 1020), and the unit runs again. MySQL's update reads the
	 * newest row and does not refuse.
	 *
	 * @since 0.2.0
	 *
	 * @group concurrency
	 */
	public function test_a_reclaim_between_the_rows_read_and_the_conversion_takes_effect_once(): void {
		$variant = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		$group = $this->plantHold( $variant, 1, -60 );
		$token = SequentialIdGenerator::nth( 800003 );
		$raced = $this->beforeStatement(
			self::shapeOf( MysqlStockRepository::CONVERT_HOLD ),
			function () use ( $b, $variant, $token ): void {
				$b->query( 'START TRANSACTION' );
				$b->query( $this->raw( MysqlStockRepository::LOCK_ITEM, $variant, $this->defaultLocation ) );
				$b->query( $this->raw( MysqlStockRepository::CLAIM_EXPIRED, $token, $variant, $this->defaultLocation ) );
				$b->query( $this->raw( MysqlStockRepository::GIVE_BACK, $token, $variant, $this->defaultLocation, $token ) );
				$b->query( $this->raw( MysqlStockRepository::DELETE_CLAIMED, $token ) );
				$b->query( 'COMMIT' );
			}
		);
		$runs  = 0;

		$this->db->transaction(
			function () use ( $group, $variant, &$runs ): array {
				++$runs;

				return $this->service->allocate( $group, self::ORDER, array( new Allocation( 10, $variant, 1 ) ) );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);

		$this->assertTrue( $raced->fired, 'The reclaim never raced the conversion.' );
		$this->assertSame( 1, $runs, 'The allocation ran once, never refused.' );
		$this->assertSame( array( 0, 1 ), array( $this->committedItem( $b, $variant )['held'] ?? null, $this->committedItem( $b, $variant )['allocated'] ?? null ), 'The hold given back once, the unit allocated once.' );
		$this->assertSame( array( '10:' . $this->defaultLocation . ':1' ), $this->committedLocatedAllocations( $b ) );
		$this->assertSame( array(), $this->committedHolds( $b, $variant ) );
		$this->assertSame( 0, $this->committedEvents( $b, StockHoldExpired::eventName() ), 'The allocation reclaimed nothing itself: the row was B\'s to give back.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Reads the committed allocation rows with their locations, as connection B sees them.
	 *
	 * @since 0.2.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return list<string> Each open row as `order_line:location:quantity`, by id.
	 */
	private function committedLocatedAllocations( SecondConnection $b ): array {
		$rows = (string) $b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( order_line_id, ':', location_id, ':', quantity ) ORDER BY id SEPARATOR ',' ) FROM `%s` WHERE order_id = %d AND state = 'open'", $this->table( InventoryTables::ALLOCATIONS ), self::ORDER ) );

		return '' === $rows ? array() : explode( ',', $rows );
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
