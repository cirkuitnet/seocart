<?php
/**
 * OutboundRequest: one request to an external service, as its caller writes it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to its developer; they are never HTML.

defined( 'ABSPATH' ) || exit;

/**
 * A request for HttpClient::send(): which declared service, and what to send it.
 *
 * Owns one fact: the shape of a request. The client decides everything else (timeouts,
 * redirects, certificate checks, the size of an answer), so a caller cannot loosen them.
 *
 * The headers and the body may carry a credential, such as an API key in an Authorization
 * header. Neither is ever logged, and both are hidden from stack traces. Put no personal data
 * in the URL's path, because the path is logged.
 *
 *     new OutboundRequest(
 *         'example-rates',
 *         'POST',
 *         'https://api.example.com/v1/rates',
 *         array( 'Content-Type' => 'application/json' ),
 *         '{"base":"EUR"}'
 *     );
 *
 * @since 0.2.0
 * @api
 */
final readonly class OutboundRequest {

	/**
	 * The methods a request may use.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const METHODS = array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Records the request.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the method is not one of METHODS, a GET request has a
	 *                                   body, or a header's name is not a token or its value is
	 *                                   not one line of text.
	 *
	 * @param string                $hostId  The id of the declared OutboundHost the request is for.
	 * @param string                $method  One of METHODS, in capitals.
	 * @param string                $url     The https:// URL, on the declared host.
	 * @param array<string, string> $headers Optional. Header values, keyed by header name. Default none.
	 * @param string|null           $body    Optional. The body, already encoded; a GET request has none.
	 *                                       Default null, no body.
	 */
	public function __construct(
		public string $hostId,
		public string $method,
		public string $url,
		#[\SensitiveParameter]
		public array $headers = array(),
		#[\SensitiveParameter]
		public ?string $body = null
	) {
		if ( ! in_array( $method, self::METHODS, true ) ) {
			throw new \InvalidArgumentException( 'An outbound request\'s method must be one of ' . implode( ', ', self::METHODS ) . '.' );
		}

		// WordPress sends a GET request's data as its query, and cannot do that with a string.
		if ( 'GET' === $method && null !== $body ) {
			throw new \InvalidArgumentException( 'An outbound GET request cannot have a body.' );
		}

		foreach ( $headers as $name => $value ) {
			if ( ! self::isHeader( $name, $value ) ) {
				throw new \InvalidArgumentException( 'An outbound request\'s headers must map header names (letters, digits and hyphens) to single-line text.' );
			}
		}
	}

	/**
	 * Tells whether a name and a value make one well-formed header.
	 *
	 * The types are checked too: a caller's array is not typed at run time.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $name  The header's name: letters, digits and hyphens.
	 * @param mixed $value The header's value: text with no line break, which would start another
	 *                     header or the body, and no null byte.
	 * @return bool True when the header may be sent.
	 */
	private static function isHeader( mixed $name, #[\SensitiveParameter] mixed $value ): bool {
		return is_string( $name ) && 1 === preg_match( '/^[A-Za-z0-9-]+$/D', $name )
			&& is_string( $value ) && 1 !== preg_match( '/[\r\n\0]/', $value );
	}
}
