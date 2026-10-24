<?php
/**
 * Tests the figure of each variant a shopper may be shown, per mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Application\Availability;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Tests\Support\Inventory\StockTestCase;

/**
 * The figure counts the active locations that ship online orders: their sum under `split` and `transfer`, the most one of them has under `refuse`, each at no fewer than zero; no limit for a variant whose stock is not counted or that may be backordered; nothing for one with no stock policy; in one read of stock.
 *
 * Planted violations, each shown red and removed: in LocationDirectory::fulfilling(), keep the
 * deactivated locations: their units are counted and the figure is 17; in
 * Availability::forSale(), give the most one location has under `transfer` as under `refuse`: the
 * product shows 4 where the order can take 7; in MysqlStockRepository::SALE_FIGURES, sum the rows
 * without GREATEST(): the row below zero takes the other location's units away.
 *
 * @since 0.2.0
 */
final class AvailabilityTest extends StockTestCase {

	/**
	 * Tests the sum under split and transfer and the largest under refuse, over the active fulfilling locations only, in one read of stock.
	 *
	 * @since 0.2.0
	 */
	public function test_the_figure_is_the_sum_or_the_largest_of_the_active_fulfilling_locations(): void {
		$variant   = self::variant();
		$second    = $this->plantLocation();
		$inactive  = $this->plantLocation( 2, false );
		$shipsNone = $this->plantLocation( 3, true, false );

		$this->plantItem( $variant, 3 );
		$this->plantItem( $variant, 4, 0, 0, true, $second );
		$this->plantItem( $variant, 10, 0, 0, true, $inactive );
		$this->plantItem( $variant, 10, 0, 0, true, $shipsNone );

		foreach ( array(
			'split'    => 7,
			'transfer' => 7,
			'refuse'   => 4,
		) as $mode => $figure ) {
			$log = $this->captureQueries(
				function () use ( $variant, $mode, $figure ): void {
					$this->assertSame( array( $variant => $figure ), $this->availability( FulfilmentMode::from( $mode ) )->forSale( array( $variant ), null ), $mode );
				}
			);

			$this->assertQueryCount( 1, $log->forTable( $this->table( InventoryTables::POLICIES ) ), 'Reads of stock for one figure, in ' . $mode . ' mode' );
		}
	}

	/**
	 * Tests that a variant with no limit has no figure, one with no policy has nothing, a location below zero counts as nothing, and a store with no fulfilling location has nothing for sale.
	 *
	 * @since 0.2.0
	 */
	public function test_unlimited_missing_and_negative_figures(): void {
		$untracked = self::variant();
		$backorder = self::variant();
		$short     = self::variant();
		$missing   = self::variant();
		$second    = $this->plantLocation();

		$this->plantItem( $untracked, 0, 0, 0, false );
		$this->plantPolicy( $backorder, true, 'allow' );
		$this->plantItem( $backorder );
		$this->plantItem( $short, 1, 3 );
		$this->plantItem( $short, 2, 0, 0, true, $second );

		foreach ( array( FulfilmentMode::Split, FulfilmentMode::Refuse ) as $mode ) {
			$this->assertSame(
				array(
					$untracked => null,
					$backorder => null,
					$short     => 2,
					$missing   => 0,
				),
				$this->availability( $mode )->forSale( array( $untracked, $backorder, $short, $missing ), null ),
				$mode->value
			);
		}

		$this->db->execute( 'UPDATE %i SET fulfils_online = 0', $this->table( InventoryTables::LOCATIONS ) );
		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		$this->assertSame( array( $short => 0 ), $this->availability( FulfilmentMode::Split )->forSale( array( $short ), null ), 'No location ships online orders.' );
	}

	/**
	 * Builds the figure's service over the test's connection, in a mode.
	 *
	 * @since 0.2.0
	 *
	 * @param FulfilmentMode $mode The store's mode.
	 * @return Availability The service.
	 */
	private function availability( FulfilmentMode $mode ): Availability {
		return new Availability( new LocationDirectory( new MysqlLocationRepository( $this->db ) ), $this->repository, static fn(): FulfilmentMode => $mode );
	}
}
