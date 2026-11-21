<?php
/**
 * CreateOrderFieldValues: creates the table of the checkout fields' values an order was placed with
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
 * Creates `order_field_values`, on a site whose order tables were created before it.
 *
 * Owns one fact: when the orders learn to keep the order note and the values of the fields
 * extensions declare. No order held any before, so the table starts empty. A site installed since
 * has it from the order tables' own migration, and this one then sends no DDL.
 *
 * The store does not trade while it is outstanding: a placement with such a value writes the
 * table in the transaction that places the order.
 *
 * @since 0.2.0
 */
final class CreateOrderFieldValues implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261115_0002_order_field_values';

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
	 * Tells whether the store may trade while the table is being created. It may not: a placement may write it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declaration of the table it creates.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `order_field_values`.
	 */
	public function tables(): array {
		return array( OrderTables::fieldValues() );
	}

	/**
	 * Creates the table from its declaration, unless the site has it.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
