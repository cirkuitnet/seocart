<?php
/**
 * AdjustmentScope: what an adjustment changes: a line, the shipping, or the order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

defined( 'ABSPATH' ) || exit;

/**
 * The part of the totals an adjustment belongs to.
 *
 * Owns one fact: the scopes an adjustment can have, spelled as `order_adjustments.scope` stores
 * them. A line-scoped adjustment is already inside its line's amount, which is the line after
 * its discounts; the others are added to the lines to make the grand total.
 *
 * @since 0.1.0
 */
enum AdjustmentScope: string {

	/**
	 * One line, named by its key.
	 *
	 * @since 0.1.0
	 */
	case Line = 'line';

	/**
	 * The shipping of the order.
	 *
	 * @since 0.1.0
	 */
	case Shipping = 'shipping';

	/**
	 * The order as a whole.
	 *
	 * @since 0.1.0
	 */
	case Order = 'order';
}
