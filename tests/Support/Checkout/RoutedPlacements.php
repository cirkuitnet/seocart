<?php
/**
 * RoutedPlacements: locations, stock at them and the routing settings, for a placement test
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Inventory\Application\InventorySettings;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\MysqlLocationRepository;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Tests\Support\Inventory\StockTables;

defined( 'ABSPATH' ) || exit;

/**
 * Plants locations and their stock, sets the store's routing, and reads what a placement held, allocated and recorded.
 *
 * For a PlacementTestCase: it uses the test's connection, tables and kernel.
 *
 * @since 0.2.0
 */
trait RoutedPlacements {

	/**
	 * Plants an active location that ships online orders, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param int  $priority      Where it is tried when an order is routed: the lowest first; the default location's is 0.
	 * @param bool $active        Optional. Whether it is active. Default true.
	 * @param bool $fulfilsOnline Optional. Whether it ships online orders; a warehouse that does not can still send
	 *                            units by transfer. Default true.
	 * @return int The location's id.
	 */
	protected function plantLocation( int $priority, bool $active = true, bool $fulfilsOnline = true ): int {
		$this->db->execute(
			'INSERT INTO %i ( uuid, name, active, fulfils_online, offers_pickup, priority, created_at, updated_at ) VALUES ( %s, %s, %d, %d, 0, %d, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )',
			$this->table( InventoryTables::LOCATIONS ),
			wp_generate_uuid4(),
			'Location ' . wp_generate_uuid4(),
			$active ? 1 : 0,
			$fulfilsOnline ? 1 : 0,
			$priority
		);

		wp_cache_delete( MysqlLocationRepository::CACHE_KEY, MysqlLocationRepository::CACHE_GROUP );

		return $this->db->lastInsertId();
	}

	/**
	 * Sets a variant's units on hand at a location, creating the item there or replacing its figure.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId  The variant, which sellable() planted.
	 * @param int $locationId The location.
	 * @param int $onHand     The units on hand.
	 */
	protected function stockAt( int $variantId, int $locationId, int $onHand ): void {
		$this->db->execute(
			'INSERT INTO %i ( variant_id, location_id, on_hand, updated_at ) VALUES ( %d, %d, %d, UTC_TIMESTAMP(6) ) ON DUPLICATE KEY UPDATE on_hand = VALUES( on_hand )',
			$this->table( InventoryTables::ITEMS ),
			$variantId,
			$locationId,
			$onHand
		);
	}

	/**
	 * Returns the store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @return int Its id.
	 */
	protected function defaultLocation(): int {
		return StockTables::defaultLocation( $this->db );
	}

	/**
	 * Sets what the store does with an order no one location can ship whole, and the extra days it tells shoppers a transfer adds.
	 *
	 * @since 0.2.0
	 *
	 * @param FulfilmentMode $mode     The mode.
	 * @param int            $leadDays Optional. The days. Default 3.
	 */
	protected function routeIn( FulfilmentMode $mode, int $leadDays = 3 ): void {
		$this->kernel->get( SettingsStore::class )->writeScalars(
			array(
				InventorySettings::MULTI_LOCATION_FULFILMENT => $mode->value,
				InventorySettings::TRANSFER_LEAD_DAYS => $leadDays,
			)
		);
	}

	/**
	 * Reads a placed order's routing facts by uuid.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order's uuid.
	 * @return array{id: int, fulfilment_location_id: int|null, transfer_lead_days: int|null} The order's id and facts.
	 */
	protected function routedOrder( string $uuid ): array {
		$row = $this->db->fetchRow( 'SELECT id, fulfilment_location_id, transfer_lead_days FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $uuid );

		$this->assertIsArray( $row, 'No order has the uuid.' );

		return array(
			'id'                     => (int) $row['id'],
			'fulfilment_location_id' => null === $row['fulfilment_location_id'] ? null : (int) $row['fulfilment_location_id'],
			'transfer_lead_days'     => null === $row['transfer_lead_days'] ? null : (int) $row['transfer_lead_days'],
		);
	}

	/**
	 * Reads an order's allocations, as `variant@location:quantity:state`, by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The allocations.
	 */
	protected function allocationsOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => $row['variant_id'] . '@' . $row['location_id'] . ':' . $row['quantity'] . ':' . $row['state'],
			$this->db->fetchAll( 'SELECT variant_id, location_id, quantity, state FROM %i WHERE order_id = %d ORDER BY variant_id, location_id', $this->table( InventoryTables::ALLOCATIONS ), $orderId )
		);
	}

	/**
	 * Reads an order's events of the fulfillment machine, as `reason:from>to:reference`, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The events.
	 */
	protected function fulfillmentEventsOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => $row['reason'] . ':' . $row['from_status'] . '>' . $row['to_status'] . ':' . $row['reference'],
			$this->db->fetchAll( "SELECT reason, from_status, to_status, reference FROM %i WHERE order_id = %d AND machine = 'fulfillment' ORDER BY id", $this->table( OrderTables::EVENTS ), $orderId )
		);
	}

	/**
	 * Returns a location's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location.
	 * @return string Its uuid.
	 */
	protected function uuidOfLocation( int $locationId ): string {
		return (string) $this->db->fetchValue( 'SELECT uuid FROM %i WHERE id = %d', $this->table( InventoryTables::LOCATIONS ), $locationId );
	}

	/**
	 * Reads an order's transfers, as `state:source>destination`, by id.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The transfers.
	 */
	protected function transfersOf( int $orderId ): array {
		return array_map(
			static fn( array $row ): string => $row['state'] . ':' . $row['source_location_id'] . '>' . $row['destination_location_id'],
			$this->db->fetchAll( 'SELECT state, source_location_id, destination_location_id FROM %i WHERE order_id = %d ORDER BY id', $this->table( InventoryTables::TRANSFERS ), $orderId )
		);
	}
}
