<?php
/**
 * AddRefundClaimRequest: adds the request a refund claim was made for, its key, and the two tables beside the claims
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
 * Adds to `refund_claims` the request each claim was made for (its base share, the shipping, the reason and the note), the caller's idempotency key with the request's fingerprint, and the index a user's daily refunds are added up by; and creates `refund_claim_lines` and `refund_actor_locks`.
 *
 * Owns one fact: when the refund claims learn what was asked and by which key. A site installed
 * since has all of it from the claims' own migration, and this one then sends no DDL. A claim made
 * before it has no key and no lines; its new figures are the column types' zero, and only a
 * development site can hold one, since no release made claims.
 *
 * The store does not trade while it is outstanding: a refund's claim names the new columns, so
 * the schema gate refuses commerce writes, a refund's claim included, until they exist.
 *
 * @since 0.2.0
 */
final class AddRefundClaimRequest implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261004_0002_payment_refund_claim_request';

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
	 * Tells whether the store may trade while the columns and tables are being added. It may not: a refund's claim names them.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables it changes and creates, in their end state.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The claims, their lines and the actor locks.
	 */
	public function tables(): array {
		return RefundClaimTables::all();
	}

	/**
	 * Adds the columns and the index, and creates the two tables, through their declarations: what the site lacks is added, and nothing else changes.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
