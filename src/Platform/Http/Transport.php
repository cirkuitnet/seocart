<?php
/**
 * Transport: what sends a request for the outbound client
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;

defined( 'ABSPATH' ) || exit;

/**
 * The seam under OutboundClient: one request out, one answer back.
 *
 * Owns one fact: where the bytes go. OutboundClient decides whether a request may be sent and
 * what is logged; the transport sends it within the client's limits. WordPressTransport is the
 * only production transport. A test double that answers without the network keeps the
 * transaction guard, which watches `pre_http_request`, by answering through that filter: it
 * wraps WordPressTransport and adds a `pre_http_request` callback that returns the recorded
 * answer, so every request still passes the guard first.
 *
 * @since 0.2.0
 */
interface Transport {

	/**
	 * Sends the request and returns the answer, whatever its status.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When no answer arrived.
	 *
	 * @param OutboundRequest $request        The request, already checked against its declared host.
	 * @param int             $timeoutSeconds How long the whole request may take, in seconds.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request, int $timeoutSeconds ): OutboundResponse;
}
