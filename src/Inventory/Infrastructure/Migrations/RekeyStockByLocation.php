<?php
/**
 * RekeyStockByLocation: the four stock tables keyed by the variant and the location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Migrations;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exception tells the person running the upgrade which statements to run; it is never HTML.

/**
 * Makes the location of every stock row NOT NULL, keys the four stock tables by it, and drops the policy columns from the items.
 *
 * Owns one fact: the last step of keeping stock per location. One ALTER TABLE per table, each
 * with exactly the clauses of CLAUSES, sent only when the table differs from its declaration and
 * verified against it afterwards: the items are keyed by variant and location and lose `track`,
 * `backorder_policy` and `low_stock_threshold`, which SeedDefaultLocation copied to
 * `stock_policies`, and the index over two of them goes with them; the holds, allocations and
 * ledger get their keys by variant and location, and the ledger its key by transfer. Then the ten
 * tables are handed to the declarations once more, so dbDelta adds anything left, and all ten are
 * verified. On a site installed since, every table matches its declaration already and nothing is
 * sent.
 *
 * A limitation of the chain: a request still running the previous version of the plugin while the
 * chain runs may write a stock row after SeedDefaultLocation has passed it, with no location and,
 * for a new item, no policy row. The ALTER is sent in strict mode, so the server refuses it whole
 * rather than store such a row at no location, the table is left as it was and the store stays
 * closed. The error names the statements a person runs: for the items, the copy of the missing
 * policies from the columns the items still have, then the update that puts every row without a
 * location at the default location; for the other tables, that update. Then `wp seocart migrate`
 * runs this migration again.
 *
 * @since 0.2.0
 */
final class RekeyStockByLocation implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261024_0003_inventory_location_keys';

	/**
	 * Each table's ALTER clauses, in the order they are sent: forms MySQL and MariaDB both accept.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, list<string>>
	 */
	public const CLAUSES = array(
		InventoryTables::ITEMS       => array(
			'MODIFY location_id bigint unsigned NOT NULL',
			'DROP PRIMARY KEY',
			'ADD PRIMARY KEY (variant_id, location_id)',
			'DROP COLUMN track',
			'DROP COLUMN backorder_policy',
			'DROP COLUMN low_stock_threshold',
			'ADD KEY location_on_hand (location_id, on_hand)',
		),
		InventoryTables::HOLDS       => array(
			'MODIFY location_id bigint unsigned NOT NULL',
			'DROP INDEX variant_expires',
			'ADD KEY item_expires (variant_id, location_id, expires_at)',
		),
		InventoryTables::ALLOCATIONS => array(
			'MODIFY location_id bigint unsigned NOT NULL',
			'DROP INDEX order_line_allocation',
			'ADD UNIQUE KEY order_line_allocation (order_line_id, variant_id, location_id)',
			'DROP INDEX variant_state',
			'ADD KEY item_state (variant_id, location_id, state)',
			'ADD KEY location_state (location_id, state)',
		),
		InventoryTables::LEDGER      => array(
			'MODIFY location_id bigint unsigned NOT NULL',
			'DROP INDEX variant_created',
			'ADD KEY item_created (variant_id, location_id, created_at)',
			'ADD KEY location_created (location_id, created_at)',
			'ADD KEY transfer_id (transfer_id)',
		),
	);

	/**
	 * The statement that copies the policies of items written without one, from the columns the items still have, as the error names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const COPY_REMEDY = 'INSERT INTO `%1$s` ( variant_id, track, backorder_policy, low_stock_threshold, updated_at ) SELECT variant_id, track, backorder_policy, low_stock_threshold, UTC_TIMESTAMP(6) FROM `%2$s` WHERE location_id IS NULL ON DUPLICATE KEY UPDATE variant_id = `%1$s`.variant_id;';

	/**
	 * The statement that puts a table's rows without a location at the default location, as the error names it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const FILL_REMEDY = 'UPDATE `%1$s` SET location_id = ( SELECT id FROM `%2$s` WHERE is_default = 1 ) WHERE location_id IS NULL;';

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
	 * Tells whether the store may trade while the chain is outstanding. It may not: the code that runs now reads the tables keyed by location.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the ten inventory tables, in their end state.
	 *
	 * @since 0.2.0
	 *
	 * @return list<TableDefinition> Every inventory table.
	 */
	public function tables(): array {
		return InventoryTables::all();
	}

	/**
	 * Alters the four stock tables, each in one statement, then hands the ten tables to their declarations.
	 *
	 * @since 0.2.0
	 *
	 * @throws \RuntimeException When the server refuses a table's ALTER, naming the statements a person runs before the migration runs again.
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$declarations = array();

		foreach ( InventoryTables::all() as $declaration ) {
			$declarations[ $declaration->name() ] = $declaration;
		}

		foreach ( self::CLAUSES as $table => $clauses ) {
			try {
				$operations->alterTable( $declarations[ $table ], ...$clauses );
			} catch ( QueryFailed $refused ) {
				throw new \RuntimeException( self::refusal( $operations, $table ), 0, $refused );
			}
		}

		$operations->createTables( $this->tables() );
	}

	/**
	 * Says why a table's ALTER was refused and what a person runs, with the site's table names.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations, which know the site's table names.
	 * @param string           $table      The table whose ALTER was refused.
	 * @return string The message.
	 */
	private static function refusal( SchemaOperations $operations, string $table ): string {
		$locations = $operations->tableName( InventoryTables::LOCATIONS );
		$remedy    = sprintf( self::FILL_REMEDY, $operations->tableName( $table ), $locations );

		if ( InventoryTables::ITEMS === $table ) {
			$remedy = sprintf( self::COPY_REMEDY, $operations->tableName( InventoryTables::POLICIES ), $operations->tableName( $table ) ) . ' ' . $remedy;
		}

		return sprintf(
			'The server refused to key %1$s by location, and left it as it was. The likely reason is a row without a location, written by a request that still ran the previous version of the plugin while this upgrade ran. Run, in this order: %2$s Then run `wp seocart migrate` again.',
			$operations->tableName( $table ),
			$remedy
		);
	}
}
