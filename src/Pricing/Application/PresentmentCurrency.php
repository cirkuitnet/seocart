<?php
/**
 * PresentmentCurrency: the terms a cart in one currency is priced under
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\ConversionContext;
use SEOCart\Support\Currency;
use SEOCart\Support\CurrencyRoundingRule;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a caller's mistake, for the developer; it is never rendered.

/**
 * A currency the store sells in, with what pricing a cart in it depends on.
 *
 * Owns one fact: the terms of one currency at the current exchange-rate version. They are its
 * rounding rule, whether a variant priced only in the base currency may be sold in it at a
 * converted price, and the current rate from the base currency, as the conversion context every
 * converted price and every base-currency figure of the calculation uses.
 *
 * The base currency is offered on fixed terms: its default rounding rule, its identity context,
 * and nothing to convert, since every price is authored in it.
 *
 * @since 0.1.0
 */
final readonly class PresentmentCurrency {

	/**
	 * Holds the terms.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the rounding rule is not the one of the context's quote currency.
	 *
	 * @param CurrencyRoundingRule $roundingRule              How amounts in the currency are rounded.
	 * @param bool                 $conversionFallbackAllowed Whether a variant without a price in the currency may be sold at its converted base price.
	 * @param ConversionContext    $context                   The current rate from the base currency to this one.
	 */
	public function __construct(
		public CurrencyRoundingRule $roundingRule,
		public bool $conversionFallbackAllowed,
		public ConversionContext $context
	) {
		if ( ! $roundingRule->currency()->equals( $context->quoteCurrency() ) ) {
			throw new \InvalidArgumentException( 'A currency\'s rounding rule and its rate from the base currency are of the same currency.' );
		}
	}

	/**
	 * Returns the terms of the base currency: its default rounding rule, its identity context, and no conversion.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $base The store's base currency.
	 * @return self The terms.
	 */
	public static function base( Currency $base ): self {
		return new self( CurrencyRoundingRule::defaultFor( $base ), false, ConversionContext::identity( $base ) );
	}

	/**
	 * Returns the currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency a cart is priced in.
	 */
	public function currency(): Currency {
		return $this->context->quoteCurrency();
	}

	/**
	 * Returns the store's base currency, which converted prices and base figures come from.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The base currency.
	 */
	public function baseCurrency(): Currency {
		return $this->context->baseCurrency();
	}
}
