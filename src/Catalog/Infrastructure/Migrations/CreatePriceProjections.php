<?php
/**
 * CreatePriceProjections: creates the table of the price each product sorts by in each currency
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Migrations;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `product_price_projections`.
 *
 * Owns one fact: when the price projections come into existence on a store installed before
 * them. The store does not trade while it is outstanding: the code that runs as soon as the
 * plugin's files are replaced writes the table from every product save, and a save made while the
 * table is missing would lose its projection. The table starts empty, so a price order lists
 * nothing until `wp seocart catalog project-prices` has run; doctor counts the products without a
 * row. A site installed since has the table from the catalog's own migration, and running this
 * one, or running it again, changes nothing.
 *
 * @since 0.2.0
 */
final class CreatePriceProjections implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261031_0002_catalog_price_projections';

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
	 * Tells whether the store may trade without the table. It may not: every product save writes it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declaration of the table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The price projections.
	 */
	public function tables(): array {
		return array( CatalogTables::priceProjections() );
	}

	/**
	 * Creates the table.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
