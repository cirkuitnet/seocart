<?php
/**
 * TransportFailure: a request to an external service ended without a usable answer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by HttpClient::send() when no answer arrived, or the one that arrived was too large.
 *
 * Owns one fact: why a request got no answer, as one of four kinds. A connection, timeout or
 * TLS failure may still have reached the service, so a caller that moves money must ask the
 * service what happened before it tries again; the client itself never retries.
 *
 * The message names the kind and the host, and nothing else: never a URL's path or query, a
 * header or a body.
 *
 * @since 0.2.0
 * @api
 */
final class TransportFailure extends \RuntimeException {

	/**
	 * The host could not be reached, or the connection broke off.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONNECT = 'connect';

	/**
	 * The answer did not arrive within the timeout.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TIMEOUT = 'timeout';

	/**
	 * The TLS handshake failed, or the host's certificate could not be verified.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TLS = 'tls';

	/**
	 * The answer was larger than the client accepts.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SIZE = 'size';

	/**
	 * Why the request failed: one of the constants above.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $kind;

	/**
	 * The host the request was sent to.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public readonly string $host;

	/**
	 * Creates the failure.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kind One of CONNECT, TIMEOUT, TLS and SIZE.
	 * @param string $host The host the request was sent to.
	 */
	public function __construct( string $kind, string $host ) {
		$this->kind = $kind;
		$this->host = $host;

		parent::__construct( self::describe( $kind, $host ) );
	}

	/**
	 * Says what happened, in one sentence that names only the kind and the host.
	 *
	 * @since 0.2.0
	 *
	 * @param string $kind The kind.
	 * @param string $host The host.
	 * @return string The message.
	 */
	private static function describe( string $kind, string $host ): string {
		return match ( $kind ) {
			self::TIMEOUT => "The request to {$host} timed out.",
			self::TLS     => "The secure connection to {$host} could not be established or verified.",
			self::SIZE    => "The answer from {$host} was larger than the client accepts.",
			default       => "The connection to {$host} could not be made, or broke off.",
		};
	}
}
