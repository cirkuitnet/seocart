<?php
/**
 * PlantsStock: locations, stock policies and stock items planted directly, for a test with the inventory tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Inventory;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The fixtures plant stock rows directly.

/**
 * Plants locations, stock policies and stock items row by row, with no ledger entry.
 *
 * Owns one fact: how a test plants stock. It needs the test's connection (`$this->db`), the
 * store's default location (`$this->defaultLocation`, which StockTables::create() returns) and
 * `table()`, as StockTestCase and CartTestCase give them.
 *
 * @since 0.2.0
 */
trait PlantsStock {

	/**
	 * The last location number handed out, for the names of planted locations.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private static int $lastLocation = 0;

	/**
	 * Plants a location beside the default one, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $priority      Optional. Its routing priority. Default 1, after the default location.
	 * @param bool $active        Optional. Whether it is active. Default true.
	 * @param bool $fulfilsOnline Optional. Whether it ships online orders. Default true.
	 * @param bool $offersPickup  Optional. Whether it offers pickup. Default false.
	 * @return int The location's id.
	 */
	protected function plantLocation( int $priority = 1, bool $active = true, bool $fulfilsOnline = true, bool $offersPickup = false ): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, name, active, fulfils_online, offers_pickup, priority, created_at, updated_at ) VALUES ( %s, %s, %d, %d, %d, %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::LOCATIONS ),
			wp_generate_uuid4(),
			'Location ' . ( ++self::$lastLocation ),
			$active ? 1 : 0,
			$fulfilsOnline ? 1 : 0,
			$offersPickup ? 1 : 0,
			$priority
		);

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		return $this->db->lastInsertId();
	}

	/**
	 * Plants an item row directly, with its variant's policy row when the variant has none yet, and no ledger entry.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location, and the policy row.
	 *
	 * @param int      $variantId  The variant.
	 * @param int      $onHand     Optional. Units on hand. Default 0.
	 * @param int      $allocated  Optional. Units allocated. Default 0.
	 * @param int      $held       Optional. Units held, without rows. Default 0.
	 * @param bool     $track      Optional. Whether stock is counted, when the policy row is planted. Default true.
	 * @param int|null $locationId Optional. The location. Default null, the default location.
	 */
	protected function plantItem( int $variantId, int $onHand = 0, int $allocated = 0, int $held = 0, bool $track = true, ?int $locationId = null ): void {
		$this->plantPolicy( $variantId, $track );
		$this->db->execute(
			'INSERT INTO %i ( variant_id, location_id, on_hand, allocated, held, updated_at ) VALUES ( %d, %d, %d, %d, %d, UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::ITEMS ),
			$variantId,
			$locationId ?? $this->defaultLocation,
			$onHand,
			$allocated,
			$held
		);
	}

	/**
	 * Plants a variant's policy row, unless it has one.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $variantId The variant.
	 * @param bool   $track     Optional. Whether stock is counted. Default true.
	 * @param string $backorder Optional. The backorder policy. Default `no`.
	 */
	protected function plantPolicy( int $variantId, bool $track = true, string $backorder = 'no' ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, track, backorder_policy, updated_at ) VALUES ( %d, %d, %s, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE variant_id = variant_id',
			$this->table( InventoryTables::POLICIES ),
			$variantId,
			$track ? 1 : 0,
			$backorder
		);
	}
}
