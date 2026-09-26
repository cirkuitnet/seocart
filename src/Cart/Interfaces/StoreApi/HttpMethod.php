<?php
/**
 * HttpMethod: tells whether a Store API request is a write the way it arrived over HTTP
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The one test of whether a request may change the store and carry a new cart token.
 *
 * Owns one fact: which requests count as writes. WordPress routes a request by the method it names,
 * and lets a `_method` parameter or an `X-HTTP-Method-Override` header name another than the one it
 * arrived with. So a GET, which a prefetch, a crawler or a cache may send and which a page cache
 * may keep, can be routed as a POST. A request is a write only when both methods are: the one
 * WordPress routes it by, and the one it arrived with (`$_SERVER['REQUEST_METHOD']`). A request
 * that did not arrive over HTTP at all, such as a dispatch from the command line, is not one.
 *
 * @since 0.1.0
 */
final class HttpMethod {

	/**
	 * The methods of a write.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const WRITES = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Cannot be called: the test is a static function.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Tells whether a request is a write, as WordPress routes it and as it arrived over HTTP.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request WordPress routes.
	 * @return bool True when both methods are POST, PUT, PATCH or DELETE.
	 */
	public static function isWrite( WP_REST_Request $request ): bool {
		$arrived = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';

		return in_array( strtoupper( $request->get_method() ), self::WRITES, true ) && in_array( $arrived, self::WRITES, true );
	}
}
