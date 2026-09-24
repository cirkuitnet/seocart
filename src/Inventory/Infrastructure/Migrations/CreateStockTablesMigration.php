<?php
/**
 * CreateStockTablesMigration: creates the four stock tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Migrations;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the stock item, ledger, hold and allocation tables, in their final shape.
 *
 * Owns one fact: when the inventory tables come into existence. The store cannot trade without
 * them: no stock can be held or adjusted, so writes stay blocked until the migration is applied.
 *
 * @since 0.1.0
 */
final class CreateStockTablesMigration implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260924_0001_inventory_stock';

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade without the tables. It may not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The four stock tables.
	 */
	public function tables(): array {
		return InventoryTables::all();
	}

	/**
	 * Creates the tables.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
