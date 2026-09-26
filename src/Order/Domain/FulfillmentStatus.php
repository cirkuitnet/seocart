<?php
/**
 * FulfillmentStatus: how much of an order has been shipped or returned
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `orders.fulfillment_status`.
 *
 * Owns one fact: the vocabulary of an order's fulfillment status. Every order starts
 * unfulfilled; shipping and returns are what will change it.
 *
 * @since 0.1.0
 */
enum FulfillmentStatus: string {

	/**
	 * Nothing has been shipped.
	 *
	 * @since 0.1.0
	 */
	case Unfulfilled = 'unfulfilled';

	/**
	 * Part of the order has been shipped.
	 *
	 * @since 0.1.0
	 */
	case PartiallyFulfilled = 'partially_fulfilled';

	/**
	 * The whole order has been shipped.
	 *
	 * @since 0.1.0
	 */
	case Fulfilled = 'fulfilled';

	/**
	 * Part of what was shipped has come back.
	 *
	 * @since 0.1.0
	 */
	case PartiallyReturned = 'partially_returned';

	/**
	 * Everything that was shipped has come back.
	 *
	 * @since 0.1.0
	 */
	case Returned = 'returned';
}
