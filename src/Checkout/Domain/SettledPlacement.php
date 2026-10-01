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

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a settlement, with the order's status and payment status after it.
 *
 * Owns one fact: what the answer to a placement says of its order once the gateway's result was
 * applied, so the answer reads nothing more.
 *
 * @since 0.1.0
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
	 */
	public function __construct(
		public PlacementOutcome $outcome,
		public int $orderId,
		public ?OrderStatus $orderStatus,
		public PaymentStatus $paymentStatus
	) {
	}
}
