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
use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\RoutingContext;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Support\Error\CodedException;
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
					$backorder => 0,
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
	 * Tests that, over every policy and availability, the router plans a unit only when a hold's claim takes it, unraced, and the figure for sale agrees.
	 *
	 * A variant whose stock is counted is sold from its units, whatever its backorder policy; an
	 * untracked one has no limit and no hold.
	 *
	 * Planted violation: in StockPolicy::sellsBeyondStock(), answer true for backorder `allow` too:
	 * the router then plans the unit the claim refuses.
	 *
	 * @since 0.2.0
	 */
	public function test_the_router_plans_a_unit_only_when_the_claim_takes_it(): void {
		$routing = new FulfilmentRouting( new LocationDirectory( new MysqlLocationRepository( $this->db ) ), $this->repository, static fn(): FulfilmentMode => FulfilmentMode::Split );

		foreach ( array( array( true, 'no' ), array( true, 'allow' ), array( false, 'no' ) ) as list( $track, $backorder ) ) {
			foreach ( array( 0, 2 ) as $onHand ) {
				$variant = self::variant();
				$case    = sprintf( 'track %d, backorder %s, %d on hand', $track ? 1 : 0, $backorder, $onHand );

				$this->plantPolicy( $variant, $track, $backorder );
				$this->db->execute( 'INSERT INTO %i ( variant_id, location_id, on_hand, updated_at ) VALUES ( %d, %d, %d, UTC_TIMESTAMP(6) )', $this->table( InventoryTables::ITEMS ), $variant, $this->defaultLocation, $onHand );

				$planned = null;

				try {
					$planned = $routing->propose( array( $variant => 1 ), RoutingContext::none() );
				} catch ( CodedException $refused ) {
					$this->assertSame( array( InventoryError::RoutingRefused, 'quantity', 0 ), array( $refused->errorCode(), $refused->details()['reason'] ?? null, $refused->details()['available'] ?? null ), $case );
				}

				$figure = $this->availability( FulfilmentMode::Split )->forSale( array( $variant ), null )[ $variant ];

				if ( null === $planned ) {
					$this->assertSame( 0, $figure, $case . ': a unit the router refuses is shown for sale.' );
					$this->assertRefusedHold( $variant, $case );

					continue;
				}

				$this->assertSame( $track ? $onHand : null, $figure, $case );
				$this->service->hold( $planned->lines, 60 );
			}
		}
	}

	/**
	 * Asserts that a hold of one unit of a variant is refused as insufficient.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variantId The variant.
	 * @param string $label     The case, for the message.
	 */
	private function assertRefusedHold( int $variantId, string $label ): void {
		try {
			$this->service->hold( array( new HoldLine( $variantId, 1 ) ), 60 );
			$this->fail( $label . ': a unit was held that the router refused.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( InventoryError::Insufficient, $refused->errorCode(), $label );
		}
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
