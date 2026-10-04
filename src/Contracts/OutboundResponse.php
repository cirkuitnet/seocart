<?php
/**
 * OutboundResponse: the answer an external service gave
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report an unreadable answer to the developer; they are never HTML.

defined( 'ABSPATH' ) || exit;

/**
 * What HttpClient::send() returns: any HTTP status, with its headers and body.
 *
 * Owns one fact: the shape of an answer. Every status is an answer, a redirect and an error
 * included; what it means is the caller's to decide. The body is at most the client's size
 * limit. It may hold provider data that must never be logged or stored as it is: map what is
 * needed out of it, then let it go.
 *
 * @since 0.2.0
 * @api
 */
final readonly class OutboundResponse {

	/**
	 * Records the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param int                   $status  The HTTP status code.
	 * @param array<string, string> $headers Header values keyed by lower-case name. A field sent
	 *                                       more than once has its values joined with ", ", as
	 *                                       RFC 9110 allows. The client neither sends nor keeps
	 *                                       cookies, so a Set-Cookie field is passed through like
	 *                                       any other and nothing in SEOCart reads it.
	 * @param string                $body    The body, decompressed.
	 */
	public function __construct(
		public int $status,
		public array $headers,
		#[\SensitiveParameter]
		public string $body
	) {
	}

	/**
	 * Decodes the body as a JSON object or array.
	 *
	 * Integers too large for PHP's int are kept as strings, so a provider's long number is never
	 * rounded.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the body is not JSON, or is JSON but not an object or an
	 *                                   array. The message never quotes the body.
	 *
	 * @return array<mixed> The decoded body.
	 */
	public function json(): array {
		try {
			$decoded = json_decode( $this->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $failure ) {
			throw new \UnexpectedValueException( 'The response body is not JSON.', 0, $failure );
		}

		if ( ! is_array( $decoded ) ) {
			throw new \UnexpectedValueException( 'The response body is JSON, but not an object or an array.' );
		}

		return $decoded;
	}
}
