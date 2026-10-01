<?php
/**
 * CreateRateTables: creates the currencies and exchange-rate tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Pricing\Infrastructure\PricingTables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `currencies` and `exchange_rates`, in their final shape.
 *
 * Owns one fact: when the pricing tables come into existence. Until they do, prices are offered in
 * the base currency only, which reads neither, so the store may trade while the migration is
 * pending.
 *
 * @since 0.1.0
 */
final class CreateRateTables implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20261001_0001_pricing_rates';

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
	 * Tells whether the store may trade without the tables. It may, in its base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Returns the declarations of the tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The two pricing tables.
	 */
	public function tables(): array {
		return PricingTables::all();
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
