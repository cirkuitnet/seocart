<?php
/**
 * AddOrderRoutingSnapshot: the days a transfer was said to add, and the orders fulfilled from a location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure\Migrations;

use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `transfer_lead_days` and the `fulfilment_location` index to `orders`, on a site whose order tables were created before them.
 *
 * Owns one fact: when an order gets the column that keeps the extra days its shopper was told a
 * transfer adds, and the index that finds the orders fulfilled from a location. Every order
 * placed before it needed no transfer that anyone was told of, and the column is NULL for each of
 * them. A site installed since has both from the order tables' own migration, and this one then
 * sends no DDL.
 *
 * The store may not trade while it is outstanding: placing an order writes the column.
 *
 * @since 0.2.0
 */
final class AddOrderRoutingSnapshot implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261031_0004_order_routing_snapshot';

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
	 * Tells whether the store may trade while the column is being added. It may not: an order's placement names it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declaration of the table it changes, in its end state.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `orders`.
	 */
	public function tables(): array {
		return array( OrderTables::orders() );
	}

	/**
	 * Adds the column and the index, through the table's declaration: what the table lacks is added, and nothing else changes.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
