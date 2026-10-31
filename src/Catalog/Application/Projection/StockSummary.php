<?php
/**
 * StockSummary: the one rule that sums a product's stock up for listings
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

defined( 'ABSPATH' ) || exit;

/**
 * Says whether a product is in stock, out of stock or not yet known, from its enabled variants' units for sale.
 *
 * Owns one fact: the rule. A product is in stock when any of its enabled variants has units for
 * sale, or has no limit (its stock is not counted, or it may be backordered); out of stock when it
 * has enabled variants and none has a unit for sale; unknown when it has no enabled variant, so
 * there is nothing to judge. The figures are Availability::forSale()'s, over every active location
 * that fulfils online orders, so the summary is the store's, not a market's; it is a state, never
 * a number.
 *
 * @since 0.2.0
 */
final class StockSummary {

	/**
	 * At least one enabled variant has units for sale, or no limit.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const IN_STOCK = 'in_stock';

	/**
	 * Every enabled variant has nothing for sale.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OUT_OF_STOCK = 'out_of_stock';

	/**
	 * No enabled variant, or not summarized yet: the column's default.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNKNOWN = 'unknown';

	/**
	 * Returns every summary, in the order a listing explains them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The values.
	 */
	public static function values(): array {
		return array( self::IN_STOCK, self::OUT_OF_STOCK, self::UNKNOWN );
	}

	/**
	 * Sums up a product's stock.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int|null> $forSale Each enabled variant's units for sale, by variant id: null for no limit.
	 * @return string IN_STOCK, OUT_OF_STOCK or UNKNOWN.
	 */
	public static function of( array $forSale ): string {
		if ( array() === $forSale ) {
			return self::UNKNOWN;
		}

		foreach ( $forSale as $units ) {
			if ( null === $units || $units > 0 ) {
				return self::IN_STOCK;
			}
		}

		return self::OUT_OF_STOCK;
	}
}
