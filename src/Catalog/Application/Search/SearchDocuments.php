<?php
/**
 * SearchDocuments: the reads a product's search documents are built from, and doctor's count of the posts without one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Reads what a product's searchable words are, per locale, and counts the posts the word index lacks.
 *
 * Owns one fact: where a search document's words come from. A product has one document per post
 * that presents it, whatever the post's status: the post's title and excerpt, in its locale, and
 * the SKUs of the product's enabled variants of its published generation. The projector builds
 * the documents through it and stores them through CatalogSearch::index(); doctor counts through it.
 * MysqlCatalogSearch is the implementation.
 *
 * @since 0.2.0
 */
interface SearchDocuments {

	/**
	 * Returns the documents of some products, one per post that presents each.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, list<ProductIndexDocument>> By product id; a product with no post, or that no longer exists, is absent.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function documents( array $productIds ): array;

	/**
	 * Counts the posts of complete products with an enabled variant that have no document.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function unindexedCount(): int;
}
