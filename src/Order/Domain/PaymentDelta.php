<?php
/**
 * PaymentDelta: what one payment ledger row adds to an order's payment projection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyMismatchException;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The amounts one applied ledger row moves: authorized, captured and refunded, with their base twins.
 *
 * Owns one fact: the change a payment makes to an order's authorized, paid and refunded amounts.
 * The payment module builds it from the ledger row it appends; the order adds it in one
 * conditional update, so the projection always equals the ledger it is derived from.
 *
 * @since 0.1.0
 */
final readonly class PaymentDelta {

	/**
	 * Records the delta, refusing a negative amount or mixed currencies.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an amount is negative.
	 * @throws CurrencyMismatchException When the three amounts, or their three base twins, are not in one currency.
	 *
	 * @param Money $authorized     Added to authorized_minor.
	 * @param Money $captured       Added to paid_minor.
	 * @param Money $refunded       Added to refunded_minor.
	 * @param Money $baseAuthorized Added to base_authorized_minor.
	 * @param Money $baseCaptured   Added to base_paid_minor.
	 * @param Money $baseRefunded   Added to base_refunded_minor.
	 */
	public function __construct(
		public Money $authorized,
		public Money $captured,
		public Money $refunded,
		public Money $baseAuthorized,
		public Money $baseCaptured,
		public Money $baseRefunded
	) {
		self::requireNotNegative( $authorized, $captured, $refunded, $baseAuthorized, $baseCaptured, $baseRefunded );

		CurrencyMismatchException::assertSameCurrency( $authorized->currency(), $captured->currency() );
		CurrencyMismatchException::assertSameCurrency( $authorized->currency(), $refunded->currency() );
		CurrencyMismatchException::assertSameCurrency( $baseAuthorized->currency(), $baseCaptured->currency() );
		CurrencyMismatchException::assertSameCurrency( $baseAuthorized->currency(), $baseRefunded->currency() );
	}

	/**
	 * Refuses a negative amount: a payment only ever adds to what was authorized, captured or refunded.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an amount is negative.
	 *
	 * @param Money ...$amounts The amounts.
	 */
	private static function requireNotNegative( Money ...$amounts ): void {
		foreach ( $amounts as $amount ) {
			if ( $amount->isNegative() ) {
				throw new \InvalidArgumentException( 'A payment adds to an order\'s authorized, paid and refunded amounts; none of them is negative.' );
			}
		}
	}

	/**
	 * Returns the currency of the three amounts.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The order's currency, when the delta is the order's.
	 */
	public function currency(): Currency {
		return $this->authorized->currency();
	}

	/**
	 * Returns the currency of the three base twins.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The order's base currency, when the delta is the order's.
	 */
	public function baseCurrency(): Currency {
		return $this->baseAuthorized->currency();
	}
}
