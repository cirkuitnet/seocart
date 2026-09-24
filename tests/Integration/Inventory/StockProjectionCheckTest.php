<?php
/**
 * Tests the stock check of doctor: every counter agrees with its rows, and each disagreement is named
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Infrastructure\Doctor\StockProjectionCheck;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Tests\Support\Inventory\StockTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test plants inconsistent rows on purpose.

/**
 * Doctor's stock check on planted faults: it names each one with the item and the figures, and a
 * consistent store passes. It reports and never repairs, so the faults are still there after it.
 *
 * Planted violation: in MysqlStockRepository::HELD_DRIFT, join only the unexpired holds
 * (`AND h.expires_at > UTC_TIMESTAMP()` in the join). An item's `held` then disagrees with its
 * rows as soon as one of them expires, and the item with the day-old hold is reported as drifted.
 *
 * @since 0.1.0
 */
final class StockProjectionCheckTest extends StockTestCase {

	/**
	 * Tests that a consistent store passes, with holds live and expired, a shortfall and a deleted variant.
	 *
	 * @since 0.1.0
	 */
	public function test_a_consistent_store_passes(): void {
		$first  = self::variant();
		$second = self::variant();

		$this->stockItem( $first, 4 );
		$this->stockItem( $second, 2 );
		$this->service->hold( array( new HoldLine( $first, 1 ), new HoldLine( $second, 2 ) ), 600 );
		$this->plantHold( $first, 1, -60 );

		$result = $this->projectionCheck();

		$this->assertTrue( $result->passed, implode( "\n", $result->findings ) );
		$this->assertSame( StockProjectionCheck::NAME, $result->check );
		$this->assertSame( 'Every item\'s held, on hand and allocated agree with their rows, and no hold expired more than 24 hours ago.', $result->summary );
	}

	/**
	 * Tests that each planted fault is named with its item and its figures, and nothing is repaired.
	 *
	 * @since 0.1.0
	 */
	public function test_each_fault_is_named_with_its_figures(): void {
		$heldAbove  = self::variant();
		$ledgerOff  = self::variant();
		$allocation = self::variant();
		$dayOld     = self::variant();
		$vanished   = self::variant();
		$tokened    = self::variant();

		$this->stockItem( $heldAbove, 3 );
		$this->plantHold( $heldAbove, 1, 600 );
		$this->db->execute( 'UPDATE %i SET held = held + 1 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $heldAbove );

		$this->stockItem( $ledgerOff, 5 );
		$this->db->execute( 'UPDATE %i SET on_hand = 6 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $ledgerOff );

		$this->stockItem( $allocation, 2 );
		$this->plantOpenAllocation( $allocation, 1 );

		$this->stockItem( $dayOld, 2 );
		$this->plantHold( $dayOld, 1, -25 * 3600 );

		$this->plantOpenAllocation( $vanished, 2 );

		$this->stockItem( $tokened, 2 );
		$this->plantHold( $tokened, 1, 600 );
		$this->db->execute( "UPDATE %i SET reclaim_token = '00000000-0000-7000-8000-000000000001' WHERE variant_id = %d", $this->table( InventoryTables::HOLDS ), $tokened );

		$result = $this->projectionCheck();

		$this->assertFalse( $result->passed );
		$this->assertSame( '6 stock problems found.', $result->summary );

		$expected = array(
			sprintf( '/^Critical: variant %d holds 2, but its hold rows add up to 1\./', $heldAbove ),
			sprintf( '/^Critical: variant %d has 6 on hand, but its ledger adds up to 5 and its newest entry says 5\.$/', $ledgerOff ),
			sprintf( '/^Critical: variant %d has 0 allocated, but its open allocations add up to 1\.$/', $allocation ),
			sprintf( '/^Critical: allocation \d+ of order 1 is open for variant %d, whose stock item is gone\.$/', $vanished ),
			sprintf( '/^Warning: variant %d has 1 hold that expired more than 24 hours ago, the oldest 9\d{4} seconds ago: the sweep is not keeping up\./', $dayOld ),
			sprintf( '/^Warning: variant %d has 1 hold row marked by a reclaim that never finished;/', $tokened ),
		);

		$this->assertCount( count( $expected ), $result->findings, implode( "\n", $result->findings ) );

		foreach ( $expected as $index => $pattern ) {
			$this->assertMatchesRegularExpression( $pattern, $result->findings[ $index ] );
		}

		$this->assertSame( $result->findings, $this->projectionCheck()->findings, 'Doctor repaired nothing.' );
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
	 * Inserts an open allocation of a number of units, committed.
	 *
	 * @since 0.1.0
	 *
	 * @param int $variantId The variant.
	 * @param int $quantity  The units.
	 */
	private function plantOpenAllocation( int $variantId, int $quantity ): void {
		$this->db->execute(
			"INSERT INTO %i ( variant_id, order_id, order_line_id, quantity, state, created_at ) VALUES ( %d, 1, %d, %d, 'open', UTC_TIMESTAMP(6) )",
			$this->table( InventoryTables::ALLOCATIONS ),
			$variantId,
			$variantId,
			$quantity
		);
	}
}
