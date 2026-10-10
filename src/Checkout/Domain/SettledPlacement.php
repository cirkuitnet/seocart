<?php
/**
 * SettledPlacement: what applying a gateway result to a placement came to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\Application;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a settlement, with the order's status and payment status after it.
 *
 * Owns one fact: what the answer to a placement says of its order once the gateway's result was
 * applied, so the answer reads nothing more. It also carries what the payment path did with the
 * result, so a webhook's receipt can record it, a duplicate told from a stale result, without a
 * second read.
 *
 * @since 0.1.0
 * @since 0.2.0 What the payment path did.
 */
final readonly class SettledPlacement {

	/**
	 * Holds the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @param PlacementOutcome $outcome       What the settlement came to.
	 * @param int              $orderId       The order.
	 * @param OrderStatus|null $orderStatus   The order's status after it, or null when the settlement did not change it.
	 * @param PaymentStatus    $paymentStatus The order's payment status after it.
	 * @param Application|null $application   Optional. What the payment path did with the gateway's result; null for an
	 *                                        order with nothing to pay, which has none. Default null.
	 */
	public function __construct(
		public PlacementOutcome $outcome,
		public int $orderId,
		public ?OrderStatus $orderStatus,
		public PaymentStatus $paymentStatus,
		public ?Application $application = null
	) {
	}

	/**
	 * Returns the same outcome, with what the payment path did.
	 *
	 * @since 0.2.0
	 *
	 * @param Application $application What the payment path did with the gateway's result.
	 * @return self The outcome.
	 */
	public function withApplication( Application $application ): self {
		return new self( $this->outcome, $this->orderId, $this->orderStatus, $this->paymentStatus, $application );
	}
}
