<?php
/**
 * StockSummaries: the reads and the write of the products' stock summaries
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the products whose stock summary may have moved, reads what it is computed from, and writes it.
 *
 * Owns one fact: where the summary is kept and how it is found. The projector, the command that
 * summarizes the whole catalog and doctor's line read and write through it; MysqlStockSummaryProjection
 * is the implementation.
 *
 * @since 0.2.0
 */
interface StockSummaries {

	/**
	 * Returns the products some variants belong to.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return list<int> The products, each once, ascending; a variant that no longer exists adds none.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function productsOfVariants( array $variantIds ): array;

	/**
	 * Returns each product's stored summary and its enabled variants of the published generation.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, array{summary: string, variants: list<int>}> By product id, for each product
	 *         that exists: its summary as stored, and its enabled variants, none when it has none.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function enabledVariants( array $productIds ): array;

	/**
	 * Writes a product's summary, only when it holds another.
	 *
	 * The row's `updated_at` and `generation_state` are left as they are: the generation marker's
	 * compare-and-set reads both, and a summary is not a change of the product.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $summary   One of StockSummary's values.
	 * @return bool True when the row changed.
	 */
	public function write( int $productId, string $summary ): bool;

	/**
	 * Returns a page of the `complete` products, by id, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterId The last id of the previous page, or 0 for the first.
	 * @param int $limit   The most ids the page holds.
	 * @return list<int> The ids, ascending.
	 */
	public function completeProductIds( int $afterId, int $limit ): array;

	/**
	 * Counts the `complete` products with an enabled variant whose summary is still unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function unsummarizedCount(): int;
}
