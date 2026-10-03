<?php
/**
 * CreateRefundClaimTable: creates the refund claims
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Infrastructure\Migrations;

use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `refund_claims`, in its final shape.
 *
 * Owns one fact: when the refund claims come into existence.
 *
 * @since 0.1.0
 */
final class CreateRefundClaimTable implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20261002_0001_payment_refund_claims';

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
	 * Tells whether the store may trade without the table. It may: nothing a customer does reads or writes it, only a refund does.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Returns the declaration of the table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The refund claims.
	 */
	public function tables(): array {
		return RefundClaimTables::all();
	}

	/**
	 * Creates the table.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
