<?php
/**
 * AddRefundClaimSettlement: adds to the refund claims how a person settled one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Migrations;

use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Adds to `refund_claims` what a person who settled a claim stated, what the gateway said of the refund then, who settled it and why.
 *
 * Owns one fact: when the refund claims learn how a person settled one. No claim was settled by a
 * person before, so the four columns are NULL on every claim it finds. A site installed since has
 * them from the claims' own migration, and this one then sends no DDL.
 *
 * The store does not trade while it is outstanding: the schema gate refuses commerce writes, the
 * settling of a claim included, until the columns exist.
 *
 * @since 0.2.0
 */
final class AddRefundClaimSettlement implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261009_0002_payment_refund_claim_settlement';

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
	 * Tells whether the store may trade while the columns are being added. It may not: settling a claim writes them.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables it changes, in their end state.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The claims, their lines and the actor locks.
	 */
	public function tables(): array {
		return RefundClaimTables::all();
	}

	/**
	 * Adds the columns, through the claims' declaration: what the table lacks is added, and nothing else changes.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
