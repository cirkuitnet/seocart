<?php
/**
 * OutboundClient: the plugin's one HTTP client for external services
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

use SEOCart\Contracts\HttpClient;
use SEOCart\Contracts\OutboundHost;
use SEOCart\Contracts\OutboundRequest;
use SEOCart\Contracts\OutboundResponse;
use SEOCart\Contracts\TransportFailure;
use SEOCart\Platform\Logging\Level;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to its developer; they are never HTML.

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests to the external services it was given, and to nothing else.
 *
 * Owns one fact: the rules every outbound request obeys.
 *
 * - A request names a declared OutboundHost by id; its URL must be https:// on exactly that
 *   host, with no port and no user name or password. Anything else is a \LogicException: a
 *   programming error, refused before anything is sent.
 * - The transport sends it with the certificate verified, a five-second connect timeout, the
 *   host's total timeout (never more than thirty seconds), no redirect followed, and at most
 *   one megabyte of answer read. A larger answer is a TransportFailure, as is no answer.
 * - Nothing is retried: a caller that must try again does so with its own idempotency key.
 * - Each request writes one log line: the URL without its query, the method, the status, the
 *   duration and a SHA-256 hash of the body when an answer came; the URL, the method, the
 *   duration and the kind of failure when none did. Never a header, a body or a query string,
 *   any of which may carry a credential or personal data.
 *
 * Constructing it sends nothing and registers nothing. The transaction guard watches the
 * transport's `pre_http_request` filter, so a request made while a transaction is open
 * throws on a development site and is reported on any other.
 *
 * @since 0.2.0
 */
final class OutboundClient implements HttpClient {

	/**
	 * How long establishing a connection may take, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const CONNECT_TIMEOUT_SECONDS = 5;

	/**
	 * The longest total timeout a host may declare, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_TIMEOUT_SECONDS = 30;

	/**
	 * The largest answer accepted, in bytes: one megabyte. A provider's object is a few kilobytes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * The services this client may call, keyed by id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, OutboundHost>
	 */
	private array $hosts = array();

	/**
	 * Sends the requests.
	 *
	 * @since 0.2.0
	 *
	 * @var Transport
	 */
	private Transport $transport;

	/**
	 * Writes a log line: Logger::log()'s shape.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(Level, string, string, array<mixed>): void
	 */
	private \Closure $log;

	/**
	 * Creates the client. Sends nothing and registers nothing.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When two hosts share an id.
	 *
	 * @param OutboundHost[] $hosts     The services the client may call.
	 * @param Transport      $transport Sends the requests: a WordPressTransport outside tests.
	 * @param callable       $log       Writes a line, as the plugin's Logger::log() does: a level,
	 *                                  a machine code, a fixed message and a context.
	 *
	 * @phpstan-param list<OutboundHost> $hosts
	 * @phpstan-param callable(Level, string, string, array<mixed>): void $log
	 */
	public function __construct( array $hosts, Transport $transport, callable $log ) {
		foreach ( $hosts as $host ) {
			$this->allow( $host );
		}

		$this->transport = $transport;
		$this->log       = \Closure::fromCallable( $log );
	}

	/**
	 * Sends the request to its declared host and returns the answer, whatever its status.
	 *
	 * A request that is not for a declared host, or not over https, is refused with a
	 * \LogicException before anything is sent or logged (see hostFor()).
	 *
	 * @since 0.2.0
	 *
	 * @throws TransportFailure When no answer arrived, or the answer was larger than MAX_RESPONSE_BYTES.
	 *
	 * @param OutboundRequest $request The request.
	 * @return OutboundResponse The answer.
	 */
	public function send( OutboundRequest $request ): OutboundResponse {
		$host    = $this->hostFor( $request );
		$line    = array(
			'url'    => self::withoutQuery( $request->url ),
			'method' => $request->method,
		);
		$started = hrtime( true );

		try {
			$response = $this->transport->send( $request, min( $host->timeoutSeconds, self::MAX_TIMEOUT_SECONDS ) );

			if ( strlen( $response->body ) > self::MAX_RESPONSE_BYTES ) {
				throw new TransportFailure( TransportFailure::SIZE, $host->host );
			}
		} catch ( TransportFailure $failure ) {
			( $this->log )(
				Level::Info,
				ReportCode::Failed->value,
				'An outbound request got no usable answer.',
				$line + array(
					'duration_ms' => self::millisecondsSince( $started ),
					'failure'     => $failure->kind,
				)
			);

			throw $failure;
		}

		( $this->log )(
			Level::Debug,
			ReportCode::Answered->value,
			'An outbound request was answered.',
			$line + array(
				'status'      => $response->status,
				'duration_ms' => self::millisecondsSince( $started ),
				'body_sha256' => self::hash( $response->body ),
			)
		);

		return $response;
	}

	/**
	 * Adds a host to the ones the client may call.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When a host with the same id was added before.
	 *
	 * @param OutboundHost $host The host.
	 */
	private function allow( OutboundHost $host ): void {
		if ( isset( $this->hosts[ $host->id ] ) ) {
			throw new \InvalidArgumentException( "Outbound host \"{$host->id}\" is declared twice." );
		}

		$this->hosts[ $host->id ] = $host;
	}

	/**
	 * Returns the declared host a request is for, after checking the request's URL against it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the host id is not declared, the URL is not https://, or its host,
	 *                         port or credentials are not exactly the declared host's.
	 *
	 * @param OutboundRequest $request The request.
	 * @return OutboundHost The host.
	 */
	private function hostFor( OutboundRequest $request ): OutboundHost {
		$host = $this->hosts[ $request->hostId ] ?? null;

		if ( null === $host ) {
			throw new \LogicException( "Outbound host \"{$request->hostId}\" is not declared to this client." );
		}

		$url = wp_parse_url( $request->url );

		if ( ! is_array( $url ) || 'https' !== ( $url['scheme'] ?? '' ) || ! str_starts_with( $request->url, 'https://' ) ) {
			throw new \LogicException( "A request to outbound host \"{$host->id}\" must use an https:// URL." );
		}

		if ( ( $url['host'] ?? '' ) !== $host->host || isset( $url['port'] ) || isset( $url['user'] ) || isset( $url['pass'] ) ) {
			throw new \LogicException( "A request to outbound host \"{$host->id}\" must go to {$host->host}, with no port and no credentials in its URL." );
		}

		return $host;
	}

	/**
	 * Returns a URL without its query and fragment, for the log.
	 *
	 * @since 0.2.0
	 *
	 * @param string $url A URL hostFor() accepted.
	 * @return string The scheme, host and path.
	 */
	private static function withoutQuery( string $url ): string {
		return substr( $url, 0, strcspn( $url, '?#' ) );
	}

	/**
	 * Hashes an answer's body, for the log: SHA-256 in hexadecimal, in groups of eight joined by colons.
	 *
	 * Not one run of 64 digits: such a run holds 13 to 19 decimal digits in a row in about one
	 * hash in twenty-five, and the logger's card-number filter would remove them whenever they
	 * passed the Luhn check. A colon ends a run of digits for that filter, and a group of eight
	 * is shorter than any card number.
	 *
	 * @since 0.2.0
	 *
	 * @param string $body The body.
	 * @return string The hash, such as `9f86d081:884c7d65:…:b0f00a08`.
	 */
	private static function hash( string $body ): string {
		return implode( ':', str_split( hash( 'sha256', $body ), 8 ) );
	}

	/**
	 * Returns the whole milliseconds since a moment of the monotonic clock.
	 *
	 * @since 0.2.0
	 *
	 * @param int|float $started What hrtime( true ) returned at the start, in nanoseconds.
	 * @return int The milliseconds.
	 */
	private static function millisecondsSince( int|float $started ): int {
		return (int) ( ( hrtime( true ) - $started ) / 1000000 );
	}
}
