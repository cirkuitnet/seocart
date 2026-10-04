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
 * and the intent's base currency the order's. An authorization or a capture must be for exactly
 * the intent's frozen amount, which was frozen from the order's total for this tender, and must
 * not bring what the order has tendered past its grand total. A refund's amount is capped by the
 * statement that applies it, against what the intent captured.
 *
 * The intent and the order are read with locking reads before the check, so what it compares is
 * current: this is not a check-then-act. The intent's update requires the currencies again, and
 * the projection's update carries the order's currencies and caps, in their WHERE clauses, as a
 * belt.
 *
 * @since 0.1.0
 */
final class AmountCheck {

	/**
	 * Tells whether an approval matches its intent and its order.
	 *
	 * @since 0.1.0
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
			Operation::Capture   => $result->amount->equals( $intent->amount ) && self::fits( $order->paid, $result->amount, $order->grandTotal ),
			Operation::Refund,
			Operation::Void      => true,
		};
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
