<?php
/**
 * CartStatus: whether a cart may still be changed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Where a cart stands with respect to the order placed from it.
 *
 * Owns one fact: the values of `carts.status`. A cart is created open and every Store API write
 * requires it to be open. Placing an order moves it to placing, inside the placement's
 * transaction; the settlement of the payment result then moves it to converted, when the order
 * is accepted, or back to open, when it is not, so the shopper can try again. While a cart is
 * placing or converted, every write is refused.
 *
 * @since 0.1.0
 */
enum CartStatus: string {

	/**
	 * The shopper may change the cart.
	 *
	 * @since 0.1.0
	 */
	case Open = 'open';

	/**
	 * An order was placed from the cart, and its payment result is outstanding.
	 *
	 * @since 0.1.0
	 */
	case Placing = 'placing';

	/**
	 * The order placed from the cart was accepted; the cart is finished.
	 *
	 * @since 0.1.0
	 */
	case Converted = 'converted';
}
