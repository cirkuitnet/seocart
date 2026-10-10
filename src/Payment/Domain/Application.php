<?php
/**
 * Application: what applying one gateway result did
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of PaymentService::applyGatewayResult(): the kind, and every state it read or wrote.
 *
 * Owns one fact: what a caller of the money path learns from it. Checkout branches on the kind
 * and settles stock, promotion usage and the cart in the same transaction; it finds the order's
 * stock hold by the hold group, read with the order's lock. A mismatch says why it was kept, so a
 * webhook's receipt can name it without reading the order.
 *
 * @since 0.1.0
 * @since 0.2.0 Why a mismatch was kept.
 */
final readonly class Application {

	/**
	 * Records the outcome.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The reason.
	 *
	 * @param ApplicationKind  $kind          What happened.
	 * @param string           $intentUuid    The intent the result was about.
	 * @param Operation        $operation     The operation it reported.
	 * @param int|null         $transactionId The ledger row: the one written, or for a duplicate the first; null when no row is written.
	 * @param int              $orderId       The intent's order.
	 * @param string|null      $holdGroup     The order's stock hold, or null when placement held nothing.
	 * @param IntentStatus     $intentFrom    The intent's state before.
	 * @param IntentStatus     $intentTo      The intent's state after; the same for a duplicate and a mismatch.
	 * @param PaymentStatus    $paymentFrom   The order's payment status before.
	 * @param PaymentStatus    $paymentTo     The order's payment status after.
	 * @param OrderStatus|null $orderStatusTo The order's status after, when the result changed it; otherwise null.
	 * @param string|null      $reason        Optional. Why a mismatch was kept for a person: the word its order was parked
	 *                                        and flagged with, such as `amount_mismatch` or `late_approval`; null for
	 *                                        any other kind. Default null.
	 */
	public function __construct(
		public ApplicationKind $kind,
		public string $intentUuid,
		public Operation $operation,
		public ?int $transactionId,
		public int $orderId,
		public ?string $holdGroup,
		public IntentStatus $intentFrom,
		public IntentStatus $intentTo,
		public PaymentStatus $paymentFrom,
		public PaymentStatus $paymentTo,
		public ?OrderStatus $orderStatusTo,
		public ?string $reason = null
	) {
	}
}
