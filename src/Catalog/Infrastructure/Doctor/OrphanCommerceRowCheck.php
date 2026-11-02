<?php
/**
 * OrphanCommerceRowCheck: variants, prices and derived rows whose product is gone
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Catalog\Application\Search\CatalogSearch;
use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\Repairable;
use SEOCart\Platform\Cli\Doctor\RepairResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports `variants` and `variant_prices` rows whose product no longer exists, option rows whose parent is gone and option values whose image is gone (doctor check 8), and the derived rows of a product gone, which `--repair` deletes.
 *
 * Owns one fact: which commerce rows outlived their product. Variants and prices are never
 * repaired: a variant id may already be on a ledger row, so deleting one here could break that
 * history. Option axes, values and the values a variant selects are reported, never repaired: a
 * product's deletion removes them in its own transaction, so one left over is a fault to look
 * at. An option value whose image attachment was deleted shows no image; nothing clears it, and
 * it is reported so a merchant can pick another. A sort price or a search document of a product that no longer exists is derived and
 * joins nothing: a
 * projection that read the product before its deletion committed may have written it after, and
 * `--repair` deletes it. A product id is never given again, so its rows stay orphans until then.
 *
 * @since 0.1.0
 * @since 0.2.0 The sort prices and the search documents of a product gone, and their repair.
 */
final class OrphanCommerceRowCheck implements Repairable {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'orphan_commerce_rows';

	/**
	 * The most ids the check lists per kind.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 20;

	/**
	 * The products.
	 *
	 * @since 0.1.0
	 *
	 * @var ProductRepository
	 */
	private ProductRepository $products;

	/**
	 * The sort prices, which the repair deletes.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjections
	 */
	private PriceProjections $projections;

	/**
	 * The products gone whose sort prices the last run found.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private array $pricedGone = array();

	/**
	 * The search documents, which the repair deletes.
	 *
	 * @since 0.2.0
	 *
	 * @var CatalogSearch
	 */
	private CatalogSearch $search;

	/**
	 * The products gone whose search documents the last run found.
	 *
	 * @since 0.2.0
	 *
	 * @var list<int>
	 */
	private array $indexedGone = array();

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The sort prices.
	 *
	 * @param ProductRepository $products    The products.
	 * @param PriceProjections  $projections The sort prices, which the repair deletes.
	 * @param CatalogSearch     $search      The search documents, which the repair deletes.
	 */
	public function __construct( ProductRepository $products, PriceProjections $projections, CatalogSearch $search ) {
		$this->products    = $products;
		$this->projections = $projections;
		$this->search      = $search;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `catalog.orphan_commerce_rows`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Lists variants and prices whose product is gone, and the products gone that still have sort prices or search documents.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every variant, price and sort price still has a product.
	 */
	public function run(): CheckResult {
		$findings = array();

		$variants     = $this->products->orphanVariants( self::LIMIT + 1 );
		$moreVariants = count( $variants ) > self::LIMIT;
		$variants     = array_slice( $variants, 0, self::LIMIT );

		if ( array() !== $variants ) {
			$findings[] = sprintf(
				'Reported: variant%1$s %2$s%3$s whose product no longer exists. Never repaired: the variant id may already be on a ledger row.',
				1 === count( $variants ) ? '' : 's',
				implode( ', ', array_map( static fn( array $row ): int => $row['variant_id'], $variants ) ),
				$moreVariants ? ' and more' : ''
			);
		}

		$prices     = $this->products->orphanPrices( self::LIMIT + 1 );
		$morePrices = count( $prices ) > self::LIMIT;
		$prices     = array_slice( $prices, 0, self::LIMIT );

		if ( array() !== $prices ) {
			$findings[] = sprintf(
				'Reported: price row%1$s %2$s%3$s whose variant no longer exists. Never repaired.',
				1 === count( $prices ) ? '' : 's',
				implode( ', ', array_map( static fn( array $row ): int => $row['price_id'], $prices ) ),
				$morePrices ? ' and more' : ''
			);
		}

		$options = $this->products->orphanOptionRows( self::LIMIT + 1 );

		foreach ( array(
			'axes'       => array( 'option axis', 'option axes', 'id', 'whose product no longer exists. Never repaired.' ),
			'values'     => array( 'option value', 'option values', 'id', 'whose axis no longer exists. Never repaired.' ),
			'selections' => array( 'option value selected by a variant', 'option values selected by variants', 'option_value_id', 'whose variant or value no longer exists. Never repaired.' ),
			'images'     => array( 'option value', 'option values', 'id', 'whose image attachment no longer exists: they show no image until another is picked. Never repaired.' ),
		) as $kind => list( $one, $many, $column, $why ) ) {
			$rows = $options[ $kind ];

			if ( array() !== $rows ) {
				$findings[] = sprintf(
					'Reported: %1$s %2$s%3$s %4$s',
					1 === count( $rows ) ? $one : $many,
					implode( ', ', array_map( static fn( array $row ): int => $row[ $column ], array_slice( $rows, 0, self::LIMIT ) ) ),
					count( $rows ) > self::LIMIT ? ' and more' : '',
					$why
				);
			}
		}

		$this->pricedGone = self::productsOf( $this->products->orphanPriceProjections( self::LIMIT + 1 ) );

		if ( array() !== $this->pricedGone ) {
			$findings[] = self::derived( 'sort prices', $this->pricedGone );
		}

		$this->indexedGone = self::productsOf( $this->products->orphanSearchDocuments( self::LIMIT + 1 ) );

		if ( array() !== $this->indexedGone ) {
			$findings[] = self::derived( 'search documents', $this->indexedGone );
		}

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Every variant, price, option, sort price and search document still has its parent, and every option value its image.' );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d kind%s of orphan commerce row found.', count( $findings ), 1 === count( $findings ) ? '' : 's' ), $findings );
	}

	/**
	 * Deletes the sort prices and the search documents of the products gone that the last run found; variants and prices are never repaired.
	 *
	 * A product id is never given again, so a product gone stays gone: its rows are deleted with no
	 * further check.
	 *
	 * @since 0.2.0
	 *
	 * @return RepairResult What was deleted.
	 */
	public function repair(): RepairResult {
		$changes = array();

		foreach ( $this->pricedGone as $productId ) {
			$deleted = $this->projections->deleteOf( $productId );

			if ( $deleted > 0 ) {
				$changes[] = sprintf( 'deleted the %1$d sort price%2$s of product %3$d, which no longer exists.', $deleted, 1 === $deleted ? '' : 's', $productId );
			}
		}

		foreach ( $this->indexedGone as $productId ) {
			$deleted = $this->search->delete( $productId );

			if ( $deleted > 0 ) {
				$changes[] = sprintf( 'deleted the %1$d search document%2$s of product %3$d, which no longer exists.', $deleted, 1 === $deleted ? '' : 's', $productId );
			}
		}

		return new RepairResult( self::NAME, $changes );
	}

	/**
	 * Returns the distinct products of some orphan rows, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{product_id: int}> $rows The rows.
	 * @return list<int> The products, each once.
	 */
	private static function productsOf( array $rows ): array {
		return array_values( array_unique( array_map( static fn( array $row ): int => $row['product_id'], $rows ) ) );
	}

	/**
	 * Describes the derived rows of products gone, which the repair deletes.
	 *
	 * @since 0.2.0
	 *
	 * @param string $rows     What the rows are, such as `sort prices`.
	 * @param int[]  $products The products gone.
	 * @return string The finding.
	 *
	 * @phpstan-param list<int> $products
	 */
	private static function derived( string $rows, array $products ): string {
		$one = 1 === count( $products );

		return sprintf( 'Repairable: the %1$s of product%2$s %3$s, which no longer exist%4$s. --repair deletes them: they are derived.', $rows, $one ? '' : 's', implode( ', ', $products ), $one ? 's' : '' );
	}
}
