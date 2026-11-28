<?php
/**
 * StoreConfig: what the storefront's store module is given by the page
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Storefront;

use SEOCart\Platform\Localization\RequestLanguage;
use SEOCart\Support\Currency;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the configuration of the Interactivity store `seocart/store` once a request: where the Store API is, where a logged-in shopper's nonce comes from, the page's locale, currency and exchange-rate version, and every word the storefront shows.
 *
 * Owns one fact: what the page tells the store module. Nothing in it depends on the visitor, so a
 * page cache may serve the page to anyone: the nonce a logged-in shopper's write needs is fetched
 * by the module from WordPress's `rest-nonce` action when the Store API asks for it, never
 * printed. The currency is the one the page's prices are in, and the exchange-rate version the
 * one they were read under.
 *
 * @since 0.2.0
 */
final class StoreConfig {

	/**
	 * The store's namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STORE = 'seocart/store';

	/**
	 * Returns the page's currency.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): Currency
	 */
	private \Closure $currency;

	/**
	 * Returns the current exchange-rate version, or null when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): (int|null)
	 */
	private \Closure $priceVersion;

	/**
	 * The language the request's words are in.
	 *
	 * @since 0.2.0
	 *
	 * @var RequestLanguage
	 */
	private RequestLanguage $language;

	/**
	 * Whether the configuration was printed in this request.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $printed = false;

	/**
	 * Creates the configuration. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param callable        $currency     Returns the page's currency: the store's base currency.
	 * @param callable        $priceVersion Returns the current exchange-rate version, or null.
	 * @param RequestLanguage $language     The language the request's words are in.
	 *
	 * @phpstan-param callable(): Currency   $currency
	 * @phpstan-param callable(): (int|null) $priceVersion
	 */
	public function __construct( callable $currency, callable $priceVersion, RequestLanguage $language ) {
		$this->currency     = \Closure::fromCallable( $currency );
		$this->priceVersion = \Closure::fromCallable( $priceVersion );
		$this->language     = $language;
	}

	/**
	 * Prints the configuration, the first time a block asks in a request.
	 *
	 * @since 0.2.0
	 */
	public function print(): void {
		if ( $this->printed ) {
			return;
		}

		$this->printed = true;

		wp_interactivity_config( self::STORE, $this->config() );
	}

	/**
	 * Returns the configuration.
	 *
	 * @since 0.2.0
	 *
	 * @return array{restRoot: string, nonceUrl: string, locale: string, currency: string, priceVersion: int|null, strings: array<string, string>} The configuration.
	 */
	public function config(): array {
		return array(
			'restRoot'     => esc_url_raw( rest_url() ),
			'nonceUrl'     => esc_url_raw( admin_url( 'admin-ajax.php?action=rest-nonce' ) ),
			'locale'       => $this->language->locale()->toBcp47(),
			'currency'     => ( $this->currency )()->code(),
			'priceVersion' => ( $this->priceVersion )(),
			'strings'      => StorefrontStrings::all(),
		);
	}
}
