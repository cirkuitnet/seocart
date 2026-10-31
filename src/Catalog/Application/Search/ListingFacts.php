<?php
/**
 * ListingFacts: the commerce facts a listing shows of the products behind a page of posts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, for a page of product posts, each one's product, its variant and its stock summary, in one read.
 *
 * Owns one fact: what a listing knows of a product besides its post. Whichever search found the
 * page, these facts come from the plugin's own tables, so they are a port of their own rather than
 * part of CatalogSearch.
 *
 * @since 0.2.0
 */
interface ListingFacts {

	/**
	 * Returns the facts of the products of some product posts.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $postIds The product posts.
	 * @return array<int, array{product_id: int, product_uuid: string, variant_id: int|null, sku: string|null, stock_summary: string}> The facts
	 *         by post id: the product, its enabled variant of the lowest position in its published
	 *         generation (null when it has none), and its stock summary. A post no product presents is
	 *         left out.
	 *
	 * @phpstan-param list<int> $postIds
	 */
	public function of( array $postIds ): array;
}
