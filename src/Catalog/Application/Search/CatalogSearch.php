<?php
/**
 * CatalogSearch: the port the product listing finds its page of products through
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the products a listing shows: which, and in what order, never how they look.
 *
 * Owns one fact: what a search engine must answer for the catalog. Given a query, it returns the
 * ids of the product posts of one page, in order, and whether another page follows; never markup,
 * never a price. A query names its facets after the listing has resolved them: categories, brand
 * and tag as term-taxonomy ids, and the in-stock filter. An adapter that cannot filter by a facet
 * says so in supportsFacet().
 *
 * The plugin's own adapter reads its MySQL tables (MysqlCatalogSearch). The port is internal for
 * now: there is no registration action and no second adapter, and its names are the ones a public
 * contract would keep.
 *
 * @since 0.2.0
 */
interface CatalogSearch {

	/**
	 * The facet of the product categories, a category's descendants included.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FACET_CATEGORY = 'category';

	/**
	 * The facet of the product brands.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FACET_BRAND = 'brand';

	/**
	 * The facet of the product tags.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FACET_TAG = 'tag';

	/**
	 * The facet that leaves out every product not in stock.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FACET_IN_STOCK = 'in_stock';

	/**
	 * Finds one page of products.
	 *
	 * @since 0.2.0
	 *
	 * @param SearchQuery $query What to find, in which locale, ordered how, and which page.
	 * @return SearchResults The page's product post ids, in order, and whether another page follows.
	 */
	public function search( SearchQuery $query ): SearchResults;

	/**
	 * Tells whether the adapter can filter by a facet.
	 *
	 * @since 0.2.0
	 *
	 * @param string $facet One of the FACET_* names.
	 * @return bool True when search() honours the facet.
	 */
	public function supportsFacet( string $facet ): bool;
}
