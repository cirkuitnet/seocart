<?php
/**
 * PaymentIntent: one attempt to pay for an order, as its row reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An intent's row: its order, its gateway and mode, its state, its frozen amounts and what its ledger has moved.
 *
 * Owns one fact: what the payment service decides an intent's results from. The amount and its
 * base twin were frozen from the order's totals when the intent was created and never change; a
 * recalculation voids the intent and creates another. The authorized, captured and refunded
 * amounts are the ledger's projection, written only by the statements that append to it.
 *
 * @since 0.1.0
 * @since 0.2.0 Carries the mode.
 */
final readonly class PaymentIntent {

	/**
	 * Records the row.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The mode was added.
	 *
	 * @param int          $id                  The intent's internal id.
	 * @param string       $uuid                Its public identifier, also the idempotency key its gateway receives.
	 * @param int          $orderId             The order it pays for.
	 * @param string       $gatewayId           The gateway it is paid through.
	 * @param Mode         $mode                The provider mode it was created in.
	 * @param IntentStatus $status              Its state.
	 * @param Money        $amount              Its frozen amount, in the order's currency.
	 * @param Money        $baseAmount          Its frozen amount in the base currency, at the order's rate.
	 * @param int          $conversionContextId The rate it was frozen at.
	 * @param Money        $authorized          Authorized so far.
	 * @param Money        $captured            Captured so far.
	 * @param Money        $refunded            Refunded so far.
	 * @param string|null  $providerIntentId    The gateway's reference to it, once the gateway gave one.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public int $orderId,
		public string $gatewayId,
		public Mode $mode,
		public IntentStatus $status,
		public Money $amount,
		public Money $baseAmount,
		public int $conversionContextId,
		public Money $authorized,
		public Money $captured,
		public Money $refunded,
		public ?string $providerIntentId
	) {
	}
}
