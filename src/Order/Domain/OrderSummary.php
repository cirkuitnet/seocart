<?php
/**
 * OrderSummary: one order as a list of a customer's orders shows it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An order's row in a list: its identifiers, when it was placed, its statuses and its total.
 *
 * Owns one fact: what a list names an order by. It carries no line, no address and no email:
 * the order itself is opened by its own read.
 *
 * @since 0.2.0
 */
final readonly class OrderSummary {

	/**
	 * Records the row.
	 *
	 * @since 0.2.0
	 *
	 * @param string             $uuid              The order's public identifier.
	 * @param string             $orderNumber       The number shown to people.
	 * @param \DateTimeImmutable $placedAt          When the order was placed, UTC.
	 * @param OrderStatus        $status            Its status.
	 * @param PaymentStatus      $paymentStatus     Its payment status.
	 * @param FulfillmentStatus  $fulfillmentStatus Its fulfillment status.
	 * @param Money              $grandTotal        Its total, in its currency.
	 */
	public function __construct(
		public string $uuid,
		public string $orderNumber,
		public \DateTimeImmutable $placedAt,
		public OrderStatus $status,
		public PaymentStatus $paymentStatus,
		public FulfillmentStatus $fulfillmentStatus,
		public Money $grandTotal
	) {
	}
}
