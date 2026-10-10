<?php
/**
 * AmountCheck: whether an approval matches what its intent and its order expect
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The amount-and-currency check an approval passes before it may move any money.
 *
 * Owns one fact: when an approval matches. Its currency must be the order's and the intent's,
 * and the intent's base currency the order's. An authorization must be for exactly the intent's
 * frozen amount, which was frozen from the order's total for this tender, and a void releases
 * exactly that amount. A capture takes more than nothing and at most what the intent may
 * capture: what it authorized, or its frozen amount for an intent the gateway authorized and
 * captured at once; less than all of it only where the gateway declares partial captures, which
 * the capture checks before it asks. Neither may bring what the order has tendered past its grand
 * total. A refund's amount is capped by the statement that applies it, against what the intent
 * captured.
 *
 * The intent and the order are read with locking reads before the check, so what it compares is
 * current: this is not a check-then-act. The intent's update requires the currencies again, and
 * the projection's update carries the order's currencies and caps, in their WHERE clauses, as a
 * belt.
 *
 * @since 0.1.0
 * @since 0.2.0 Takes a capture of part of what was authorized, and requires a void to release the whole amount.
 */
final class AmountCheck {

	/**
	 * Tells whether an approval matches its intent and its order.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A capture of part of what was authorized matches; a void matches for the intent's amount only.
	 *
	 * @param GatewayResult $result The approval.
	 * @param PaymentIntent $intent The intent, locked.
	 * @param LockedOrder   $order  The order, locked.
	 * @return bool True when it may move money; false when it must be parked for a person.
	 */
	public static function accepts( GatewayResult $result, PaymentIntent $intent, LockedOrder $order ): bool {
		$currency = $result->amount->currency();

		if ( ! $currency->equals( $order->currency() ) || ! $currency->equals( $intent->amount->currency() ) ) {
			return false;
		}

		if ( ! $intent->baseAmount->currency()->equals( $order->baseCurrency() ) ) {
			return false;
		}

		return match ( $result->operation ) {
			Operation::Authorize => $result->amount->equals( $intent->amount ) && self::fits( $order->authorized, $result->amount, $order->grandTotal ),
			Operation::Capture   => self::capturable( $result->amount, $intent ) && self::fits( $order->paid, $result->amount, $order->grandTotal ),
			Operation::Void      => $result->amount->equals( $intent->amount ),
			Operation::Refund    => true,
		};
	}

	/**
	 * Tells whether a capture takes more than nothing and at most what the intent may capture.
	 *
	 * @since 0.2.0
	 *
	 * @param Money         $amount The capture.
	 * @param PaymentIntent $intent The intent, locked: authorized, or still processing.
	 * @return bool True when it may.
	 */
	private static function capturable( Money $amount, PaymentIntent $intent ): bool {
		$cap = IntentStatus::Processing === $intent->status ? $intent->amount : $intent->authorized;

		return ! $amount->isZero() && ! $amount->isNegative() && $amount->compare( $cap ) <= 0;
	}

	/**
	 * Tells whether a tender keeps what the order has tendered within its grand total.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $tendered What the order has tendered so far by this operation.
	 * @param Money $amount   The tender.
	 * @param Money $total    The order's grand total.
	 * @return bool True when it fits.
	 */
	private static function fits( Money $tendered, Money $amount, Money $total ): bool {
		return $tendered->add( $amount )->compare( $total ) <= 0;
	}
}
