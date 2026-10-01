<?php
/**
 * Projection: an order's payment amounts once a payment is added, and the payment status they amount to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The order's payment projection as it will read after one ledger row, and the status derived from it.
 *
 * Owns one fact: how `orders.payment_status` is derived. The status is not a state machine of
 * its own: it is what the order's amounts and its intent's state say, written in the same
 * statement that moves the amounts. The amounts are the locked order's plus the payment's, as
 * the projection's update adds them; the database adds them for the record, and this adds them
 * only to know which status the record will show.
 *
 * With one tender per order, the intent is the order's only intent; the day an order has two,
 * the intents' states are read as one aggregate and passed here in its place.
 *
 * @since 0.1.0
 */
final readonly class Projection {

	/**
	 * Records the amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $grandTotal The order's grand total.
	 * @param Money $authorized Authorized.
	 * @param Money $paid       Captured.
	 * @param Money $refunded   Refunded.
	 * @param Money $due        Still to be paid.
	 */
	public function __construct(
		public Money $grandTotal,
		public Money $authorized,
		public Money $paid,
		public Money $refunded,
		public Money $due
	) {
	}

	/**
	 * Returns an order's payment amounts once a payment is added, as the projection's update adds them.
	 *
	 * @since 0.1.0
	 *
	 * @param LockedOrder  $order The order, locked.
	 * @param PaymentDelta $delta What the payment adds.
	 * @return self The amounts after.
	 */
	public static function after( LockedOrder $order, PaymentDelta $delta ): self {
		return new self(
			$order->grandTotal,
			$order->authorized->add( $delta->authorized ),
			$order->paid->add( $delta->captured ),
			$order->refunded->add( $delta->refunded ),
			$order->due->subtract( $delta->captured )->add( $delta->refunded )
		);
	}

	/**
	 * Returns the payment status the amounts and the intent's state amount to.
	 *
	 * Refunds first, then captures, then authorizations; with nothing tendered, the intent's state
	 * decides: failed, still waiting, voided, or not paid yet.
	 *
	 * @since 0.1.0
	 *
	 * @param IntentStatus $intent The order's intent's state after the payment.
	 * @return PaymentStatus The status.
	 */
	public function status( IntentStatus $intent ): PaymentStatus {
		if ( self::positive( $this->paid ) && $this->refunded->compare( $this->paid ) >= 0 ) {
			return PaymentStatus::Refunded;
		}

		if ( self::positive( $this->refunded ) ) {
			return PaymentStatus::PartiallyRefunded;
		}

		if ( self::positive( $this->paid ) ) {
			return $this->paid->compare( $this->grandTotal ) >= 0 ? PaymentStatus::Paid : PaymentStatus::PartiallyPaid;
		}

		if ( self::positive( $this->authorized ) ) {
			return PaymentStatus::Authorized;
		}

		return match ( $intent ) {
			IntentStatus::Failed                                 => PaymentStatus::Failed,
			IntentStatus::RequiresAction, IntentStatus::Processing => PaymentStatus::Pending,
			IntentStatus::Voided                                 => PaymentStatus::Voided,
			default                                              => PaymentStatus::Unpaid,
		};
	}

	/**
	 * Tells whether an amount is more than nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The amount.
	 * @return bool True when positive.
	 */
	private static function positive( Money $amount ): bool {
		return ! $amount->isZero() && ! $amount->isNegative();
	}
}
