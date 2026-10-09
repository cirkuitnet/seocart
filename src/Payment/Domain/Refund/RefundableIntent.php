<?php
/**
 * RefundableIntent: the captured payment intent a refund gives money back through
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An order's captured intent, read without a lock before a refund's gateway call: what it captured and what was refunded of it, in both currencies, whether it has money a person must reconcile, how many of its refunds the gateway declined, and the refund it is still waiting for.
 *
 * Owns one fact: what a refund is checked against before the gateway is asked. The read decides
 * nothing for good: the refund's update of the intent carries the same cap in its WHERE clause.
 * The gateway and the mode are the intent's own: the refund is asked of that gateway, with that
 * mode's credentials.
 *
 * @since 0.1.0
 * @since 0.2.0 Carries the gateway and the mode.
 */
final readonly class RefundableIntent {

	/**
	 * Records the intent.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateway and the mode were added.
	 *
	 * @param int         $id                 The intent's internal id.
	 * @param string      $uuid               Its public identifier.
	 * @param string      $gatewayId          The gateway it is paid through.
	 * @param Mode        $mode               The provider mode it was created in.
	 * @param string|null $providerIntentId   The gateway's reference to it.
	 * @param Money       $captured           Captured so far, in the order's currency.
	 * @param Money       $refunded           Refunded so far.
	 * @param Money       $baseCaptured       Captured so far in the base currency.
	 * @param Money       $baseRefunded       Refunded so far in the base currency.
	 * @param bool        $hasUnappliedResult Whether the ledger holds a result of the intent applied to nothing since a person last cleared its order's unreconciled money: money a person must reconcile before anything else is done with the payment.
	 * @param int         $declinedRefunds    Optional. How many refunds of the intent were declined: by the gateway, or by a person's statement that it never made them. Default 0.
	 * @param string|null $openClaim          Optional. The uuid of the intent's oldest refund still claimed: asked of the
	 *                                        gateway, its answer not recorded yet. Default null, when there is none.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $gatewayId,
		public Mode $mode,
		public ?string $providerIntentId,
		public Money $captured,
		public Money $refunded,
		public Money $baseCaptured,
		public Money $baseRefunded,
		public bool $hasUnappliedResult,
		public int $declinedRefunds = 0,
		public ?string $openClaim = null
	) {
	}
}
