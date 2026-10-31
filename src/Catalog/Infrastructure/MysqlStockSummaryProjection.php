<?php
/**
 * MysqlStockSummaryProjection: the stock summaries, kept in the products table
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure;

use SEOCart\Catalog\Application\Projection\StockSummaries;
use SEOCart\Catalog\Application\Projection\StockSummary;
use SEOCart\Catalog\Domain\GenerationState;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes `products.stock_summary`, by primary keys and the variants' product index.
 *
 * Owns one fact: the statements of the summary. Each is a complete constant sent through
 * ModuleStatements. The write is one conditional UPDATE by the product's primary key that changes
 * nothing when the row already holds the value, and it touches neither `updated_at` nor
 * `generation_state`, which the generation marker's compare-and-set reads. COMPLETE_PRODUCT_IDS and
 * UNSUMMARIZED read the `complete` products, for the command that summarizes the catalog and for
 * doctor.
 *
 * @since 0.2.0
 */
final class MysqlStockSummaryProjection implements StockSummaries {

	/**
	 * The product of each of some variants.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PRODUCTS_OF_VARIANTS = 'SELECT id, product_id FROM {variants} WHERE id IN ({list})';

	/**
	 * Some products' stored summaries, each with its enabled variants of the published generation, if any.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ENABLED_VARIANTS = 'SELECT p.id AS product_id, p.stock_summary, v.id AS variant_id FROM {products} p'
		. ' LEFT JOIN {variants} v ON v.product_id = p.id AND v.generation = p.active_variant_generation AND v.is_enabled = 1'
		. ' WHERE p.id IN ({list}) ORDER BY p.id, v.id';

	/**
	 * A product's summary, written only when the row holds another.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const WRITE_SUMMARY = 'UPDATE {products} SET stock_summary = %s WHERE id = %d AND stock_summary <> %s';

	/**
	 * A page of the `complete` products, by id, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const COMPLETE_PRODUCT_IDS = 'SELECT id FROM {products} WHERE generation_state = %s AND id > %d ORDER BY id LIMIT %d';

	/**
	 * The count of the `complete` products with an enabled variant whose summary is still unknown: one
	 * whose every variant is disabled is unknown by the rule, and the command leaves it so.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNSUMMARIZED = 'SELECT COUNT(*) AS products FROM {products} WHERE generation_state = %s AND enabled_variant_count > 0 AND stock_summary = %s';

	/**
	 * Sends the catalog's statements.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the projection's store. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->statements = new ModuleStatements( $db, 'Catalog', array_map( static fn( TableDefinition $table ): string => $table->name(), CatalogTables::all() ) );
	}

	/**
	 * Returns the products some variants belong to.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds The variants.
	 * @return list<int> The products, each once, ascending.
	 *
	 * @phpstan-param list<int> $variantIds
	 */
	public function productsOfVariants( array $variantIds ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		$products = array_values( array_unique( array_map( static fn( array $row ): int => (int) $row['product_id'], $this->statements->rows( self::PRODUCTS_OF_VARIANTS, $variantIds ) ) ) );

		sort( $products );

		return $products;
	}

	/**
	 * Returns each product's stored summary and its enabled variants of the published generation.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $productIds The products.
	 * @return array<int, array{summary: string, variants: list<int>}> By product id.
	 *
	 * @phpstan-param list<int> $productIds
	 */
	public function enabledVariants( array $productIds ): array {
		$products = array();

		if ( array() === $productIds ) {
			return $products;
		}

		foreach ( $this->statements->rows( self::ENABLED_VARIANTS, $productIds ) as $row ) {
			$productId = (int) $row['product_id'];

			$products[ $productId ] ??= array(
				'summary'  => (string) $row['stock_summary'],
				'variants' => array(),
			);

			if ( null !== $row['variant_id'] ) {
				$products[ $productId ]['variants'][] = (int) $row['variant_id'];
			}
		}

		return $products;
	}

	/**
	 * Writes a product's summary, only when it holds another.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $productId The product.
	 * @param string $summary   One of StockSummary's values.
	 * @return bool True when the row changed.
	 */
	public function write( int $productId, string $summary ): bool {
		return 1 === $this->statements->execute( self::WRITE_SUMMARY, $summary, $productId, $summary );
	}

	/**
	 * Returns a page of the `complete` products, by id, after a cursor.
	 *
	 * @since 0.2.0
	 *
	 * @param int $afterId The last id of the previous page, or 0 for the first.
	 * @param int $limit   The most ids the page holds.
	 * @return list<int> The ids, ascending.
	 */
	public function completeProductIds( int $afterId, int $limit ): array {
		return array_map( static fn( array $row ): int => (int) $row['id'], $this->statements->rows( self::COMPLETE_PRODUCT_IDS, GenerationState::Complete->value, $afterId, $limit ) );
	}

	/**
	 * Counts the `complete` products whose summary is still unknown.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function unsummarizedCount(): int {
		return (int) ( $this->statements->rows( self::UNSUMMARIZED, GenerationState::Complete->value, StockSummary::UNKNOWN )[0]['products'] ?? 0 );
	}
}
