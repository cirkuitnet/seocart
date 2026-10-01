<?php
/**
 * CreatePaymentTables: creates the payment tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Migrations;

use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the payment intents and the payment ledger, in their final shape.
 *
 * Owns one fact: when the payment tables come into existence. An order cannot be placed without
 * its intent, so writes stay blocked until the migration is applied.
 *
 * @since 0.1.0
 */
final class CreatePaymentTables implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260926_0002_payment_intents';

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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The two payment tables.
	 */
	public function tables(): array {
		return PaymentTables::all();
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
