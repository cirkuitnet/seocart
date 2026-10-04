<?php
/**
 * HttpClient: the one way to call an external service
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Sends a request to a declared external service and returns its answer.
 *
 * Owns one fact: how code that is not WordPress itself talks to another server. Every
 * request goes through WordPress's HTTP API, so the plugin's guards see it; it may only go to
 * a host its caller declared (OutboundHost), only over https with the certificate verified,
 * never follows a redirect, accepts a limited answer, and is never retried by the client.
 *
 * Other plugins can change any request through WordPress's hooks. Where WordPress sends with
 * cURL, as it does wherever PHP has the extension, the client re-asserts its settings at the
 * last hooks WordPress offers before cURL sends: certificate and host-name checks on, no
 * redirect, https only, its timeouts and the request's URL. A call whose arguments or options
 * can no longer be recognised as its own is refused rather than sent. Code in the same
 * process that replaces the Requests library's `hooks` or `transport` option, or that hooks
 * in after the client, can still interfere, and the socket transport WordPress falls back to
 * without cURL runs hooks of its own after the client's.
 *
 * Never call it inside a database transaction: a development site throws, and any other site
 * reports the call and sends it.
 *
 *     $response = $http->send( new OutboundRequest( 'example-rates', 'GET', 'https://api.example.com/v1/rates' ) );
 *     $rates    = $response->json();
 *
 * @since 0.2.0
 * @api
 */
interface HttpClient {

	/**
	 * Sends the request and returns the answer, whatever its status.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When the connection, the TLS handshake or the timeout failed, or the
	 *                          answer was too large.
	 * @throws \LogicException  When the request is not for a declared host, or not over https: a
	 *                          programming error, never a runtime failure.
	 *
	 * @param OutboundRequest $request The request.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request ): OutboundResponse;
}
