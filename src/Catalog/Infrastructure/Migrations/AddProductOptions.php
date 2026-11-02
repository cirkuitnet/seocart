<?php
/**
 * AddProductOptions: creates the option tables and adds the variant's label
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
 * Creates `option_axes`, `option_values` and `variant_option_values`, and adds `label` to `variants`.
 *
 * Owns one fact: when a store installed before product options gets their tables and the variant's
 * label. The store does not trade while it is outstanding: every read of a product's variants
 * reads the label, and every product deletion removes the option rows. No data moves: a product
 * saved before keeps its one variant, whose combination is the empty one, with no label and no
 * axis. A site installed since has the tables and the column from the catalog's own migration, and
 * running this one, or running it again, sends no DDL.
 *
 * @since 0.2.0
 */
final class AddProductOptions implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261101_0001_catalog_options';

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
	 * Tells whether the store may trade while the step is outstanding. It may not: every variant read names the label.
	 *
	 * @since 0.2.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables the step creates or changes, in their end state.
	 *
	 * @since 0.2.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The three option tables, then `variants`.
	 */
	public function tables(): array {
		return array( CatalogTables::optionAxes(), CatalogTables::optionValues(), CatalogTables::variantOptionValues(), CatalogTables::variants() );
	}

	/**
	 * Creates the three tables, then adds the label to `variants` unless it has one.
	 *
	 * @since 0.2.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( array( CatalogTables::optionAxes(), CatalogTables::optionValues(), CatalogTables::variantOptionValues() ) );
		$operations->addColumn( CatalogTables::VARIANTS, CatalogTables::variantLabel() );
	}
}
