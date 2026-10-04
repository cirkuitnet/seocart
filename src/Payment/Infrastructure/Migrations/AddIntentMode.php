<?php
/**
 * AddIntentMode: adds the mode to the payment intents, on a site whose intents were created before it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Migrations;

use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds `mode` to `payment_intents`, on a site whose intents were created before it.
 *
 * Owns one fact: when the intents get the provider mode they were created in. Every intent made
 * before it was made through the stand-in gateway, which has only the test mode, so the column's
 * default, `test`, is what each of them was. A site installed since has the column from the
 * payment tables' own migration, and this one then sends no DDL.
 *
 * The store does not trade while it is outstanding: every statement that creates or reads an
 * intent names the column, so the schema gate refuses commerce writes, a placement included,
 * until the column exists. Reads open no transaction and are not refused: a read that names the
 * column, the reconciliation job's page of waiting intents, fails with the database's error and
 * writes nothing, and the job's next run, once the column is there, settles what waits.
 *
 * @since 0.2.0
 */
final class AddIntentMode implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261003_0001_payment_intent_mode';

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
	 * Tells whether the store may trade while the column is being added. It may not: the payment statements name the column.
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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `payment_intents`.
	 */
	public function tables(): array {
		return array( PaymentTables::intents() );
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
