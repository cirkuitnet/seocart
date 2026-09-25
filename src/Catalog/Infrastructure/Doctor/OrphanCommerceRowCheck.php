<?php
/**
 * OrphanCommerceRowCheck: variants and prices whose product is gone
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Doctor;

use SEOCart\Catalog\Application\ProductRepository;
use SEOCart\Platform\Cli\Doctor\Check;
use SEOCart\Platform\Cli\Doctor\CheckResult;

defined( 'ABSPATH' ) || exit;

/**
 * Reports `variants` and `variant_prices` rows whose product no longer exists (doctor check 8).
 *
 * Owns one fact: which commerce rows outlived their product. Never repaired: a variant id may
 * already be on a ledger row, so deleting one here could break that history.
 *
 * @since 0.1.0
 */
final class OrphanCommerceRowCheck implements Check {

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
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param ProductRepository $products The products.
	 */
	public function __construct( ProductRepository $products ) {
		$this->products = $products;
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
	 * Lists variants and prices whose product is gone.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every variant and price still has a product.
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

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, 'Every variant and price still has a product.' );
		}

		return CheckResult::fail( self::NAME, sprintf( '%d kind%s of orphan commerce row found.', count( $findings ), 1 === count( $findings ) ? '' : 's' ), $findings );
	}
}
