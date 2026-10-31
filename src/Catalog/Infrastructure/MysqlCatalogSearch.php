<?php
/**
 * MysqlCatalogSearch: the catalog's own search, over the product posts and the catalog's tables
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Application\Projection\StockSummary;
use SEOCart\Catalog\Application\Search\CatalogSearch;
use SEOCart\Catalog\Application\Search\ListingFacts;
use SEOCart\Catalog\Application\Search\SearchQuery;
use SEOCart\Catalog\Application\Search\SearchResults;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a page of product posts with one statement whatever the filters, and reads their products' facts with one more.
 *
 * Owns one fact: how the catalog's tables answer a search. PAGE walks the product posts newest
 * first, by WordPress's `type_status_date` index, and keeps a post when its locale's binding and
 * its `complete` product join it; so a post that is not published, a product that is not
 * complete and a product with no post in the locale are never listed, whoever asks. Each filter
 * is a predicate of its own behind a flag, `( flag = 0 OR … )`, which the server folds away when
 * the flag is 0, so every search is the same statement and its plan is judged once. A term filter
 * is a semi-join (`EXISTS`) on the post's term relationships, never a join, so a product in two of
 * the categories asked for is one row. An absent category filter is sent as the list `0`, which
 * keeps the statement's shape: an empty list would be NULL, a second shape. A word must be in the
 * post's title or excerpt, or begin a SKU of an enabled variant of the product's published
 * generation; it is matched with LIKE, its `%` and `_` escaped, so it matches only as typed. The
 * statement asks for one row more than the page, which says whether another page follows.
 *
 * Its joins are STRAIGHT_JOINs, so the server walks the posts first, by the index that gives the
 * order, and stops at the LIMIT. Left to choose, MySQL reads every binding of the locale, or every
 * complete product, and sorts them all to return a page.
 *
 * The walk stops at the LIMIT, so a page costs its offset and its length in rows walked. A filter
 * or a word is checked on each row the walk reaches, so a rare word or a small category walks
 * further than the rows it returns: the price of filtering without a word or facet index.
 *
 * FACTS reads, by post id, each post's product, its enabled variant of the lowest position in the
 * published generation, and its stock summary, by primary and unique keys.
 *
 * The two core tables are named with `%i` and the site's prefix; the catalog's own with their
 * tokens, through ModuleStatements. There is no cache: every page is read as the tables are.
 *
 * @since 0.2.0
 */
final class MysqlCatalogSearch implements CatalogSearch, ListingFacts {

	/**
	 * One search word: in the post's title or excerpt, or the start of a SKU of an enabled variant of the published generation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WORD = ' AND ( %d = 0 OR wp.post_title LIKE %s OR wp.post_excerpt LIKE %s'
		. ' OR EXISTS ( SELECT 1 FROM {variants} v WHERE v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1 AND v.sku LIKE %s ) )';

	/**
	 * One page of the listing, newest first, with one more row than the page asks for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE = 'SELECT wp.ID AS post_id FROM %i wp'
		. ' STRAIGHT_JOIN {product_posts} pp ON pp.post_id = wp.ID AND pp.locale = %s'
		. ' STRAIGHT_JOIN {products} p ON p.id = pp.product_id AND p.generation_state = %s'
		. ' WHERE wp.post_type = %s AND wp.post_status = %s'
		. ' AND ( %d = 0 OR p.stock_summary = %s )'
		. ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tc WHERE tc.object_id = wp.ID AND tc.term_taxonomy_id IN ({list}) ) )'
		. ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tb WHERE tb.object_id = wp.ID AND tb.term_taxonomy_id = %d ) )'
		. ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tt WHERE tt.object_id = wp.ID AND tt.term_taxonomy_id = %d ) )'
		. self::WORD
		. self::WORD
		. self::WORD
		. ' ORDER BY wp.post_date DESC, wp.ID DESC LIMIT %d OFFSET %d';

	/**
	 * The products of some posts, each with its enabled variants of the published generation, lowest position first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FACTS = 'SELECT pp.post_id, p.id AS product_id, p.uuid AS product_uuid, p.stock_summary, v.id AS variant_id, v.sku'
		. ' FROM {product_posts} pp JOIN {products} p ON p.id = pp.product_id'
		. ' LEFT JOIN {variants} v ON v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1'
		. ' WHERE pp.post_id IN ({list}) ORDER BY pp.post_id, v.position, v.id';

	/**
	 * The facets PAGE filters by.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const FACETS = array(
		CatalogSearch::FACET_CATEGORY,
		CatalogSearch::FACET_BRAND,
		CatalogSearch::FACET_TAG,
		CatalogSearch::FACET_IN_STOCK,
	);

	/**
	 * The status of a post a shopper may see.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PUBLISHED = 'publish';

	/**
	 * The connection, for the site's prefix.
	 *
	 * @since 0.2.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Sends the catalog's statements.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the search. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db         = $db;
		$this->statements = new ModuleStatements( $db, 'Catalog', array_map( static fn( TableDefinition $table ): string => $table->name(), CatalogTables::all() ) );
	}

	/**
	 * Finds one page of product posts.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query What to find.
	 * @return SearchResults The page's product posts, newest first, and whether another page follows.
	 */
	public function search( SearchQuery $query ): SearchResults {
		$relationships = $this->db->prefix() . 'term_relationships';
		$categories    = $query->categoryTermTaxonomyIds;
		$values        = array(
			$this->db->prefix() . 'posts',
			$query->locale->toString(),
			GenerationState::Complete->value,
			ProductCapabilities::POST_TYPE,
			self::PUBLISHED,
			$query->inStock ? 1 : 0,
			StockSummary::IN_STOCK,
			array() === $categories ? 0 : 1,
			$relationships,
			array() === $categories ? array( 0 ) : $categories,
			null === $query->brandTermTaxonomyId ? 0 : 1,
			$relationships,
			(int) $query->brandTermTaxonomyId,
			null === $query->tagTermTaxonomyId ? 0 : 1,
			$relationships,
			(int) $query->tagTermTaxonomyId,
		);

		for ( $slot = 0; $slot < SearchQuery::MOST_WORDS; $slot++ ) {
			array_push( $values, ...self::wordValues( $query->words[ $slot ] ?? null ) );
		}

		$values[] = $query->perPage + 1;
		$values[] = $query->offset();

		$postIds = array_map( static fn( array $row ): int => (int) $row['post_id'], $this->statements->rows( self::PAGE, ...$values ) );

		return new SearchResults( array_slice( $postIds, 0, $query->perPage ), count( $postIds ) > $query->perPage );
	}

	/**
	 * Tells whether PAGE filters by a facet: the category, the brand, the tag and the in-stock filter.
	 *
	 * @since 0.2.0
	 *
	 * @param string $facet One of CatalogSearch's FACET_* names.
	 * @return bool True for the four.
	 */
	public function supportsFacet( string $facet ): bool {
		return in_array( $facet, self::FACETS, true );
	}

	/**
	 * Reads the facts of the products of some product posts, with FACTS.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $postIds The product posts.
	 * @return array<int, array{product_id: int, product_uuid: string, variant_id: int|null, sku: string|null, stock_summary: string}> The facts by post id.
	 *
	 * @phpstan-param list<int> $postIds
	 */
	public function of( array $postIds ): array {
		$facts = array();

		if ( array() === $postIds ) {
			return $facts;
		}

		foreach ( $this->statements->rows( self::FACTS, $postIds ) as $row ) {
			$postId = (int) $row['post_id'];

			// The rows of a post come lowest position first: its first row holds the variant listed.
			if ( isset( $facts[ $postId ] ) ) {
				continue;
			}

			$facts[ $postId ] = array(
				'product_id'    => (int) $row['product_id'],
				'product_uuid'  => (string) $row['product_uuid'],
				'variant_id'    => null === $row['variant_id'] ? null : (int) $row['variant_id'],
				'sku'           => null === $row['sku'] ? null : (string) $row['sku'],
				'stock_summary' => (string) $row['stock_summary'],
			);
		}

		return $facts;
	}

	/**
	 * Returns the values of one word's predicate: its flag, its two patterns for the title and the excerpt, and its SKU prefix.
	 *
	 * The word's `%`, `_` and `\` are escaped, so each matches only itself.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $word The word, or null for a slot the search leaves empty.
	 * @return array{0: int, 1: string, 2: string, 3: string} The values, in the order WORD takes them.
	 */
	private static function wordValues( ?string $word ): array {
		global $wpdb;

		if ( null === $word ) {
			return array( 0, '', '', '' );
		}

		$escaped = $wpdb->esc_like( $word );

		return array( 1, '%' . $escaped . '%', '%' . $escaped . '%', $escaped . '%' );
	}
}
