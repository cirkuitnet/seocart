<?php
/**
 * Tests the query cost of the transfers list, the read of the transfers screen
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Tests\Support\Inventory\TransferTestCase;
use SEOCart\Tests\Support\QueryLog;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test plants the transfers a store's history would hold.

/**
 * A page of the transfers list costs the same few statements whatever its length or its filter: the page, its lines summed up, and the list of locations when the object cache does not hold it.
 *
 * Each page is read with the list of locations out of the object cache, the dearest case: at
 * most BUDGET statements for 10 transfers and for 100, the most a page holds, and the 100-row page
 * no more than the 10-row page. The store has 110 transfers of three lines each between the
 * default location and a second one.
 *
 * Planted violation, shown red and removed: in Transfers::listTransfers(), read each transfer's
 * lines on its own (call lineTotals() with one id per transfer). The 100-row page then costs a
 * statement per transfer.
 *
 * @since 0.2.0
 *
 * @group performance
 */
final class TransferListBudgetTest extends TransferTestCase {

	/**
	 * The most statements a page of the transfers list may cost.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const BUDGET = 6;

	/**
	 * How many transfers the store has.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const TRANSFERS = 110;

	/**
	 * Tests that a 10-row and a 100-row page each cost at most the budget, the same for each, with and without a filter.
	 *
	 * @since 0.2.0
	 */
	public function test_a_page_costs_the_same_few_statements_whatever_its_length(): void {
		$second = $this->plantLocation();

		$this->plantTransfers( $second );

		// A first read loads the user the capability check asks about; the pages measure the list alone.
		$this->transfers->listTransfers( array( 'per_page' => 1 ), $this->manager );

		$b       = $this->secondConnection();
		$filters = array(
			'no filter'  => array(),
			'a state'    => array( 'state' => 'draft' ),
			'a location' => array( 'location_uuid' => $this->uuidOf( $b, $second ) ),
		);
		$counts  = array();

		foreach ( $filters as $name => $filter ) {
			$short = $this->page( $filter + array( 'per_page' => 10 ), 10 );
			$long  = $this->page( $filter + array( 'per_page' => 100 ), 100 );

			$this->assertQueryCountAtMost( self::BUDGET, $short, "A 10-row page of the transfers list, {$name}" );
			$this->assertQueryCountAtMost( self::BUDGET, $long, "A 100-row page of the transfers list, {$name}" );
			$this->assertSame( count( $short ), count( $long ), "The 100-row page costs more than the 10-row page, {$name}: " . $long->describe() );

			$counts[] = sprintf( '%s %d and %d', $name, count( $short ), count( $long ) );
		}

		fwrite( STDOUT, sprintf( "\nThe transfers list, statements for 10 and for 100 transfers, the list of locations read each time: %s.\n", implode( '; ', $counts ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The measurement is printed for the report.
	}

	/**
	 * Reads one page of the transfers list with the list of locations out of the cache, and returns its statements.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input    The list's input.
	 * @param int                  $expected How many transfers the page must hold.
	 * @return QueryLog The statements the page sent.
	 */
	private function page( array $input, int $expected ): QueryLog {
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$answer = null;
		$log    = $this->captureQueries(
			function () use ( $input, &$answer ): void {
				$answer = $this->transfers->listTransfers( $input, $this->manager );
			}
		);

		$this->assertCount( $expected, $answer['transfers'] ?? array(), 'The page holds as many transfers as it asked for.' );
		$this->assertSame( 3, $answer['transfers'][0]['lines'] ?? null, 'Each transfer\'s lines are summed up.' );

		return $log;
	}

	/**
	 * Plants the store's transfers, drafts from the default location to a second one, each with three lines.
	 *
	 * @since 0.2.0
	 *
	 * @param int $second The second location.
	 */
	private function plantTransfers( int $second ): void {
		$transfers = $this->table( InventoryTables::TRANSFERS );
		$lines     = $this->table( InventoryTables::TRANSFER_LINES );

		for ( $index = 0; $index < self::TRANSFERS; $index++ ) {
			$this->db->execute( "INSERT INTO %i ( uuid, source_location_id, destination_location_id, kind, state, created_at, updated_at ) VALUES ( %s, %d, %d, 'manual', 'draft', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $transfers, wp_generate_uuid4(), $this->defaultLocation, $second );

			$id = $this->db->lastInsertId();

			$this->db->execute( 'INSERT INTO %i ( transfer_id, variant_id, quantity ) VALUES ( %d, 1, 2 ), ( %d, 2, 3 ), ( %d, 3, 4 )', $lines, $id, $id, $id );
		}
	}
}
