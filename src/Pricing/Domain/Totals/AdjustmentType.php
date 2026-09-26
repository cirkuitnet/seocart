<?php
/**
 * AdjustmentType: what kind of change an adjustment is: a discount, a shipping charge or a fee
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

defined( 'ABSPATH' ) || exit;

/**
 * The kind of an adjustment, which decides the summary figure it is counted in.
 *
 * Owns one fact: the adjustment types, spelled as `order_adjustments.type` stores them. A
 * discount is negative; a shipping charge and a fee are not.
 *
 * @since 0.1.0
 */
enum AdjustmentType: string {

	/**
	 * Money taken off.
	 *
	 * @since 0.1.0
	 */
	case Discount = 'discount';

	/**
	 * The selected shipping rate.
	 *
	 * @since 0.1.0
	 */
	case Shipping = 'shipping';

	/**
	 * A fee on a declared base.
	 *
	 * @since 0.1.0
	 */
	case Fee = 'fee';
}
