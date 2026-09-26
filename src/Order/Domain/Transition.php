<?php
/**
 * Transition: an order's status change that was made
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One change of an order's status, as the transaction that made it recorded it.
 *
 * Owns one fact: what a transition changed, and the `order_events` row that records it.
 *
 * @since 0.1.0
 */
final readonly class Transition {

	/**
	 * Records the change.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $orderId The order's internal id.
	 * @param OrderStatus $from    The status before.
	 * @param OrderStatus $to      The status after.
	 * @param int         $eventId The id of the `order_events` row that records it.
	 */
	public function __construct(
		public int $orderId,
		public OrderStatus $from,
		public OrderStatus $to,
		public int $eventId
	) {
	}
}
