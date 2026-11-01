<?php
/**
 * CurrencyTerms: the port through which the catalog learns which currencies the store sells in, and what a base price is worth in one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Answers, for the catalog's price projection and its listing, the questions only pricing can answer.
 *
 * Owns one fact: what the catalog may ask about currencies. Which currencies prices are offered in
 * now, at which exchange-rate version, and what a base-currency price becomes in another currency.
 * The catalog never converts or rounds money itself: it asks here, and pricing answers with the one
 * rule a cart's price is converted by, so a product sorts at the price its cart shows. Pricing
 * implements it (PricingCurrencyTerms); the catalog imports nothing of pricing.
 *
 * @since 0.2.0
 */
interface CurrencyTerms {

	/**
	 * Returns the current exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null The version every converted price is converted at now, or null when no rate was ever saved.
	 */
	public function rateVersion(): ?int;

	/**
	 * Returns the currencies prices are offered in now.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Currency> The base currency first, then every enabled currency with a rate at the current version, by code.
	 */
	public function offered(): array;

	/**
	 * Tells whether prices are offered in a currency now.
	 *
	 * @since 0.2.0
	 *
	 * @param Currency $currency The currency.
	 * @return bool True for the base currency, and for an enabled currency with a rate at the current version.
	 */
	public function isOffered( Currency $currency ): bool;

	/**
	 * Returns a base-currency price in another currency, as a cart in that currency prices it.
	 *
	 * @since 0.2.0
	 *
	 * @param Money    $base A price in the store's base currency.
	 * @param Currency $to   The currency.
	 * @return Money|null The price unchanged for the base currency; converted at the current rate and rounded
	 *                    by the currency's rule and its cash step when the currency allows a base price to be
	 *                    sold in it; null when it does not, or prices are not offered in it.
	 */
	public function convert( Money $base, Currency $to ): ?Money;
}
