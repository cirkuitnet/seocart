<?php
/**
 * StoreApiError: the refusals of the Store API's request policy
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors a Store API write is refused with before it runs.
 *
 * Owns one fact: how the Store API's request policy tells a client why it refused a write. Each
 * refusal has its own code, so a client can act on it: send the write as a write, send the header,
 * fetch a nonce, send the cart token, or wait. None of them names the client, the cart or the counts.
 *
 * @since 0.1.0
 */
enum StoreApiError: string implements ErrorCode {

	/**
	 * The write arrived as a GET or HEAD, whatever method it names.
	 *
	 * @since 0.1.0
	 */
	case ReadMethod = 'store_api.read_method';

	/**
	 * The write does not carry the Store API's request header.
	 *
	 * @since 0.1.0
	 */
	case HeaderMissing = 'store_api.header_missing';

	/**
	 * The request carries a valid login cookie but no nonce, so WordPress would run it as a guest.
	 *
	 * @since 0.1.0
	 */
	case NonceMissing = 'store_api.nonce_missing';

	/**
	 * The write changes an existing cart, and the request carries no cart token.
	 *
	 * @since 0.1.0
	 */
	case CartTokenMissing = 'store_api.cart_token_missing';

	/**
	 * The client has sent more requests to the write's bucket than its window allows.
	 *
	 * @since 0.1.0
	 */
	case RateLimited = 'store_api.rate_limited';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::ReadMethod,
				405,
				static fn(): string => __( 'This request changes the store, so it must be sent as a POST, PUT, PATCH or DELETE request. A GET or HEAD request never changes it, whatever method it names.', 'seocart' )
			),
			new ErrorDefinition(
				self::HeaderMissing,
				403,
				static fn(): string => __( 'This request changes the store, so it must carry the header X-SEOCart-Store: 1.', 'seocart' )
			),
			new ErrorDefinition(
				self::NonceMissing,
				403,
				static fn(): string => __( 'You are logged in, but this request carries no valid nonce, so it would run as a guest. Fetch a new nonce, send it as X-WP-Nonce, and try again.', 'seocart' )
			),
			new ErrorDefinition(
				self::CartTokenMissing,
				400,
				static fn(): string => __( 'This request changes an existing cart, so it must carry the cart\'s token.', 'seocart' )
			),
			new ErrorDefinition(
				self::RateLimited,
				429,
				static fn(): string => __( 'Too many requests. Wait a moment, then try again.', 'seocart' )
			),
		);
	}
}
