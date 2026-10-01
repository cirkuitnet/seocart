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

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * A payment intent as checkout and reconciliation see it: enough to authorize it, ask the gateway about it, and find its order.
 *
 * Owns one fact: the intent's identity outside the payment module. It is a snapshot of the
 * moment it was read; the payment service reads the intent again, under a lock, before it
 * decides anything.
 *
 * @since 0.1.0
 */
final readonly class IntentRef {

	/**
	 * Records the reference.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $uuid             The intent's public identifier.
	 * @param int          $orderId          The order it pays for.
	 * @param IntentStatus $status           Its state when it was read.
	 * @param string|null  $providerIntentId The gateway's reference to it, once the gateway gave one.
	 * @param Money        $amount           Its frozen amount.
	 */
	public function __construct(
		public string $uuid,
		public int $orderId,
		public IntentStatus $status,
		public ?string $providerIntentId,
		public Money $amount
	) {
	}
}
