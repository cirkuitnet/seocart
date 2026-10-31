<?php
/**
 * Tests doctor's transfer lines, each on a planted violation
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Tests\Support\Inventory\TransferTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant what only a fault, or a change outside the plugin, leaves.

/**
 * Each of doctor's six transfer lines, named on a store whose transfers were made by the service and then planted with one fault.
 *
 * Each test asserts its own finding is there and names its transfer, its line or its location;
 * a fault may also show in another line, which is that line's business.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class TransferDoctorTest extends TransferTestCase {

	/**
	 * Tests that a line that received and lost more than it sent, and a line of a received transfer that received and lost fewer, are critical.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::LINES_OUT_OF_BOUNDS,
	 * leave out the received transfer's condition: the line short of its quantity passes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_line_out_of_its_bounds_is_critical(): void {
		$over  = $this->sentTransfer( 3 );
		$short = $this->sentTransfer( 2 );

		$this->db->execute( 'UPDATE %i SET received_quantity = 4 WHERE id = %d', $this->table( InventoryTables::TRANSFER_LINES ), $over['line'] );
		$this->db->execute( "UPDATE %i SET state = 'received' WHERE id = %d", $this->table( InventoryTables::TRANSFERS ), $short['id'] );

		$this->assertFound( sprintf( '/^Critical: line %d of transfer %d sends 3 units, of which 4 were received and 0 lost/', $over['line'], $over['id'] ) );
		$this->assertFound( sprintf( '/^Critical: line %d of transfer %d sends 2 units, of which 0 were received and 0 lost/', $short['line'], $short['id'] ) );
	}

	/**
	 * Tests that a sent transfer whose units on the way, by its lines, are not what its ledger entries moved, is critical: here two transfers whose ledger entries were swapped.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::TRANSIT_DRIFT, total the
	 * ledger by location rather than by transfer (`GROUP BY location_id ) g ON g.location_id =
	 * t.source_location_id`): each of the pair is said to have moved the units of both.
	 *
	 * @since 0.2.0
	 */
	public function test_units_on_the_way_that_the_ledger_does_not_explain_are_critical(): void {
		$first  = $this->sentTransfer( 3 );
		$second = $this->sentTransfer( 2, $first['source'], $first['variant'] );
		$ledger = $this->table( InventoryTables::LEDGER );

		$this->db->execute( 'UPDATE %i SET transfer_id = transfer_id + 1000000 WHERE transfer_id IN ( %d, %d )', $ledger, $first['id'], $second['id'] );
		$this->db->execute( 'UPDATE %i SET transfer_id = %d WHERE transfer_id = %d', $ledger, $second['id'], $first['id'] + 1000000 );
		$this->db->execute( 'UPDATE %i SET transfer_id = %d WHERE transfer_id = %d', $ledger, $first['id'], $second['id'] + 1000000 );

		$this->assertFound( sprintf( '/^Critical: transfer %d has 3 units on the way by its lines, but its ledger entries moved 2 out and in, and 0 were lost/', $first['id'] ) );
		$this->assertFound( sprintf( '/^Critical: transfer %d has 2 units on the way by its lines, but its ledger entries moved 3 out and in, and 0 were lost/', $second['id'] ) );
	}

	/**
	 * Tests that an allocation in transit that no line of a transfer in transit carries, its line taken off or its transfer marked received, and a line carrying units on the way for an allocation that is not in transit, are critical.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::UNCARRIED_ALLOCATIONS,
	 * count every line that names the allocation, whatever its transfer's state: the allocation a
	 * received transfer's line names passes.
	 *
	 * @since 0.2.0
	 */
	public function test_an_allocation_in_transit_and_its_line_must_agree(): void {
		$source = $this->plantLocation();
		$v      = self::variant();
		$w      = self::variant();
		$order  = self::order();

		$this->stockItem( $v, 2, $source );
		$this->stockItem( $w, 2, $source );

		$carried = $this->plantAllocation( $v, $source, $order, 1, 2 );
		$moved   = $this->plantAllocation( $w, $source, $order, 2, 2 );
		$uuid    = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;
		$sent    = $this->send( $uuid );
		$b       = $this->secondConnection();

		$this->db->execute( 'UPDATE %i SET allocation_id = NULL WHERE id = %d', $this->table( InventoryTables::TRANSFER_LINES ), self::lineOf( $sent, $v ) );
		$this->db->execute( "UPDATE %i SET state = 'open' WHERE id = %d", $this->table( InventoryTables::ALLOCATIONS ), $moved );

		// A second order's units, whose transfer is marked received while they are still on the way.
		$stranded = self::order();
		$x        = self::variant();

		$this->stockItem( $x, 2, $source );

		$left    = $this->plantAllocation( $x, $source, $stranded, 1, 2 );
		$arrived = $this->requestFor( $stranded, $this->defaultLocation )[0]->uuid;

		$this->send( $arrived );
		$this->db->execute( "UPDATE %i SET state = 'received' WHERE uuid = %s", $this->table( InventoryTables::TRANSFERS ), $arrived );

		$this->assertFound( sprintf( '/^Critical: allocation %d of order %d is in transit with 2 units, but the lines of transfers in transit carry 0 of them\.$/', $carried, $order ) );
		$this->assertFound( sprintf( '/^Critical: allocation %d of order %d is in transit with 2 units, but the lines of transfers in transit carry 0 of them\.$/', $left, $stranded ) );
		$this->assertFound( sprintf( '/^Critical: line %d of transfer %d has units on the way for allocation %d, which is not in transit\.$/', self::lineOf( $sent, $w ), (int) $this->committedTransfer( $b, $uuid )['id'], $moved ) );
	}

	/**
	 * Tests that a transfer requested, or in transit, for longer than the warning period is a warning, whatever its kind.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::OVERDUE, leave out the
	 * transfers in transit: a deactivation's move that lost its job is never named.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_waiting_too_long_is_a_warning(): void {
		$source    = $this->plantLocation();
		$v         = self::variant();
		$order     = self::order();
		$transfers = $this->table( InventoryTables::TRANSFERS );

		$this->stockItem( $v, 2, $source );
		$this->plantAllocation( $v, $source, $order, 1, 1 );

		$requested = (int) $this->committedTransfer( $this->secondConnection(), $this->requestFor( $order, $this->defaultLocation )[0]->uuid )['id'];
		$moving    = $this->sentTransfer( 1, $source, $v );

		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 4 DAY WHERE id = %d', $transfers, $requested );
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 5 DAY, sent_at = UTC_TIMESTAMP(6) - INTERVAL 4 DAY WHERE id = %d', $transfers, $moving['id'] );

		$this->assertFound( sprintf( '/^Warning: transfer %d was requested more than 3 days ago and has not been sent: an order waits for it\.$/', $requested ) );
		$this->assertFound( sprintf( '/^Warning: transfer %d \(manual\) was sent more than 3 days ago and is still in transit\./', $moving['id'] ) );
	}

	/**
	 * Tests that transfers naming a location that no longer exists, as source or destination, are critical.
	 *
	 * Planted violation, shown red and removed: in MysqlTransferRepository::FROM_LOST_LOCATIONS,
	 * read the destinations in place of the sources: the vanished source is never named.
	 *
	 * @since 0.2.0
	 */
	public function test_a_transfer_naming_a_vanished_location_is_critical(): void {
		$gone = 990000 + self::variant();

		foreach ( array( array( $gone, $this->defaultLocation ), array( $this->defaultLocation, $gone + 1 ) ) as list( $from, $to ) ) {
			$this->db->execute( "INSERT INTO %i ( uuid, source_location_id, destination_location_id, kind, state, created_at, updated_at ) VALUES ( %s, %d, %d, 'manual', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $this->table( InventoryTables::TRANSFERS ), wp_generate_uuid4(), $from, $to );
		}

		$this->assertFound( sprintf( '/^Critical: transfers from location %d, which no longer exists/', $gone ) );
		$this->assertFound( sprintf( '/^Critical: transfers to location %d, which no longer exists/', $gone + 1 ) );
	}

	/**
	 * Tests that a received transfer that lost an order's units is critical while the order is not on hold, and quiet once it is.
	 *
	 * Planted violation, shown red and removed: in StockProjectionCheck::transferFindings(), name the
	 * transfer whatever the order's status: an order put on hold is still named.
	 *
	 * @since 0.2.0
	 */
	public function test_a_shortage_not_acted_on_is_critical(): void {
		$source = $this->plantLocation();
		$v      = self::variant();
		$order  = self::order();

		$this->stockItem( $v, 2, $source );
		$this->plantAllocation( $v, $source, $order, 1, 2 );

		$uuid = $this->requestFor( $order, $this->defaultLocation )[0]->uuid;
		$sent = $this->send( $uuid );
		$id   = (int) $this->committedTransfer( $this->secondConnection(), $uuid )['id'];

		$this->receive( $uuid, array( array( self::lineOf( $sent, $v ), 1, 1, 0 ) ) );

		$this->orderStatus = static fn(): string => 'processing';

		$this->assertFound( sprintf( '/^Critical: transfer %d lost units order %d waited for, but the order is processing, not on hold/', $id, $order ) );

		$this->orderStatus = static fn(): string => 'on_hold';

		$doctor = $this->projectionCheck();

		$this->assertTrue( $doctor->passed, 'Doctor with the order on hold: ' . implode( "\n", $doctor->findings ) );
	}

	/**
	 * Sends a draft of one variant from a location to the default location, planting what it needs.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $units   The units sent.
	 * @param int|null $source  Optional. The source. Default a new location.
	 * @param int|null $variant Optional. The variant, with units at the source already. Default a new one.
	 * @return array{id: int, line: int, source: int, variant: int} The transfer, its line, the source and the variant.
	 */
	private function sentTransfer( int $units, ?int $source = null, ?int $variant = null ): array {
		$b = $this->secondConnection();

		if ( null === $variant || null === $source ) {
			$source ??= $this->plantLocation();
			$variant = self::variant();

			$this->stockItem( $variant, 9, $source );
		}

		$draft = $this->draft( $source, $this->defaultLocation, array( $variant => $units ) );

		$this->send( (string) $draft['transfer_uuid'] );

		return array(
			'id'      => (int) $this->committedTransfer( $b, (string) $draft['transfer_uuid'] )['id'],
			'line'    => self::lineOf( $draft, $variant ),
			'source'  => $source,
			'variant' => $variant,
		);
	}

	/**
	 * Asserts that doctor names a finding matching a pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param string $pattern The pattern.
	 */
	private function assertFound( string $pattern ): void {
		$findings = $this->projectionCheck()->findings;

		$this->assertNotSame( array(), preg_grep( $pattern, $findings ), "Doctor did not name {$pattern}:\n" . implode( "\n", $findings ) );
	}
}
