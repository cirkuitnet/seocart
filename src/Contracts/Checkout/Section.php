<?php
/**
 * Section: where the checkout form shows a field
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The part of the checkout form a field belongs to: the shopper's contact details, an address, or the order.
 *
 * Owns one fact: the three sections. An `address` field belongs to the shipping and the billing
 * address alike, so a shopper gives it once for each.
 *
 * Experimental until SEOCart 1.0: a case may still be added.
 *
 * @since 0.2.0
 *
 * @api
 */
enum Section: string {

	/**
	 * How the store reaches the shopper, such as an email address.
	 *
	 * @since 0.2.0
	 */
	case Contact = 'contact';

	/**
	 * A part of an address, given for the shipping and for the billing address.
	 *
	 * @since 0.2.0
	 */
	case Address = 'address';

	/**
	 * Something about the order itself.
	 *
	 * @since 0.2.0
	 */
	case Order = 'order';
}
