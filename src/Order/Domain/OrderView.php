<?php
/**
 * OrderView: an order, as the order recorded it, for showing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Locale;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An order read back by its public identifier: its statuses, totals, lines and addresses.
 *
 * Owns one fact: what showing an order says about it. It is read from the order's own tables
 * only, never joined to the catalog or a customer, so it reads the same however they change
 * after the sale. It carries no internal id: whatever shows it names the order by its uuid.
 *
 * @since 0.1.0
 */
final readonly class OrderView {

	/**
	 * Records the order.
	 *
	 * @since 0.1.0
	 *
	 * @param string             $uuid              The public identifier.
	 * @param string             $orderNumber       The number shown to people.
	 * @param OrderStatus        $status            Its status.
	 * @param PaymentStatus      $paymentStatus     Its payment status.
	 * @param FulfillmentStatus  $fulfillmentStatus Its fulfillment status.
	 * @param OrderChannel       $channel           Where it came from.
	 * @param Currency           $currency          Its currency.
	 * @param Locale             $locale            The locale it was placed in.
	 * @param string             $email             Where its messages go.
	 * @param int|null           $customerId        The WordPress user it belongs to, or null for a guest order.
	 * @param Money              $subtotal          The lines before discounts.
	 * @param Money              $discountTotal     Every discount.
	 * @param Money              $shippingTotal     Shipping.
	 * @param Money              $feeTotal          Every fee.
	 * @param Money              $taxTotal          Every tax.
	 * @param Money              $grandTotal        The grand total.
	 * @param Money              $paid              Captured so far.
	 * @param Money              $refunded          Refunded so far.
	 * @param Money              $due               Still to be paid.
	 * @param \DateTimeImmutable $placedAt          When it was placed, UTC.
	 * @param OrderLineView[]    $lines             Its lines, in their order.
	 * @param Address            $billingAddress    The billing address.
	 * @param Address|null       $shippingAddress   The shipping address, or null when nothing is shipped.
	 */
	public function __construct(
		public string $uuid,
		public string $orderNumber,
		public OrderStatus $status,
		public PaymentStatus $paymentStatus,
		public FulfillmentStatus $fulfillmentStatus,
		public OrderChannel $channel,
		public Currency $currency,
		public Locale $locale,
		public string $email,
		public ?int $customerId,
		public Money $subtotal,
		public Money $discountTotal,
		public Money $shippingTotal,
		public Money $feeTotal,
		public Money $taxTotal,
		public Money $grandTotal,
		public Money $paid,
		public Money $refunded,
		public Money $due,
		public \DateTimeImmutable $placedAt,
		public array $lines,
		public Address $billingAddress,
		public ?Address $shippingAddress
	) {
	}
}
