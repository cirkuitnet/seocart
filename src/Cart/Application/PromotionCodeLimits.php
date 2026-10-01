<?php
/**
 * PromotionCodeLimits: the caps on the promotion codes a cart, and a client, may try
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
 * The two caps on promotion codes tried: per cart, and per client across its carts.
 *
 * Owns one fact: how many codes may be tried in an hour before every code is refused, valid or
 * not, for the rest of it. Trying codes one after another is how a stranger would look for valid
 * ones, so each code tried is counted against the cart and against its client before it is looked
 * at, the valid ones too: a code is counted before anyone knows whether it is valid, which is what
 * keeps codes tried at once from passing a cap together. A cart holds at most five codes, so a
 * shopper's own codes take little of the cap. A client may start a new cart to try ten more codes;
 * the client's cap limits that, while leaving room for shoppers who share one address, such as an
 * office's. The cart service enforces both caps; the apply operation's declaration states them.
 *
 * @since 0.1.0
 */
final class PromotionCodeLimits {

	/**
	 * What the cap of one cart counts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CART_BUCKET = 'cart.code_tried';

	/**
	 * The most codes one cart may try in a window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CART_LIMIT = 10;

	/**
	 * What the cap of one client counts, across its carts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLIENT_BUCKET = 'cart.code_tried_by_client';

	/**
	 * The most codes one client may try in a window, across its carts.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CLIENT_LIMIT = 40;

	/**
	 * The window both caps count in: an hour.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WINDOW_SECONDS = 3600;

	/**
	 * Cannot be called: the caps are constants and static functions.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Returns the cap of one cart.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit CART_LIMIT codes per WINDOW_SECONDS.
	 */
	public static function perCart(): RateLimit {
		return new RateLimit( self::CART_BUCKET, self::CART_LIMIT, self::WINDOW_SECONDS );
	}

	/**
	 * Returns the cap of one client, across its carts.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit CLIENT_LIMIT codes per WINDOW_SECONDS.
	 */
	public static function perClient(): RateLimit {
		return new RateLimit( self::CLIENT_BUCKET, self::CLIENT_LIMIT, self::WINDOW_SECONDS );
	}
}
