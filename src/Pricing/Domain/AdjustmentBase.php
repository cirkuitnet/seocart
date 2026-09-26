<?php
/**
 * AdjustmentBase: the amount a percentage fee is calculated on
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The declared base of a fee: what it is a percentage of, and what it follows.
 *
 * Owns one fact: the bases a fee may declare, spelled as `order_adjustments.calculation_base`
 * stores them. A fee always names its base, so no fee can be computed on a figure that already
 * includes it.
 *
 * @since 0.1.0
 */
enum AdjustmentBase: string {

	/**
	 * The lines' amounts after every discount.
	 *
	 * @since 0.1.0
	 */
	case SubtotalAfterDiscounts = 'subtotal_after_discounts';

	/**
	 * The selected shipping rate, after a free-shipping discount.
	 *
	 * @since 0.1.0
	 */
	case SelectedShippingRate = 'selected_shipping_rate';
}
