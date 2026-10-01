<?php
/**
 * SourceDiscount: what one source took off a calculation, and its base-currency twin
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The net discount of one source, such as one promotion, in the cart's currency and the base currency.
 *
 * Owns one fact: the figures an order records for what a promotion gave, as `promotion_usage`
 * stores them: the nets of the source's discounts, before tax, signed as every discount is,
 * zero or negative. Totals adds them up (Totals::discountOf()); this class only holds them.
 *
 * @since 0.1.0
 */
final readonly class SourceDiscount {

	/**
	 * Holds the figures.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The net discount, in the calculation's currency.
	 * @param Money $base   The same net discount, in the base currency.
	 */
	public function __construct(
		public Money $amount,
		public Money $base
	) {
	}
}
