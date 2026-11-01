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
use SEOCart\Catalog\Application\Search\ProductIndexDocument;
use SEOCart\Catalog\Application\Search\SearchDocuments;
use SEOCart\Catalog\Application\Search\SearchQuery;
use SEOCart\Catalog\Application\Search\SearchResults;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a page of product posts with one statement per order whatever the filters, and reads their products' facts with one more.
 *
 * Owns one fact: how the catalog's tables answer a search. Each order is one complete statement,
 * chosen by the query's order and never joined at run time, built from the same filter fragments,
 * so every search in an order is the same statement and its plan is judged once:
 * - PAGE, newest first, walks the product posts by WordPress's `type_status_date` index;
 * - PAGE_PRICE_ASC and PAGE_PRICE_DESC walk the currency's sort prices by the projection's
 *   covering key `currency_price`, forward or backward, the price range as the walk's own range;
 * - PAGE_RELEVANCE reads the locale's documents that hold every word by the word index's fulltext
 *   key, best match first. Each word is reduced to its runs of letters and digits, each run of
 *   SearchQuery::WORD_MIN or more that is not one of SearchQuery::STOPWORDS required and matched as a prefix
 *   (booleanSearch()), so no operator typed in a search reaches the index; a search left with no run matches
 *   nothing, without a statement.
 *
 * Every order keeps a post only when its locale's binding and its `complete` product join it, so a
 * post that is not published, a product that is not complete and a product with no post in the
 * locale are never listed, whoever asks; a price order also leaves out a product without a sort
 * price in its currency, which it cannot place, and a search by relevance one without a document. Each filter is a predicate of its own behind a
 * flag, `( flag = 0 OR … )`, which the server folds away when the flag is 0. A term filter is a
 * semi-join (`EXISTS`) on the post's term relationships, never a join, so a product in two of the
 * categories asked for is one row. An absent category filter is sent as the list `0`, which keeps
 * the statement's shape: an empty list would be NULL, a second shape. A word must be in the post's
 * title or excerpt, or begin a SKU of an enabled variant of the product's published generation; it
 * is matched with LIKE, its `%` and `_` escaped, so it matches only as typed. A price range is a
 * semi-join on the product's sort price in the currency, except in a price order, whose walk is the
 * range, sent as 0 to PHP_INT_MAX when absent, so the statement keeps its shape. Each statement asks
 * for one row more than the page, which says whether another page follows.
 *
 * Its joins are STRAIGHT_JOINs, so the server walks the table that gives the order first, by the
 * index that gives it, and stops at the LIMIT. Left to choose, MySQL reads every binding of the
 * locale, or every complete product, and sorts them all to return a page. A filter or a word is
 * checked on each row the walk reaches, so a rare word or a small category walks further than the
 * rows it returns.
 *
 * FACTS reads, by post id, each post's product, its enabled variant of the lowest position in the
 * published generation, and its stock summary, by primary and unique keys.
 *
 * It also keeps the word index: documents() reads the words of some products' posts and variants,
 * and index() and delete() store and remove their documents, a document written only when its
 * words changed, byte for byte.
 *
 * The two core tables are named with `%i` and the site's prefix; the catalog's own with their
 * tokens, through ModuleStatements. There is no cache: every page is read as the tables are.
 *
 * @since 0.2.0
 */
final class MysqlCatalogSearch implements CatalogSearch, ListingFacts, SearchDocuments {

	/**
	 * The in-stock filter, behind its flag.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const STOCK_FILTER = ' AND ( %d = 0 OR p.stock_summary = %s )';

	/**
	 * The category, brand and tag filters, each a semi-join on the post's term relationships behind its flag.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TERM_FILTERS = ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tc WHERE tc.object_id = wp.ID AND tc.term_taxonomy_id IN ({list}) ) )'
		. ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tb WHERE tb.object_id = wp.ID AND tb.term_taxonomy_id = %d ) )'
		. ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM %i tt WHERE tt.object_id = wp.ID AND tt.term_taxonomy_id = %d ) )';

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
	 * The search's words, a slot for each word a search may hold.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WORDS = self::WORD . self::WORD . self::WORD;

	/**
	 * The price range, behind its flag: a semi-join on the product's sort price in the currency, by `product_currency`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PRICE_FILTER = ' AND ( %d = 0 OR EXISTS ( SELECT 1 FROM {product_price_projections} pr WHERE pr.product_id = p.id AND pr.currency = %s AND pr.sort_price_minor BETWEEN %d AND %d ) )';

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
		. self::STOCK_FILTER
		. self::TERM_FILTERS
		. self::PRICE_FILTER
		. self::WORDS
		. ' ORDER BY wp.post_date DESC, wp.ID DESC LIMIT %d OFFSET %d';

	/**
	 * One page of the listing, the lowest price first, ties by product, with one more row than the page asks for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_PRICE_ASC = 'SELECT wp.ID AS post_id FROM {product_price_projections} pr'
		. ' STRAIGHT_JOIN {products} p ON p.id = pr.product_id AND p.generation_state = %s'
		. ' STRAIGHT_JOIN {product_posts} pp ON pp.product_id = p.id AND pp.locale = %s'
		. ' STRAIGHT_JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s AND wp.post_status = %s'
		. ' WHERE pr.currency = %s AND pr.sort_price_minor BETWEEN %d AND %d'
		. self::STOCK_FILTER
		. self::TERM_FILTERS
		. self::WORDS
		. ' ORDER BY pr.sort_price_minor ASC, pr.product_id ASC LIMIT %d OFFSET %d';

	/**
	 * One page of the listing, the highest price first, ties by product, with one more row than the page asks for: PAGE_PRICE_ASC walked backward.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_PRICE_DESC = 'SELECT wp.ID AS post_id FROM {product_price_projections} pr'
		. ' STRAIGHT_JOIN {products} p ON p.id = pr.product_id AND p.generation_state = %s'
		. ' STRAIGHT_JOIN {product_posts} pp ON pp.product_id = p.id AND pp.locale = %s'
		. ' STRAIGHT_JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s AND wp.post_status = %s'
		. ' WHERE pr.currency = %s AND pr.sort_price_minor BETWEEN %d AND %d'
		. self::STOCK_FILTER
		. self::TERM_FILTERS
		. self::WORDS
		. ' ORDER BY pr.sort_price_minor DESC, pr.product_id DESC LIMIT %d OFFSET %d';

	/**
	 * One page of a search, the product whose document matches its words best first, ties by the product, the last first, with one more row than the page asks for.
	 *
	 * It walks the word index by the document's fulltext key, so it reads only the documents that
	 * hold every word; the matched documents are then sorted by their score, the one sort a
	 * paginated listing accepts here, bounded by the words: a word nearly every document holds ranks
	 * the whole catalog, as WordPress's own search does.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PAGE_RELEVANCE = 'SELECT wp.ID AS post_id FROM {product_search_index} si'
		. ' STRAIGHT_JOIN {product_posts} pp ON pp.product_id = si.product_id AND pp.locale = si.locale'
		. ' STRAIGHT_JOIN %i wp ON wp.ID = pp.post_id AND wp.post_type = %s AND wp.post_status = %s'
		. ' STRAIGHT_JOIN {products} p ON p.id = si.product_id AND p.generation_state = %s'
		. ' WHERE si.locale = %s AND MATCH( si.search_document ) AGAINST( %s IN BOOLEAN MODE )'
		. self::STOCK_FILTER
		. self::TERM_FILTERS
		. self::PRICE_FILTER
		. ' ORDER BY MATCH( si.search_document ) AGAINST( %s IN BOOLEAN MODE ) DESC, si.product_id DESC LIMIT %d OFFSET %d';

	/**
	 * The posts that present some products, each with its locale, by `product_locale`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BINDINGS_OF_PRODUCTS = 'SELECT product_id, post_id, locale FROM {product_posts} WHERE product_id IN ({list}) ORDER BY product_id, locale';

	/**
	 * The titles and excerpts of some posts, by WordPress's primary key.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const POSTS_OF_IDS = 'SELECT ID AS post_id, post_title, post_excerpt FROM %i WHERE ID IN ({list})';

	/**
	 * The SKUs of some products' enabled variants of the published generation, lowest position first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LISTED_SKUS = 'SELECT v.product_id, v.sku FROM {products} p'
		. ' JOIN {variants} v ON v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1'
		. ' WHERE p.id IN ({list}) ORDER BY v.product_id, v.position, v.id';

	/**
	 * Some products' stored documents.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DOCUMENTS_OF_PRODUCTS = 'SELECT product_id, locale, search_document FROM {product_search_index} WHERE product_id IN ({list})';

	/**
	 * A product's document in a locale it has none in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_DOCUMENT = 'INSERT INTO {product_search_index} ( product_id, locale, search_document, created_at, updated_at ) VALUES ( %d, %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP(6) )';

	/**
	 * A stored document's new words, written only when they differ, byte for byte, from what it holds.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_DOCUMENT = 'UPDATE {product_search_index} SET search_document = %s, updated_at = UTC_TIMESTAMP(6) WHERE product_id = %d AND locale = %s AND search_document <> CAST( %s AS BINARY )';

	/**
	 * A product's document in a locale it has no post in any more.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_DOCUMENT = 'DELETE FROM {product_search_index} WHERE product_id = %d AND locale = %s';

	/**
	 * Every document of a product.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_DOCUMENTS_OF_PRODUCT = 'DELETE FROM {product_search_index} WHERE product_id = %d';

	/**
	 * The posts of complete products with an enabled variant that have no document.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNINDEXED = 'SELECT COUNT(*) AS bindings FROM {product_posts} pp'
		. ' JOIN {products} p ON p.id = pp.product_id AND p.generation_state = %s AND p.enabled_variant_count > 0'
		. ' LEFT JOIN {product_search_index} si ON si.product_id = pp.product_id AND si.locale = pp.locale'
		. ' WHERE si.product_id IS NULL';

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
	 * The facets the statements filter by.
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
		CatalogSearch::FACET_PRICE,
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
	 * Finds one page of product posts, with the statement of the query's order.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query What to find.
	 * @return SearchResults The page's product posts, in the query's order, and whether another page follows.
	 */
	public function search( SearchQuery $query ): SearchResults {
		$against = SearchQuery::RELEVANCE === $query->orderBy ? self::booleanSearch( $query->words ) : '';

		// A search whose every word is too short or a stopword, so in no document, matches nothing.
		if ( SearchQuery::RELEVANCE === $query->orderBy && '' === $against ) {
			return new SearchResults( array(), false );
		}

		list( $statement, $values ) = match ( $query->orderBy ) {
			SearchQuery::PRICE_ASC => array( self::PAGE_PRICE_ASC, $this->priceValues( $query ) ),
			SearchQuery::PRICE_DESC => array( self::PAGE_PRICE_DESC, $this->priceValues( $query ) ),
			SearchQuery::RELEVANCE => array( self::PAGE_RELEVANCE, $this->relevanceValues( $query, $against ) ),
			default => array( self::PAGE, $this->newestValues( $query ) ),
		};

		$values[] = $query->perPage + 1;
		$values[] = $query->offset();

		$postIds = array_map( static fn( array $row ): int => (int) $row['post_id'], $this->statements->rows( $statement, ...$values ) );

		return new SearchResults( array_slice( $postIds, 0, $query->perPage ), count( $postIds ) > $query->perPage );
	}

	/**
	 * Tells whether the statements filter by a facet: the category, the brand, the tag, the in-stock filter and the price range.
	 *
	 * @since 0.2.0
	 *
	 * @param string $facet One of CatalogSearch's FACET_* names.
	 * @return bool True for the five.
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
	 * Stores the complete set of documents of the products they belong to: writes a document that changed or is missing, and deletes one of those products in a locale not given.
	 *
	 * Reads the products' stored documents with one statement, then writes one statement per
	 * document that differs, byte for byte, and per document gone. Nothing runs in a transaction:
	 * each document is one statement.
	 *
	 * @since 0.2.0
	 *
	 * @param ProductIndexDocument ...$documents Every document of each product they name.
	 * @return int How many documents were written or deleted.
	 */
	public function index( ProductIndexDocument ...$documents ): int {
		$given = array();

		foreach ( $documents as $document ) {
			$given[ $document->productId ][ $document->locale->toString() ] = $document->text();
		}

		if ( array() === $given ) {
			return 0;
		}

		$stored = array();

		foreach ( $this->statements->rows( self::DOCUMENTS_OF_PRODUCTS, array_keys( $given ) ) as $row ) {
			$stored[ (int) $row['product_id'] ][ (string) $row['locale'] ] = (string) $row['search_document'];
		}

		$written = 0;

		foreach ( $given as $productId => $texts ) {
			foreach ( $texts as $locale => $text ) {
				$old = $stored[ $productId ][ $locale ] ?? null;

				if ( null === $old ) {
					$written += $this->statements->execute( self::INSERT_DOCUMENT, $productId, $locale, $text );
				} elseif ( $old !== $text ) {
					$written += $this->statements->execute( self::UPDATE_DOCUMENT, $text, $productId, $locale, $text );
				}
			}

			foreach ( array_keys( array_diff_key( $stored[ $productId ] ?? array(), $texts ) ) as $gone ) {
				$written += $this->statements->execute( self::DELETE_DOCUMENT, $productId, (string) $gone );
			}
		}

		return $written;
	}

	/**
	 * Deletes every document of a product, with DELETE_DOCUMENTS_OF_PRODUCT.
	 *
	 * @since 0.2.0
	 *
	 * @param int $productId The product.
	 * @return int How many documents were deleted.
	 */
	public function delete( int $productId ): int {
		return $this->statements->execute( self::DELETE_DOCUMENTS_OF_PRODUCT, $productId );
	}

	/**
	 * Returns the documents of some products, one per post that presents each: three reads, whatever the number of products.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, list<ProductIndexDocument>> By product id.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function documents( array $productIds ): array {
		$documents = array();

		if ( array() === $productIds ) {
			return $documents;
		}

		$bindings = $this->statements->rows( self::BINDINGS_OF_PRODUCTS, $productIds );

		if ( array() === $bindings ) {
			return $documents;
		}

		$posts = array();
		$skus  = array();

		foreach ( $this->statements->rows( self::POSTS_OF_IDS, $this->postsTable(), array_map( static fn( array $row ): int => (int) $row['post_id'], $bindings ) ) as $row ) {
			$posts[ (int) $row['post_id'] ] = $row;
		}

		foreach ( $this->statements->rows( self::LISTED_SKUS, $productIds ) as $row ) {
			$skus[ (int) $row['product_id'] ][] = (string) $row['sku'];
		}

		foreach ( $bindings as $binding ) {
			$productId = (int) $binding['product_id'];
			$post      = $posts[ (int) $binding['post_id'] ] ?? null;

			// A binding whose post is gone presents nothing; doctor reports it.
			if ( null === $post ) {
				continue;
			}

			$documents[ $productId ][] = new ProductIndexDocument( $productId, Locale::of( (string) $binding['locale'] ), (string) $post['post_title'], (string) $post['post_excerpt'], $skus[ $productId ] ?? array() );
		}

		return $documents;
	}

	/**
	 * Counts the posts of complete products with an enabled variant that have no document, with UNINDEXED.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function unindexedCount(): int {
		return (int) ( $this->statements->rows( self::UNINDEXED, GenerationState::Complete->value )[0]['bindings'] ?? 0 );
	}

	/**
	 * Returns a search's words as the word index's boolean search: each run of letters and digits of SearchQuery::WORD_MIN or more that is not a stopword, required, matched as a prefix.
	 *
	 * Every other character, a boolean operator among them, separates runs, so nothing typed can
	 * change what the search means. A run shorter than SearchQuery::WORD_MIN, or one of
	 * SearchQuery::STOPWORDS in any case, is left out, as the index holds none: required, it would
	 * leave every product out.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $words The search's words, valid UTF-8.
	 * @return string Such as `+linen* +shi*`; empty when no run is long enough and not a stopword.
	 *
	 * @phpstan-param list<string> $words
	 */
	public static function booleanSearch( array $words ): string {
		$terms = array();

		foreach ( $words as $word ) {
			preg_match_all( '/[\p{L}\p{N}]+/u', $word, $runs );

			foreach ( $runs[0] as $run ) {
				if ( mb_strlen( $run, 'UTF-8' ) >= SearchQuery::WORD_MIN && ! in_array( mb_strtolower( $run, 'UTF-8' ), SearchQuery::STOPWORDS, true ) ) {
					$terms[] = '+' . $run . '*';
				}
			}
		}

		return implode( ' ', $terms );
	}

	/**
	 * Returns PAGE's values but the page's: the posts, the locale and the product's state, then the filters, the price range and the words.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query The search.
	 * @return list<mixed> The values.
	 */
	private function newestValues( SearchQuery $query ): array {
		return array_merge(
			array( $this->postsTable(), $query->locale->toString(), GenerationState::Complete->value, ProductCapabilities::POST_TYPE, self::PUBLISHED ),
			$this->filterValues( $query ),
			self::priceFilterValues( $query ),
			self::wordsValues( $query )
		);
	}

	/**
	 * Returns the values of a price order but the page's: the product's state, the locale, the posts, the currency and the range, then the filters and the words.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query The search.
	 * @return list<mixed> The values.
	 */
	private function priceValues( SearchQuery $query ): array {
		return array_merge(
			array( GenerationState::Complete->value, $query->locale->toString(), $this->postsTable(), ProductCapabilities::POST_TYPE, self::PUBLISHED ),
			array( $query->currencyCode(), $query->minPriceMinor ?? 0, $query->maxPriceMinor ?? PHP_INT_MAX ),
			$this->filterValues( $query ),
			self::wordsValues( $query )
		);
	}

	/**
	 * Returns PAGE_RELEVANCE's values but the page's: the posts, the product's state, the locale and the search, then the filters, the price range and the search again, for the order.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query   The search.
	 * @param string      $against The boolean search.
	 * @return list<mixed> The values.
	 */
	private function relevanceValues( SearchQuery $query, string $against ): array {
		return array_merge(
			array( $this->postsTable(), ProductCapabilities::POST_TYPE, self::PUBLISHED, GenerationState::Complete->value, $query->locale->toString(), $against ),
			$this->filterValues( $query ),
			self::priceFilterValues( $query ),
			array( $against )
		);
	}

	/**
	 * Returns the values of STOCK_FILTER and TERM_FILTERS.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query The search.
	 * @return list<mixed> The values, in the order the two fragments take them.
	 */
	private function filterValues( SearchQuery $query ): array {
		$relationships = $this->db->prefix() . 'term_relationships';
		$categories    = $query->categoryTermTaxonomyIds;

		return array(
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
	}

	/**
	 * Returns the values of PRICE_FILTER: its flag, the currency and the range, 0 to PHP_INT_MAX for a bound not given.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query The search.
	 * @return list<mixed> The values.
	 */
	private static function priceFilterValues( SearchQuery $query ): array {
		return array( $query->hasPriceRange() ? 1 : 0, $query->currencyCode(), $query->minPriceMinor ?? 0, $query->maxPriceMinor ?? PHP_INT_MAX );
	}

	/**
	 * Returns the values of WORDS: each slot's, an empty slot's for a word the search does not have.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query The search.
	 * @return list<mixed> The values.
	 */
	private static function wordsValues( SearchQuery $query ): array {
		$values = array();

		for ( $slot = 0; $slot < SearchQuery::MOST_WORDS; $slot++ ) {
			array_push( $values, ...self::wordValues( $query->words[ $slot ] ?? null ) );
		}

		return $values;
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

	/**
	 * Returns the full name of WordPress's posts table on this site.
	 *
	 * @since 0.2.0
	 *
	 * @return string The name.
	 */
	private function postsTable(): string {
		return $this->db->prefix() . 'posts';
	}
}
