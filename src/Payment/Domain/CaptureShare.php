<?php
/**
 * CaptureShare: the base-currency share of a capture of part of an intent's amount
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain;

use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Splits an intent's frozen base amount between what a capture takes and what it leaves, by largest remainder.
 *
 * Owns one fact: what a capture adds in the base currency. A capture of the intent's whole amount
 * adds its whole frozen base amount, which was frozen from the order's totals with it. A capture of
 * less, where the gateway declares one, adds the share of the frozen base amount that its amount is
 * of the intent's, by largest remainder, so the share and what is left of the frozen amount always
 * add up to it, and no rate is read. It splits a stored figure; it computes no total.
 *
 * @since 0.2.0
 */
final class CaptureShare {

	/**
	 * Returns the base-currency amount a capture adds.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the capture is in another currency than the intent, or for more than its amount.
	 *
	 * @param PaymentIntent $intent   The intent, with its frozen amount and base amount.
	 * @param Money         $captured What the capture takes, in the intent's currency: at most its amount.
	 * @return Money The capture's share of the intent's base amount.
	 */
	public static function baseOf( PaymentIntent $intent, Money $captured ): Money {
		if ( $captured->equals( $intent->amount ) ) {
			return $intent->baseAmount;
		}

		$left = $intent->amount->subtract( $captured );

		if ( $left->isNegative() ) {
			throw new \InvalidArgumentException( 'A capture takes at most the intent\'s amount.' );
		}

		return $intent->baseAmount->allocate( array( $captured->minorUnits(), $left->minorUnits() ) )[0];
	}
}
