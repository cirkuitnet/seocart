<?php
/**
 * AddFulfilmentLocation: the location an order is fulfilled from
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
 * Adds `fulfilment_location_id` to `orders`, on a site whose order tables were created before it.
 *
 * Owns one fact: when an order gets the column naming the one location it is fulfilled from.
 * Every order placed before it was fulfilled from the store's one location, and the column is NULL
 * for each of them. A site installed since has the column from the order tables' own migration,
 * and this one then sends no DDL.
 *
 * The store may trade while it is outstanding: no code writes or reads the column yet.
 *
 * @since 0.2.0
 */
final class AddFulfilmentLocation implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261024_0004_order_fulfilment_location';

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
	 * Tells whether the store may trade while the column is being added. It may: nothing names the column yet.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
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
	 * Adds the column, through the table's declaration: what the table lacks is added, and nothing else changes.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
