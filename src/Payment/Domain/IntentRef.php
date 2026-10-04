<?php
/**
 * IntentRef: what another module holds of a payment intent
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
 * A payment intent as checkout and reconciliation see it: enough to authorize it, ask the gateway about it, and find its order.
 *
 * Owns one fact: the intent's identity outside the payment module. It is a snapshot of the
 * moment it was read; the payment service reads the intent again, under a lock, before it
 * decides anything. The gateway and the mode are the intent's own, recorded when it was created:
 * every call about it goes to that gateway with that mode's credentials.
 *
 * @since 0.1.0
 * @since 0.2.0 Carries the gateway, the mode and the intent's age.
 */
final readonly class IntentRef {

	/**
	 * Records the reference.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateway, the mode and the age were added.
	 *
	 * @param string                  $uuid             The intent's public identifier.
	 * @param int                     $orderId          The order it pays for.
	 * @param string                  $gatewayId        The gateway it is paid through.
	 * @param Mode                    $mode             The provider mode it was created in.
	 * @param IntentStatus            $status           Its state when it was read.
	 * @param string|null             $providerIntentId The gateway's reference to it, once the gateway gave one.
	 * @param Money                   $amount           Its frozen amount.
	 * @param int                     $ageSeconds       Optional. How long ago it was created when it was read, in
	 *                                                  seconds, by the database's clock. Default 0, for an intent
	 *                                                  just created.
	 * @param \DateTimeImmutable|null $waitEndsAt       Optional. When its wait for the customer or the gateway runs
	 *                                                  out, UTC; null when it waits for neither. Default null.
	 * @param bool                    $waitEnded        Optional. Whether that time had passed, by the database's
	 *                                                  clock, when the intent was read. Default false.
	 */
	public function __construct(
		public string $uuid,
		public int $orderId,
		public string $gatewayId,
		public Mode $mode,
		public IntentStatus $status,
		public ?string $providerIntentId,
		public Money $amount,
		public int $ageSeconds = 0,
		public ?\DateTimeImmutable $waitEndsAt = null,
		public bool $waitEnded = false
	) {
	}
}
