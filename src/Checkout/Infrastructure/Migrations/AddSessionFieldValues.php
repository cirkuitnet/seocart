<?php
/**
 * AddSessionFieldValues: adds to the checkout sessions the values of the checkout fields that are no address member
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure\Migrations;

use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `field_values_json` to `checkout_sessions`, on a site whose checkout tables were created before it.
 *
 * Owns one fact: when a checkout session learns to keep the order note and the values of the
 * fields extensions declare. No session held any before, so the column is NULL on every session
 * it finds. A site installed since has it from the checkout tables' own migration, and this one
 * then sends no DDL.
 *
 * The store does not trade while it is outstanding: the session write and the placement's read
 * of a session both name the column.
 *
 * @since 0.2.0
 */
final class AddSessionFieldValues implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261115_0001_checkout_session_fields';

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
	 * Tells whether the store may trade while the column is being added. It may not: every session write and every placement names it.
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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `checkout_sessions`.
	 */
	public function tables(): array {
		return array( CheckoutTables::sessions() );
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
