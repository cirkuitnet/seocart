<?php
/**
 * Tests the stock check of doctor: every counter of every item agrees with its rows, the locations are whole, and each disagreement is named
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Domain\Event\StockReservationReleased;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Inventory\Infrastructure\MysqlStockRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Inventory\StockTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test plants inconsistent rows on purpose.

/**
 * Doctor's stock check on planted faults: it names each one with the item, its location and the
 * figures, and a consistent store passes. It reports the financial projections and never repairs
 * them, so those faults are still there after it; its repair gives a store its default location,
 * and a variant its policy and its item at the default location.
 *
 * Planted violations, each named on its test:
 *
 * - in MysqlStockRepository::HELD_DRIFT, join only the unexpired holds (`AND h.expires_at >
 *   UTC_TIMESTAMP()` in the join): an item's `held` then disagrees with its rows as soon as one of
 *   them expires, and the item with the day-old hold is reported as drifted;
 * - in MysqlStockRepository::ALLOCATED_DRIFT, count every allocation row in place of the open
 *   ones (drop `AND a.state = 'open'`): the units travelling with a transfer are counted at the
 *   item, and a consistent store fails.
 *
 * @since 0.1.0
 */
final class StockProjectionCheckTest extends StockTestCase {

	/**
	 * Tests that a consistent store passes, with stock at two locations, holds live and expired, an allocation in transit, a shortfall and a deleted variant; the default location's missing address is noted.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Two locations, an allocation in transit, and the note.
	 */
	public function test_a_consistent_store_passes(): void {
		$first   = self::variant();
		$second  = self::variant();
		$deleted = self::variant();
		$other   = $this->plantLocation();

		$this->stockItem( $first, 4 );
		$this->stockItem( $second, 2 );
		$this->stockItem( $deleted, 1 );
		$this->service->adjust( $first, $other, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 2 ), new HoldLine( $first, 1, $other ) ), 600 );
		$this->plantHold( $first, 1, -60 );
		$this->service->adjust( $second, $this->defaultLocation, -1, LedgerReason::Recount, Actor::user( 1 ) );
		$this->plantAllocation( $first, $other, 2, 'in_transit' );
		$this->db->transaction( fn() => $this->service->deleteVariants( array( $deleted ), Actor::user( 1 ) ) );

		$result = $this->projectionCheck();

		$this->assertTrue( $result->passed, implode( "\n", $result->findings ) );
		$this->assertSame( StockProjectionCheck::NAME, $result->check );
		$this->assertSame( 'Every item\'s held, on hand and allocated agree with their rows, no hold expired more than 24 hours ago, and the locations are whole.', $result->summary );
		$this->assertSame( array( $this->addressNote() ), $result->findings, 'The one note: the default location has no address yet.' );
	}

	/**
	 * Tests that each planted fault of an item's counters or holds is named with the item, its location and its figures, and nothing is repaired.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location of each item.
	 */
	public function test_each_fault_is_named_with_its_item_and_figures(): void {
		$heldAbove  = self::variant();
		$ledgerOff  = self::variant();
		$allocation = self::variant();
		$dayOld     = self::variant();
		$vanished   = self::variant();
		$tokened    = self::variant();
		$other      = $this->plantLocation();

		$this->stockItem( $heldAbove, 0 );
		$this->service->adjust( $heldAbove, $other, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->plantHold( $heldAbove, 1, 600, null, $other );
		$this->db->execute( 'UPDATE %i SET held = held + 1 WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $heldAbove, $other );

		$this->stockItem( $ledgerOff, 5 );
		$this->db->execute( 'UPDATE %i SET on_hand = 6 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $ledgerOff );

		$this->stockItem( $allocation, 2 );
		$this->plantAllocation( $allocation, $this->defaultLocation, 1 );

		$this->stockItem( $dayOld, 2 );
		$this->plantHold( $dayOld, 1, -25 * 3600 );

		$this->plantPolicy( $vanished );
		$this->plantItem( $vanished );
		$this->plantAllocation( $vanished, $other, 2 );

		$this->stockItem( $tokened, 2 );
		$this->plantHold( $tokened, 1, 600 );
		$this->db->execute( "UPDATE %i SET reclaim_token = '00000000-0000-7000-8000-000000000001' WHERE variant_id = %d", $this->table( InventoryTables::HOLDS ), $tokened );

		$result = $this->projectionCheck();

		$this->assertFalse( $result->passed );
		$this->assertSame( '6 stock problems found.', $result->summary );
		$this->assertFindings(
			array(
				sprintf( '/^Critical: variant %d at location %d holds 2, but its hold rows add up to 1\./', $heldAbove, $other ),
				sprintf( '/^Critical: variant %d at location %d has 6 on hand, but its ledger adds up to 5 and its newest entry says 5\.$/', $ledgerOff, $this->defaultLocation ),
				sprintf( '/^Critical: variant %d at location %d has 0 allocated, but its open allocations add up to 1\.$/', $allocation, $this->defaultLocation ),
				sprintf( '/^Critical: allocation \d+ of order 1 is open for variant %d at location %d, whose stock item is gone\.$/', $vanished, $other ),
				sprintf( '/^Warning: variant %d at location %d has 1 hold that expired more than 24 hours ago, the oldest 9\d{4} seconds ago: the sweep is not keeping up\./', $dayOld, $this->defaultLocation ),
				sprintf( '/^Warning: variant %d at location %d has 1 hold row marked by a reclaim that never finished;/', $tokened, $this->defaultLocation ),
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$result->findings
		);

		// A second can pass between the two runs, so the age of the day-old hold is left out of the comparison.
		$ageless = static fn( array $findings ): array => preg_replace( '/\d+ seconds ago/', 'N seconds ago', $findings );
		$this->assertSame( $ageless( $result->findings ), $ageless( $this->projectionCheck()->findings ), 'Doctor repaired nothing.' );
	}

	/**
	 * Tests that stock, allocations and ledger entries at a location that no longer exists are critical, each named, and a deactivated location with units available is a warning.
	 *
	 * Planted violation, shown red and removed: in StockProjectionCheck::locationFindings(), leave
	 * out ledgerAtLostLocations(). The ledger's entries at the lost location go unreported.
	 *
	 * @since 0.2.0
	 */
	public function test_stock_at_a_lost_or_deactivated_location_is_named(): void {
		$variant  = self::variant();
		$lost     = $this->plantLocation();
		$inactive = $this->plantLocation( 2, false );

		$this->stockItem( $variant, 1 );
		$this->service->adjust( $variant, $lost, 2, LedgerReason::Received, Actor::user( 1 ) );
		$this->service->adjust( $variant, $inactive, 3, LedgerReason::Received, Actor::user( 1 ) );
		$this->plantAllocation( $variant, $lost, 1 );
		$this->db->execute( 'UPDATE %i SET allocated = 1 WHERE variant_id = %d AND location_id = %d', $this->table( InventoryTables::ITEMS ), $variant, $lost );
		$this->db->execute( 'DELETE FROM %i WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $lost );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$result = $this->projectionCheck();

		$this->assertSame( '4 stock problems found.', $result->summary );
		$this->assertFindings(
			array(
				sprintf( '/^Critical: allocation \d+ of order 1 is open for variant %d at location %d, which no longer exists\.$/', $variant, $lost ),
				sprintf( '/^Critical: variant %d has a stock item at location %d, which no longer exists\.$/', $variant, $lost ),
				sprintf( '/^Critical: the stock ledger has entries at location %d, which no longer exists,/', $lost ),
				sprintf( '/^Warning: location %d is deactivated but has 3 units available, which no order can be routed to\./', $inactive ),
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$result->findings
		);
	}

	/**
	 * Tests that stock without a policy, and a policy without its item at the default location, are critical, and --repair gives each what it lacks, once.
	 *
	 * Planted violation: in StockProjectionCheck::repair(), give createItems() only the variants
	 * without a policy (leave out policiesWithoutDefaultItem()): the policy without its item is
	 * still reported after the repair.
	 *
	 * @since 0.2.0
	 */
	public function test_a_variant_without_its_policy_or_its_default_item_is_repaired(): void {
		$withoutPolicy = self::variant();
		$withoutItem   = self::variant();
		$other         = $this->plantLocation();
		$b             = $this->secondConnection();

		$this->db->execute( 'INSERT INTO %i ( variant_id, location_id, on_hand, updated_at ) VALUES ( %d, %d, 0, UTC_TIMESTAMP(6) )', $this->table( InventoryTables::ITEMS ), $withoutPolicy, $other );
		$this->plantPolicy( $withoutItem );

		$result = $this->projectionCheck();

		$this->assertSame( '2 stock problems found.', $result->summary );
		$this->assertFindings(
			array(
				sprintf( '/^Critical: variant %d has stock but no stock policy\. --repair gives it one at the defaults\.$/', $withoutPolicy ),
				sprintf( '/^Critical: variant %d has no stock item at the default location\. --repair gives it one at zero\.$/', $withoutItem ),
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$result->findings
		);

		$this->assertSame(
			array(
				sprintf( 'variant %d has its stock policy and its item at the default location.', $withoutPolicy ),
				sprintf( 'variant %d has its stock policy and its item at the default location.', $withoutItem ),
			),
			$this->stockCheck()->repair()->changes
		);
		$this->assertSame( array(), $this->stockCheck()->repair()->changes, 'A second repair has nothing to do.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $withoutPolicy ) ) );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $withoutPolicy, $other ) ), 'The item at the other location is kept.' );
	}

	/**
	 * Tests that a hold whose item is gone is critical, named with the hold, the variant and the location, and --repair recreates the item and gives the hold back, so the sweep finds nothing wrong after it.
	 *
	 * The item at the second location never existed, so its holds were given back by nothing: the
	 * sweep's reclaim begins with the item's lock, and finds no item. After the repair the item is
	 * at zero with no hold, each hold was released with the reason `item_missing`, the sweep runs
	 * clean and the check passes. A live hold of the same variant at the default location, whose
	 * item is there, is left as it was: the repair restores the recreated item's `held` from the
	 * hold rows at its own location only.
	 *
	 * Planted violations, each shown red and removed: in
	 * StockService::releaseHoldsOfMissingItems(), skip the reclaim after the item is recreated: the
	 * repair gives nothing back and the live hold stays, able to turn units the location does not
	 * have into an allocation; recreate the item bare, at zero, with createItemAt(), and skip the
	 * reclaim: the sweep then finds `held` below the expired hold's row and fails with
	 * `stock.projection_corrupt`; and in MysqlStockRepository::RESTORE_HELD, sum the hold rows of
	 * the variant at every location: the recreated item keeps a unit held for the default
	 * location's hold, which no hold row at its own location accounts for.
	 *
	 * @since 0.2.0
	 */
	public function test_a_hold_whose_item_is_gone_is_named_and_given_back(): void {
		$variant = self::variant();
		$other   = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		$kept    = $this->plantHold( $variant, 1, 900 );
		$expired = $this->plantHold( $variant, 1, -60, null, $other );
		$live    = $this->plantHold( $variant, 2, 900, null, $other );

		$this->assertNull( $this->committedItem( $b, $variant, $other ), 'The item the holds name is gone.' );

		$result = $this->projectionCheck();

		$this->assertSame( '2 stock problems found.', $result->summary );
		$this->assertFindings(
			array(
				sprintf( '/^Critical: hold %s holds 1 unit of variant %d at location %d, whose stock item is gone, so nothing can give it back\. --repair recreates the item and gives the hold back\.$/', preg_quote( $expired, '/' ), $variant, $other ),
				sprintf( '/^Critical: hold %s holds 2 units of variant %d at location %d, whose stock item is gone, so nothing can give them back\. --repair recreates the item and gives the hold back\.$/', preg_quote( $live, '/' ), $variant, $other ),
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$result->findings
		);

		$this->afterCommit = array();

		$changes = $this->stockCheck()->repair()->changes;

		// The sweep right after the repair, before anything else is asserted: a repair that leaves the hold to the sweep fails here.
		( new SweepHolds( $this->service ) )->handle( array() );

		$this->assertSame( array( sprintf( 'recreated the stock item of variant %1$d at location %2$d, at zero, and gave back hold %3$s (1 unit), hold %4$s (2 units).', $variant, $other, $expired, $live ) ), $changes );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant, $other ) ), 'The item is back, at zero.' );
		$this->assertSame( array( $kept . ':1' ), $this->committedHolds( $b, $variant ), 'Both holds at the second location were given back, and the one at the default location was not.' );
		$this->assertSame( array( 2, 0, 1 ), array_values( (array) $this->committedItem( $b, $variant ) ), 'The item at the default location, and the unit its hold holds, are untouched.' );
		$this->assertSame(
			array( array( $expired, 'item_missing', array( $variant ) ), array( $live, 'item_missing', array( $variant ) ) ),
			array_map( static fn( StockReservationReleased $event ): array => array( $event->holdId, $event->reason, $event->variantIds ), array_values( array_filter( $this->afterCommit, static fn( $event ): bool => $event instanceof StockReservationReleased ) ) )
		);
		$this->assertSame( array(), $this->stockCheck()->repair()->changes, 'A second repair has nothing to do.' );
		$this->assertTrue( $this->projectionCheck()->passed, implode( "\n", $this->projectionCheck()->findings ) );
	}

	/**
	 * Tests that the repair of a hold whose item is gone leaves an item someone created meanwhile, and its holds, as they are.
	 *
	 * A second connection creates the item, at zero, right before the repair recreates it: the
	 * repair's insert then finds the row, so it gives nothing back and reports nothing, and the
	 * hold is still there, now a drift of `held` for a person.
	 *
	 * @since 0.2.0
	 */
	public function test_the_repair_leaves_an_item_created_meanwhile_and_its_holds_alone(): void {
		$variant = self::variant();
		$other   = $this->plantLocation();
		$b       = $this->secondConnection();

		$this->stockItem( $variant, 2 );

		$hold  = $this->plantHold( $variant, 1, 900, null, $other );
		$raced = $this->beforeStatement(
			'/^' . preg_quote( $this->raw( MysqlStockRepository::CREATE_ITEM_AT, $variant, $other ), '/' ) . '$/',
			function () use ( $b, $variant, $other ): void {
				$b->query( $this->raw( MysqlStockRepository::CREATE_ITEM_AT, $variant, $other ) );
			}
		);

		$this->assertSame( array(), $this->stockCheck()->repair()->changes );
		$this->assertTrue( $raced->fired, 'The other connection never created the item first.' );
		$this->assertSame( array( 0, 0, 0 ), array_values( (array) $this->committedItem( $b, $variant, $other ) ), 'The item is as the other connection created it.' );
		$this->assertSame( array( $hold . ':1' ), $this->committedHolds( $b, $variant ), 'Its hold is untouched.' );
		$this->assertFindings(
			array(
				sprintf( '/^Critical: variant %d at location %d holds 0, but its hold rows add up to 1\./', $variant, $other ),
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$this->projectionCheck()->findings
		);
	}

	/**
	 * Tests that a store with no default location is critical, and --repair makes the active location with the lowest id the default; a store where no active location ships online orders is critical, and not repaired.
	 *
	 * @since 0.2.0
	 */
	public function test_a_store_without_a_default_or_a_fulfilling_location_is_named(): void {
		$inactive = $this->plantLocation( 0, false );
		$active   = $this->plantLocation();

		$this->db->execute( 'UPDATE %i SET is_default = NULL', $this->table( InventoryTables::LOCATIONS ) );
		$this->db->execute( 'UPDATE %i SET active = 0 WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $this->defaultLocation );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$this->assertFindings(
			array( '/^Critical: the store has no default location\. --repair makes the active location with the lowest id the default\.$/' ),
			$this->projectionCheck()->findings
		);
		$this->assertSame( array( sprintf( 'made location %d the default location.', $active ) ), $this->stockCheck()->repair()->changes, 'The lowest active id, not the deactivated location below it.' );
		$this->assertGreaterThan( $inactive, $active );
		$this->assertSame( (string) $active, $this->db->fetchValue( 'SELECT id FROM %i WHERE is_default = 1', $this->table( InventoryTables::LOCATIONS ) ) );

		$this->db->execute( 'UPDATE %i SET fulfils_online = 0', $this->table( InventoryTables::LOCATIONS ) );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$result = $this->projectionCheck();

		$this->assertFindings(
			array(
				'/^Critical: no active location ships online orders, so nothing can be sold\.$/',
				'/^Warning: the default location, \d+, has no address yet\./',
			),
			$result->findings
		);
		$this->assertSame( array(), $this->stockCheck()->repair()->changes, 'Which location ships is the merchant\'s choice.' );
	}

	/**
	 * Tests that the tolerance for an expired hold is the retention period of the holds: 24 hours.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tolerance_is_the_holds_retention_period(): void {
		$this->assertSame( 86400, StockProjectionCheck::toleranceSeconds() );

		$recent = self::variant();

		$this->stockItem( $recent, 2 );
		$this->plantHold( $recent, 1, -23 * 3600 );

		$this->assertTrue( $this->projectionCheck()->passed, 'A hold expired 23 hours ago is within the tolerance.' );
	}

	/**
	 * Asserts the findings, one pattern each, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $patterns The patterns.
	 * @param string[] $findings The findings.
	 */
	private function assertFindings( array $patterns, array $findings ): void {
		$this->assertCount( count( $patterns ), $findings, implode( "\n", $findings ) );

		foreach ( array_values( $patterns ) as $index => $pattern ) {
			$this->assertMatchesRegularExpression( $pattern, $findings[ $index ] );
		}
	}

	/**
	 * Returns the note on the default location, which has no address in a test.
	 *
	 * @since 0.2.0
	 *
	 * @return string The note.
	 */
	private function addressNote(): string {
		return sprintf( 'Warning: the default location, %d, has no address yet. Shipping labels and pickup confirmations print it once it is entered.', $this->defaultLocation );
	}

	/**
	 * Inserts an allocation of a number of units at a location, committed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location and the state.
	 *
	 * @param int    $variantId  The variant.
	 * @param int    $locationId The location.
	 * @param int    $quantity   The units.
	 * @param string $state      Optional. The state. Default `open`.
	 */
	private function plantAllocation( int $variantId, int $locationId, int $quantity, string $state = 'open' ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, location_id, order_id, order_line_id, quantity, state, created_at ) VALUES ( %d, %d, 1, %d, %d, %s, UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::ALLOCATIONS ),
			$variantId,
			$locationId,
			$variantId * 10 + $locationId,
			$quantity,
			$state
		);
	}
}
