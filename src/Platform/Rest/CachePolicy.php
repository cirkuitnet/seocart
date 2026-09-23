<?php
/**
 * CachePolicy: the caching headers of a plugin REST response
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Rest;

use WP_HTTP_Response;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Says how a response of a plugin REST route may be cached: not at all.
 *
 * This class owns one fact: the caching headers a plugin REST response carries. Every response is
 * `Cache-Control: no-store, private`: an operation's answer depends on who asks and changes with
 * the store, so neither a browser nor a shared cache may keep it. A response to a request that
 * WordPress authenticated with its login cookie also carries `Vary: Cookie`, so a cache that
 * ignores `no-store` still cannot hand one user's answer to another. A read that is safe to cache
 * publicly would be a second policy; no route needs one yet.
 *
 * The REST adapter applies it to every response of an operation route: successes, the errors the
 * service raised, and the requests WordPress refused before the service ran, with apply(). As the
 * response is served it sends the same headers again with send(), because two things between the
 * response and the client would otherwise lose them: WordPress replaces Cache-Control with its own
 * no-cache header for a logged-in user, and `?_envelope` moves the response's headers into the
 * body of a new response that has none.
 *
 * @since 0.1.0
 */
final class CachePolicy {

	/**
	 * The Cache-Control header of every response.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CACHE_CONTROL = 'no-store, private';

	/**
	 * The request header a cookie-authenticated response varies by.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const VARY_COOKIE = 'Cookie';

	/**
	 * Cannot be called: the policy is used through its static functions.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Sets the caching headers of a response.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Response $response The response.
	 * @return WP_REST_Response The same response, with `Cache-Control` set and, for a
	 *                          cookie-authenticated request, `Cookie` added to `Vary`.
	 */
	public static function apply( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', self::CACHE_CONTROL );

		if ( self::isCookieAuthenticated() ) {
			$response->header( 'Vary', self::withCookie( (string) ( $response->get_headers()['Vary'] ?? '' ) ) );
		}

		return $response;
	}

	/**
	 * Sends the caching headers while WordPress serves a response, after its own headers.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server   $server The server sending the response.
	 * @param WP_HTTP_Response $served The response being served, whose `Vary` is kept.
	 */
	public static function send( WP_REST_Server $server, WP_HTTP_Response $served ): void {
		$server->send_header( 'Cache-Control', self::CACHE_CONTROL );

		if ( self::isCookieAuthenticated() ) {
			$server->send_header( 'Vary', self::withCookie( (string) ( $served->get_headers()['Vary'] ?? '' ) ) );
		}
	}

	/**
	 * Tells whether WordPress authenticated the current request with its login cookie.
	 *
	 * WordPress records that the login cookie was valid while it determines the user, and logs the
	 * user out again when a REST request carries the cookie without a valid nonce. So the request is
	 * cookie-authenticated when the cookie was valid and a user is still logged in; a user
	 * authenticated otherwise, such as with an application password, is not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the user was authenticated by the login cookie.
	 */
	public static function isCookieAuthenticated(): bool {
		global $wp_rest_auth_cookie;

		return true === $wp_rest_auth_cookie && 0 !== get_current_user_id();
	}

	/**
	 * Adds Cookie to a Vary header, once.
	 *
	 * @since 0.1.0
	 *
	 * @param string $vary The Vary header so far, possibly empty.
	 * @return string The header naming Cookie, with every value it named before.
	 */
	private static function withCookie( string $vary ): string {
		$values = array_values( array_filter( array_map( 'trim', explode( ',', $vary ) ), static fn( string $value ): bool => '' !== $value ) );

		if ( ! in_array( strtolower( self::VARY_COOKIE ), array_map( 'strtolower', $values ), true ) ) {
			$values[] = self::VARY_COOKIE;
		}

		return implode( ', ', $values );
	}
}
