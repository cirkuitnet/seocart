<?php
/**
 * NothingDue: what settling an order with nothing to pay did
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of PaymentService::settleNothingDue(): the order's payment status and status after it, and its stock hold.
 *
 * Owns one fact: what a caller learns from settling an order whose grand total is zero, which has
 * no intent and no ledger row. Checkout settles the order's stock, promotion uses and cart from
 * it in the same transaction, as it does from an Application.
 *
 * @since 0.1.0
 */
final readonly class NothingDue {

	/**
	 * Records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param int              $orderId       The order.
	 * @param string|null      $holdGroup     The order's stock hold, or null when placement held nothing.
	 * @param PaymentStatus    $paymentStatus The order's payment status after the settlement.
	 * @param OrderStatus|null $acceptedAs    The status the order was accepted into; null when it was
	 *                                        settled before, and nothing changed.
	 */
	public function __construct(
		public int $orderId,
		public ?string $holdGroup,
		public PaymentStatus $paymentStatus,
		public ?OrderStatus $acceptedAs
	) {
	}
}
