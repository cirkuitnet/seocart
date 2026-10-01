<?php
/**
 * PresentmentCurrencies: finds the terms a cart in a currency is priced under
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * The port through which a calculation learns whether, and on what terms, it may price in a currency.
 *
 * Owns one fact: which currencies prices are offered in now. The base currency always is, on
 * fixed terms and without a read. Any other currency is offered when the merchant enabled it and
 * the current exchange-rate version has a rate for it from the base currency; that rate is the
 * one every calculation in the currency converts at, until a new version is saved.
 *
 * @since 0.1.0
 */
interface PresentmentCurrencies {

	/**
	 * Returns the terms of a currency, or null when prices are not offered in it.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $base     The store's base currency.
	 * @param Currency $currency The currency a cart is in.
	 * @return PresentmentCurrency|null The terms; PresentmentCurrency::base() for the base currency;
	 *                                  null for a currency that is not enabled, or has no rate in the
	 *                                  current version.
	 */
	public function find( Currency $base, Currency $currency ): ?PresentmentCurrency;
}
