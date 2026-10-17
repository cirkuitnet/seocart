<?php
/**
 * HttpWatch: every request WordPress's HTTP API is asked to send, counted, and the ones the gateway's client did not send refused
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Watches WordPress's HTTP API while the suite runs: it counts every request and refuses, before it is sent, each one the gateway's own client is not sending at that moment.
 *
 * Owns one fact: what reached WordPress's HTTP API around the gateway's client. Its filter runs
 * first on `pre_http_request`, which every `wp_remote_*()` call passes through. The recording
 * transport announces the URL it is about to send (expecting()); any other request is counted as
 * foreign and answered with an error, so a gateway that calls `wp_remote_post()` itself is caught
 * and nothing it sends that way reaches the network.
 *
 * @since 0.2.0
 */
final class HttpWatch {

	/**
	 * The error code of a refused foreign request.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUSED = 'seocart_conformance_foreign_request';

	/**
	 * The URL the gateway's client is sending now; null between requests.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private ?string $expected = null;

	/**
	 * Every request seen.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $seen = 0;

	/**
	 * The URLs of the requests sent outside the gateway's client.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $foreign = array();

	/**
	 * Starts watching. The test's hooks are restored after each test, which removes the filter.
	 *
	 * @since 0.2.0
	 */
	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'observe' ), PHP_INT_MIN, 3 );
	}

	/**
	 * Counts a request, and refuses it unless it is the one the gateway's client is sending.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $pre  What an earlier filter answered; false when none did.
	 * @param mixed $args The request's arguments.
	 * @param mixed $url  The request's URL.
	 * @return mixed The earlier answer for the client's request; an error for any other.
	 */
	public function observe( mixed $pre, mixed $args, mixed $url ): mixed {
		unset( $args );
		++$this->seen;

		if ( null !== $this->expected && $url === $this->expected ) {
			return $pre;
		}

		$this->foreign[] = is_string( $url ) ? $url : '(not a URL)';

		return new \WP_Error( self::REFUSED, 'The conformance suite refused a request sent outside the gateway\'s HTTP client.' );
	}

	/**
	 * Runs the client's send of one URL, during which a request to that URL is the client's own.
	 *
	 * @since 0.2.0
	 *
	 * @template T
	 *
	 * @param string   $url  The URL being sent.
	 * @param \Closure $send The send.
	 * @return mixed What the send returns.
	 *
	 * @phpstan-param \Closure(): T $send
	 * @phpstan-return T
	 */
	public function expecting( string $url, \Closure $send ): mixed {
		$this->expected = $url;

		try {
			return $send();
		} finally {
			$this->expected = null;
		}
	}

	/**
	 * Returns how many requests WordPress's HTTP API was asked to send.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	public function seen(): int {
		return $this->seen;
	}

	/**
	 * Returns the URLs of the requests sent outside the gateway's client.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The URLs, in order.
	 */
	public function foreign(): array {
		return $this->foreign;
	}
}
