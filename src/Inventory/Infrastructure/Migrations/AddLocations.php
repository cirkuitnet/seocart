<?php
/**
 * AddLocations: the location, policy and transfer tables, and a location column on the four stock tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Migrations;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the six tables of stock kept per location, and gives the four stock tables a location column for the next migration to fill.
 *
 * Owns one fact: the first step of keeping stock per location. It creates `stock_policies`,
 * `locations`, `location_markets`, `location_pickup_hours`, `stock_transfers` and
 * `stock_transfer_lines` in their final shape, adds `location_id` to `stock_items`, `stock_holds`,
 * `stock_allocations` and `stock_ledger` nullable, so no row needs a value yet, and adds
 * `stock_ledger.transfer_id` as declared. SeedDefaultLocation fills the location in, and
 * RekeyStockByLocation makes it NOT NULL and keys the tables by it, which is when the four tables
 * reach their declared shape and are verified; this migration verifies the six it creates.
 *
 * On a site installed since, the stock tables' own migration created all ten tables in their
 * final shape: the location column is found, whatever its nullability, and nothing is sent.
 *
 * The store does not trade until the whole chain is applied: the code that runs now holds and
 * allocates stock at a location.
 *
 * @since 0.2.0
 */
final class AddLocations implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261024_0001_inventory_locations';

	/**
	 * The stock tables that gain the location column.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const LOCATED = array( InventoryTables::ITEMS, InventoryTables::HOLDS, InventoryTables::ALLOCATIONS, InventoryTables::LEDGER );

	/**
	 * Returns the id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade while the chain is outstanding. It may not: the code that runs now names a location on every stock row.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables it creates, in their end state; the four tables it adds a column to reach theirs two migrations later.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The policy, location, location market, pickup hour,
	 *                                                                  transfer and transfer line tables.
	 */
	public function tables(): array {
		return array(
			InventoryTables::policies(),
			InventoryTables::locations(),
			InventoryTables::locationMarkets(),
			InventoryTables::pickupHours(),
			InventoryTables::transfers(),
			InventoryTables::transferLines(),
		);
	}

	/**
	 * Creates the six tables, then adds the location column to the four stock tables and the transfer column to the ledger, each only when it is not there.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );

		foreach ( self::LOCATED as $table ) {
			$operations->addColumn( $table, self::unfilledLocation() );
		}

		$operations->addColumn( InventoryTables::LEDGER, InventoryTables::transferId() );
	}

	/**
	 * Declares the location column as this step adds it: nullable, until the next migration has filled every row.
	 *
	 * @since 0.2.0
	 *
	 * @return ColumnSpec The column.
	 */
	private static function unfilledLocation(): ColumnSpec {
		return new ColumnSpec( 'location_id', 'bigint unsigned', Classification::Public, 'The location of the row; NULL only until the default location is filled in.', nullable: true );
	}
}
