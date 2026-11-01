<?php
/**
 * CreateSearchIndex: creates the table of each product's searchable words, with its fulltext key
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
 * Creates `product_search_index`.
 *
 * Owns one fact: when the search index comes into existence on a store installed before it. The
 * store does not trade while it is outstanding: the code that runs as soon as the plugin's files
 * are replaced writes the table from every product save, and a save made while the table is
 * missing would lose its document. The table starts empty, so a search ordered by relevance lists
 * nothing until `wp seocart catalog index-products` has run; doctor counts the posts without a
 * document. A site installed since has the table from the catalog's own migration, and running
 * this one, or running it again, changes nothing.
 *
 * @since 0.2.0
 */
final class CreateSearchIndex implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = '20261031_0003_catalog_search_index';

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
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The search index.
	 */
	public function tables(): array {
		return array( CatalogTables::searchIndex() );
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
