<?php
/**
 * Machine: which status of an order an event records
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `order_events.machine`.
 *
 * Owns one fact: which of an order's three statuses an order event is about.
 *
 * @since 0.1.0
 */
enum Machine: string {

	/**
	 * The order status, `orders.status`.
	 *
	 * @since 0.1.0
	 */
	case Order = 'order';

	/**
	 * The payment status, `orders.payment_status`.
	 *
	 * @since 0.1.0
	 */
	case Payment = 'payment';

	/**
	 * The fulfillment status, `orders.fulfillment_status`.
	 *
	 * @since 0.1.0
	 */
	case Fulfillment = 'fulfillment';
}
