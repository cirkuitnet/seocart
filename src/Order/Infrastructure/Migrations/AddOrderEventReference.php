<?php
/**
 * AddOrderEventReference: adds the reference to the order events
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
 * Adds `reference` to `order_events`, on a site whose order tables were created before it.
 *
 * Owns one fact: when the order events get the reference a refund's event names it by. Every
 * event written before it is a change of status, which names nothing, so the column is NULL for
 * each of them. A site installed since has the column from the order tables' own migration, and
 * this one then sends no DDL.
 *
 * The store does not trade while it is outstanding: a refund writes its event, which names the
 * column, in the transaction that records the money the gateway gave back, so the schema gate
 * refuses commerce writes, a refund's claim included, until the column exists.
 *
 * @since 0.2.0
 */
final class AddOrderEventReference implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261004_0001_order_event_reference';

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
	 * Tells whether the store may trade while the column is being added. It may not: a refund's event names the column.
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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `order_events`.
	 */
	public function tables(): array {
		return array( OrderTables::events() );
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
