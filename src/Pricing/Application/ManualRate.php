<?php
/**
 * ManualRate: one exchange rate a merchant typed in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name a caller's mistake, for the developer; they are never rendered.

/**
 * A rate from the base currency to one other currency, as the merchant quoted it.
 *
 * Owns one fact: what a merchant may save as a rate. It reads "1 unit of the base currency is
 * `rate` units of the quote currency", and keeps the scale it was written at, up to twelve
 * places: 0.91230 is kept at five, so a calculation converts at exactly what was typed.
 *
 * @since 0.1.0
 */
final readonly class ManualRate {

	/**
	 * The most fractional digits a rate may have.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAXIMUM_SCALE = 12;

	/**
	 * Checks and holds the rate.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the two currencies are the same, the rate is not above
	 *                                   zero, or it has more than MAXIMUM_SCALE fractional digits.
	 *
	 * @param Currency $base  The currency one unit of which the rate prices: the store's base currency.
	 * @param Currency $quote The currency the rate is expressed in.
	 * @param Decimal  $rate  The rate, at the scale it was quoted at.
	 */
	public function __construct(
		public Currency $base,
		public Currency $quote,
		public Decimal $rate
	) {
		if ( $base->equals( $quote ) ) {
			throw new \InvalidArgumentException( 'A rate goes from the base currency to another currency.' );
		}

		if ( 1 !== $rate->sign() || $rate->scale() > self::MAXIMUM_SCALE ) {
			throw new \InvalidArgumentException( sprintf( 'An exchange rate is greater than zero, with at most %d decimal places.', self::MAXIMUM_SCALE ) );
		}
	}
}
