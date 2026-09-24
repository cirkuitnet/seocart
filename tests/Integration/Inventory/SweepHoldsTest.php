<?php
/**
 * Tests the sweep of expired holds: it reclaims exactly what a checkout would, item by item, within its budget
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Event\StockHoldExpired;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Inventory\StockTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One test corrupts an item's held on purpose.

/**
 * SweepHolds over real tables, with the service a checkout uses.
 *
 * Planted violation: in MysqlStockRepository::CLAIM_EXPIRED, claim rows that expire within the
 * hour (`expires_at <= UTC_TIMESTAMP() + INTERVAL 1 HOUR`): the live hold is then reclaimed.
 *
 * @since 0.1.0
 */
final class SweepHoldsTest extends StockTestCase {

	/**
	 * Tests that the sweep reclaims every expired hold and only those, keeps every projection true, and a second run changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_reclaims_every_expired_hold_and_nothing_else(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->stockItem( $first, 5 );
		$this->stockItem( $second, 5 );
		$this->plantHold( $first, 1, -60 );
		$this->plantHold( $first, 2, -120 );

		$live = $this->plantHold( $first, 1, 600 );

		$this->plantHold( $second, 1, -60 );

		$this->assertTrue( $this->projectionCheck()->passed, 'held agrees with its rows before the sweep.' );

		( new SweepHolds( $this->service ) )->handle( array() );

		$this->assertSame( 1, $this->committedItem( $b, $first )['held'] ?? null, 'Only the live hold is still held.' );
		$this->assertSame( array( $live . ':1' ), $this->committedHolds( $b, $first ) );
		$this->assertSame( 0, $this->committedItem( $b, $second )['held'] ?? null );
		$this->assertSame( array(), $this->committedHolds( $b, $second ) );
		$this->assertSame( 3, $this->committedEvents( $b, StockHoldExpired::eventName() ), 'One event per reclaimed row.' );
		$this->assertTrue( $this->projectionCheck()->passed, 'held agrees with its rows after the sweep.' );

		$log = $this->captureQueries( fn() => ( new SweepHolds( $this->service ) )->handle( array() ) );

		$this->assertQueryCount( 0, $log->ofType( 'UPDATE', 'INSERT', 'DELETE' ), 'Writes of a sweep with nothing expired' );
		$this->assertSame( 3, $this->committedEvents( $b, StockHoldExpired::eventName() ) );
	}

	/**
	 * Tests that the sweep stops when its budget is spent: with none, one item per run.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_stops_at_its_budget(): void {
		$first  = self::variant();
		$second = self::variant();
		$b      = $this->secondConnection();

		$this->stockItem( $first, 2 );
		$this->stockItem( $second, 2 );
		$this->plantHold( $first, 1, -60 );
		$this->plantHold( $second, 1, -60 );

		( new SweepHolds( $this->service, 0 ) )->handle( array() );

		$afterOne = array( $this->committedItem( $b, $first ), $this->committedItem( $b, $second ) );

		( new SweepHolds( $this->service, 0 ) )->handle( array() );

		$afterTwo = $this->committedItem( $b, $second );

		$this->assertSame( 0, $afterOne[0]['held'] ?? null, 'The lower item was swept.' );
		$this->assertSame( 1, $afterOne[1]['held'] ?? null, 'The budget was spent before the higher one.' );
		$this->assertSame( 0, $afterTwo['held'] ?? null, 'The next run took the rest.' );
	}

	/**
	 * Tests that the sweep pages through the items, a page at a time, in ascending order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sweep_pages_through_the_items(): void {
		$variants = array( self::variant(), self::variant(), self::variant() );
		$b        = $this->secondConnection();

		foreach ( $variants as $variant ) {
			$this->stockItem( $variant, 1 );
			$this->plantHold( $variant, 1, -60 );
		}

		$pages = $this->beforeStatement( '/^SELECT DISTINCT variant_id FROM /', static fn() => null, PHP_INT_MAX );

		( new SweepHolds( $this->service, SweepHolds::BUDGET_SECONDS, 2 ) )->handle( array() );

		foreach ( $variants as $variant ) {
			$this->assertSame( 0, $this->committedItem( $b, $variant )['held'] ?? null );
		}

		$this->assertSame( 2, $pages->seen, 'A full page of two, then a short page of one.' );
	}

	/**
	 * Tests that an item whose reclaim fails does not stop the sweep of the others, and the run then fails with its error.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_item_does_not_stop_the_sweep_of_the_others(): void {
		$corrupt = self::variant();
		$healthy = self::variant();
		$b       = $this->secondConnection();

		$this->stockItem( $corrupt, 2 );
		$this->stockItem( $healthy, 2 );
		$this->plantHold( $corrupt, 2, -60 );
		$this->plantHold( $healthy, 1, -60 );
		$this->db->execute( 'UPDATE %i SET held = 1 WHERE variant_id = %d', $this->table( InventoryTables::ITEMS ), $corrupt );

		try {
			( new SweepHolds( $this->service ) )->handle( array() );
			$this->fail( 'The sweep hid a corrupt projection.' );
		} catch ( CodedException $failed ) {
			$this->assertSame( InventoryError::ProjectionCorrupt, $failed->errorCode() );
			$this->assertSame( array( 'variant_id' => $corrupt ), $failed->context() );
		}

		$this->assertSame( 0, $this->committedItem( $b, $healthy )['held'] ?? null, 'The healthy item was swept.' );
		$this->assertSame( 1, $this->committedItem( $b, $corrupt )['held'] ?? null, 'The corrupt item was left for a person.' );
		$this->assertCount( 1, $this->committedHolds( $b, $corrupt ) );
	}
}
