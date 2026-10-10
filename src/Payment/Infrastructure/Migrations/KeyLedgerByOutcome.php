<?php
/**
 * KeyLedgerByOutcome: adds the outcome to the payment ledger's claim key
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
 * Gives the ledger's claim key the outcome as its fourth column, on a site whose ledger was created with three.
 *
 * Owns one fact: when the ledger tells a provider object's outcomes apart. A provider reuses its
 * object across a decline and a later approval, as when a shopper retries a declined card on the
 * same payment intent; keyed by provider, object and operation alone, the approval met the
 * decline's row and was answered as a duplicate, the shopper's money held with no row and no flag.
 * Every row the three columns kept apart stays apart with four, so the key is replaced in one
 * statement that cannot fail on the rows it finds, and the table is never without it. A site
 * installed since has the four columns from the ledger's own migration, and this one then sends no
 * DDL.
 *
 * The store does not trade while it is outstanding: the schema gate refuses commerce writes until
 * the key has the outcome. Against three columns, the approval of an object whose decline is
 * recorded meets the decline's row, and the four-column read of the key finds no approval to call
 * it a duplicate of: it would be answered as one all the same, with no row and no flag, the
 * shopper's money dropped. A provider delivers a refused answer again; the replacement is one
 * ALTER of the ledger, so the hold is brief.
 *
 * @since 0.2.0
 */
final class KeyLedgerByOutcome implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261010_0001_payment_ledger_outcome_key';

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
	 * Tells whether the store may trade while the key is being replaced. It may not: under three columns, an answer of a second outcome of the same provider object would be lost.
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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The ledger.
	 */
	public function tables(): array {
		return array( PaymentTables::transactions() );
	}

	/**
	 * Replaces the claim key with its declaration, in one statement; nothing is sent when the key is as declared.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->replaceUniqueKey( PaymentTables::transactions(), PaymentTables::LEDGER_KEY );
	}
}
