<?php
/**
 * UnpricedLine: a line the calculation found no price for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

defined( 'ABSPATH' ) || exit;

/**
 * A line left out of the totals because it has no price in the cart's currency, and why.
 *
 * Owns one fact: how a line without a price is reported. It is never priced at zero or at a
 * guess: it is left out of the totals and named here, so a cart can still be shown and an order
 * refuses to be placed while one remains.
 *
 * @since 0.1.0
 */
final readonly class UnpricedLine {

	/**
	 * The variant has a price in the base currency, but none in the cart's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NO_PRICE_IN_CURRENCY = 'no_price_in_currency';

	/**
	 * No price row names the variant at all: it has no price, or it does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNKNOWN_VARIANT = 'unknown_variant';

	/**
	 * Holds the line and the reason.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key       The line's key.
	 * @param int    $variantId The variant.
	 * @param string $reason    NO_PRICE_IN_CURRENCY or UNKNOWN_VARIANT.
	 */
	public function __construct(
		public string $key,
		public int $variantId,
		public string $reason
	) {
	}
}
