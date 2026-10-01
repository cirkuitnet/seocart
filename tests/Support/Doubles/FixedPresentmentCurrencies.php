<?php
/**
 * FixedPresentmentCurrencies: the currencies a unit test offers prices in, held in memory
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Pricing\Application\PresentmentCurrency;
use SEOCart\Support\Currency;

/**
 * Offers the base currency and the currencies a test gives it, and records every currency it was asked about.
 *
 * Owns one fact: the presentment currencies of a test without a database. The base currency is
 * offered on its fixed terms, as the port promises; any other only when the test gave its terms.
 *
 * @since 0.1.0
 */
final class FixedPresentmentCurrencies implements PresentmentCurrencies {

	/**
	 * The terms of each currency offered besides the base one, by ISO code.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, PresentmentCurrency>
	 */
	private array $offered = array();

	/**
	 * Every currency asked about, in order, as ISO codes.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public array $asked = array();

	/**
	 * Offers the currencies given, besides the base one.
	 *
	 * @since 0.1.0
	 *
	 * @param PresentmentCurrency ...$offered The terms of each.
	 */
	public function __construct( PresentmentCurrency ...$offered ) {
		foreach ( $offered as $currency ) {
			$this->offered[ $currency->currency()->code() ] = $currency;
		}
	}

	/**
	 * Returns the terms of a currency, or null when it is not offered.
	 *
	 * @since 0.1.0
	 *
	 * @param Currency $base     The store's base currency.
	 * @param Currency $currency The currency a cart is in.
	 * @return PresentmentCurrency|null The terms.
	 */
	public function find( Currency $base, Currency $currency ): ?PresentmentCurrency {
		$this->asked[] = $currency->code();

		return $currency->equals( $base ) ? PresentmentCurrency::base( $base ) : ( $this->offered[ $currency->code() ] ?? null );
	}
}
