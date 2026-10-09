<?php
/**
 * AddMoneyReconciliation: adds to the orders when a person last cleared their unreconciled money, and why
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
 * Adds `money_reconciled_at` and `money_reconciliation_note` to `orders`, on a site whose order tables were created before them.
 *
 * Owns one fact: when the orders learn the time and the reason a person last cleared their
 * unreconciled money. No order was cleared before, so both are NULL on every order it finds. A
 * site installed since has the columns from the order tables' own migration, and this one then
 * sends no DDL.
 *
 * The store does not trade while it is outstanding: every refund reads `money_reconciled_at`
 * with the order it is worked out for, so the schema gate refuses commerce writes, a refund
 * included, until the columns exist.
 *
 * @since 0.2.0
 */
final class AddMoneyReconciliation implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261009_0001_order_money_reconciliation';

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
	 * Tells whether the store may trade while the columns are being added. It may not: every refund reads one of them.
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
	 * Adds the columns, through the table's declaration: what the table lacks is added, and nothing else changes.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
