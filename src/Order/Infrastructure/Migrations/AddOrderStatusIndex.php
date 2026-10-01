<?php
/**
 * AddOrderStatusIndex: adds the index that finds orders by status and age
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure\Migrations;

use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `status_created` to `orders`, on a site whose order tables were created before it.
 *
 * Owns one fact: when the orders get the index on their status and age. A site installed since
 * has it from the order tables' own migration, and this one then sends no DDL. The store keeps
 * trading while it runs: the index only speeds up a read of the reconciliation job.
 *
 * @since 0.1.0
 */
final class AddOrderStatusIndex implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20261001_0003_order_status_index';

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
	 * Tells whether the store may trade while the index is being added. It may.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Returns the declaration of the table it changes, in its end state.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `orders`.
	 */
	public function tables(): array {
		return array( OrderTables::orders() );
	}

	/**
	 * Adds the index, through the table's declaration: what the table lacks is added, and nothing else changes.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
