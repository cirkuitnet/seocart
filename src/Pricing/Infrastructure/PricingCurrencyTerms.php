<?php
/**
 * PricingCurrencyTerms: the catalog's currency terms, answered by pricing
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure;

use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the catalog which currencies the store sells in now, and converts a base price as a cart does.
 *
 * Owns one fact: how pricing answers the catalog's questions about currencies. The current version
 * is the one the installation record names; the currencies offered are the base currency and those
 * MysqlPresentmentCurrencies::offered() reads; a currency's terms are the ones a cart in it is priced
 * under (MysqlPresentmentCurrencies::find()), and a base price is converted by them, through
 * PresentmentCurrency::convert(), the one formula a cart's converted price comes from. Every read is
 * kept for the request by the currencies' reader.
 *
 * @since 0.2.0
 */
final class PricingCurrencyTerms implements CurrencyTerms {

	/**
	 * Reads the currencies and their terms.
	 *
	 * @since 0.2.0
	 *
	 * @var MysqlPresentmentCurrencies
	 */
	private MysqlPresentmentCurrencies $currencies;

	/**
	 * Returns the current exchange-rate version, or null when none was ever saved.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): (int|null)
	 */
	private \Closure $currentVersion;

	/**
	 * Returns the store's base currency.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): Currency
	 */
	private \Closure $baseCurrency;

	/**
	 * Creates the terms. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param MysqlPresentmentCurrencies $currencies     Reads the currencies and their terms.
	 * @param \Closure                   $currentVersion Returns the current exchange-rate version (int), or null when none was ever saved.
	 * @param \Closure                   $baseCurrency   Returns the store's base currency (Currency).
	 *
	 * @phpstan-param \Closure(): (int|null) $currentVersion
	 * @phpstan-param \Closure(): Currency   $baseCurrency
	 */
	public function __construct( MysqlPresentmentCurrencies $currencies, \Closure $currentVersion, \Closure $baseCurrency ) {
		$this->currencies     = $currencies;
		$this->currentVersion = $currentVersion;
		$this->baseCurrency   = $baseCurrency;
	}

	/**
	 * Returns the current exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null The version, or null when no rate was ever saved.
	 */
	public function rateVersion(): ?int {
		return ( $this->currentVersion )();
	}

	/**
	 * Returns the currencies prices are offered in now.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Currency> The base currency first, then every enabled currency with a rate at the current version, by code.
	 */
	public function offered(): array {
		$base = ( $this->baseCurrency )();

		return array_merge( array( $base ), $this->currencies->offered( $base ) );
	}

	/**
	 * Tells whether prices are offered in a currency now.
	 *
	 * @since 0.2.0
	 *
	 * @param Currency $currency The currency.
	 * @return bool True for the base currency, without a read, and for an enabled currency with a rate at the current version.
	 */
	public function isOffered( Currency $currency ): bool {
		return null !== $this->currencies->find( ( $this->baseCurrency )(), $currency );
	}

	/**
	 * Returns a base-currency price in another currency, as a cart in that currency prices it.
	 *
	 * @since 0.2.0
	 *
	 * @param Money    $base A price in the store's base currency.
	 * @param Currency $to   The currency.
	 * @return Money|null The price unchanged for the base currency; converted and rounded when the currency
	 *                    allows a base price to be sold in it; null otherwise.
	 */
	public function convert( Money $base, Currency $to ): ?Money {
		if ( $to->equals( $base->currency() ) ) {
			return $base;
		}

		$terms = $this->currencies->find( $base->currency(), $to );

		return null !== $terms && $terms->conversionFallbackAllowed ? $terms->convert( $base ) : null;
	}
}
