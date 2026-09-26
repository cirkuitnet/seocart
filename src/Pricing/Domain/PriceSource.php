<?php
/**
 * PriceSource: where a line's unit price came from
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * How a line's unit price was found.
 *
 * Owns one fact: the origins a unit price can have, spelled as `order_lines.price_source`
 * stores them, so an order can say whether the merchant typed the price in the cart's currency,
 * it was converted from the base currency, or a promotion added the line.
 *
 * @since 0.1.0
 */
enum PriceSource: string {

	/**
	 * The merchant authored the price in the cart's currency.
	 *
	 * @since 0.1.0
	 */
	case Explicit = 'explicit';

	/**
	 * The price was converted from the base currency's price at a frozen rate.
	 *
	 * @since 0.1.0
	 */
	case Converted = 'converted';

	/**
	 * A promotion added the line, at the price it named.
	 *
	 * @since 0.1.0
	 */
	case AutoAdded = 'auto_added';
}
