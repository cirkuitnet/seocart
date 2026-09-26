<?php
/**
 * OrderStatus: the statuses an order can be in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `orders.status`.
 *
 * Owns one fact: the names of the order statuses. What each status means and which status may
 * follow which is the registry's data (OrderStatusRegistry), not this enum's.
 *
 * @since 0.1.0
 */
enum OrderStatus: string {

	/**
	 * Placed, and waiting for its payment.
	 *
	 * @since 0.1.0
	 */
	case PendingPayment = 'pending_payment';

	/**
	 * Paid for, and waiting for the store to review it.
	 *
	 * @since 0.1.0
	 */
	case AwaitingReview = 'awaiting_review';

	/**
	 * Accepted: its payment was authorized and it may be fulfilled.
	 *
	 * @since 0.1.0
	 */
	case Processing = 'processing';

	/**
	 * Parked until a person looks at it, for example because a payment did not match it.
	 *
	 * @since 0.1.0
	 */
	case OnHold = 'on_hold';

	/**
	 * Accepted, and waiting for stock that has not arrived yet.
	 *
	 * @since 0.1.0
	 */
	case Preorder = 'preorder';

	/**
	 * Fulfilled and paid for.
	 *
	 * @since 0.1.0
	 */
	case Completed = 'completed';

	/**
	 * Cancelled by the store or the customer.
	 *
	 * @since 0.1.0
	 */
	case Cancelled = 'cancelled';

	/**
	 * Its payment was declined or never completed; the order keeps its number.
	 *
	 * @since 0.1.0
	 */
	case Failed = 'failed';
}
