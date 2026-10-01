<?php
/**
 * CurrencyChangeLimit: the cap on how often one cart may switch its currency
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Application;

use SEOCart\Platform\RateLimiter\RateLimit;

defined( 'ABSPATH' ) || exit;

/**
 * The cap on the currency switches of one cart.
 *
 * Owns one fact: how many times a cart may switch its currency in an hour. Each switch reprices
 * the cart, its shipping and its tax, so a client switching back and forth would make the store
 * calculate without end. The cart service counts every switch of a cart found by the request's
 * token, refused ones too, before its compare-and-swap; a token that names no cart starts no
 * count. The switch's declaration states the cap.
 *
 * @since 0.1.0
 */
final class CurrencyChangeLimit {

	/**
	 * What the cap counts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const BUCKET = 'cart.currency_change';

	/**
	 * The most switches one cart may make in a window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIMIT = 10;

	/**
	 * The window the cap counts in: an hour.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 3600;

	/**
	 * Cannot be called: the cap is constants and a static function.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Returns the cap of one cart.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit LIMIT switches per WINDOW_SECONDS.
	 */
	public static function perCart(): RateLimit {
		return new RateLimit( self::BUCKET, self::LIMIT, self::WINDOW_SECONDS );
	}
}
