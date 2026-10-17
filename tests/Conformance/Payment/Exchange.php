<?php
/**
 * Exchange: one request a gateway sent and the answer it got
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * One request a gateway sent through its HTTP client, and the provider's answer, as a tape holds them.
 *
 * Owns one fact: an exchange's content. It is built from what was sent and answered, and from a
 * tape's file; toTape() gives the file's shape. Header names are lower-cased, as HTTP treats them.
 * An exchange whose answer was lost reached the provider, which answered, but the gateway was told
 * the request timed out: what a dropped connection looks like from the gateway's side.
 *
 * @since 0.2.0
 */
final readonly class Exchange {

	/**
	 * The request's headers, by lower-case name.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public array $headers;

	/**
	 * The answer's headers, by lower-case name.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public array $responseHeaders;

	/**
	 * Records the exchange.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $method          The request's method.
	 * @param string                $url             The request's whole URL.
	 * @param array<string, string> $headers         The request's headers.
	 * @param string|null           $body            The request's body.
	 * @param int                   $status          The answer's status.
	 * @param array<string, string> $responseHeaders The answer's headers.
	 * @param string                $responseBody    The answer's body.
	 * @param bool                  $answerLost      Whether the gateway was told the request timed out instead.
	 */
	public function __construct(
		public string $method,
		public string $url,
		array $headers,
		public ?string $body,
		public int $status,
		array $responseHeaders,
		public string $responseBody,
		public bool $answerLost = false
	) {
		$this->headers         = self::lowerCased( $headers );
		$this->responseHeaders = self::lowerCased( $responseHeaders );
	}

	/**
	 * Reads an exchange from a tape's file.
	 *
	 * @since 0.2.0
	 *
	 * @throws \UnexpectedValueException When the file does not hold an exchange.
	 *
	 * @param array<mixed> $taped The decoded file.
	 * @return self The exchange.
	 */
	public static function fromTape( array $taped ): self {
		$request  = $taped['request'] ?? null;
		$response = $taped['response'] ?? null;

		if ( ! is_array( $request ) || ! is_array( $response ) ) {
			throw new \UnexpectedValueException( 'A taped exchange holds a request and a response.' );
		}

		return new self(
			(string) $request['method'],
			'https://' . $request['host'] . $request['path'],
			(array) $request['headers'],
			isset( $request['body'] ) ? (string) $request['body'] : null,
			(int) $response['status'],
			(array) $response['headers'],
			(string) $response['body'],
			isset( $taped['answer_lost'] ) && true === $taped['answer_lost']
		);
	}

	/**
	 * Returns the exchange in a tape file's shape.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, mixed> The request, the response and, when true, answer_lost.
	 */
	public function toTape(): array {
		$taped = array(
			'request'  => array(
				'method'  => $this->method,
				'host'    => $this->host(),
				'path'    => $this->path(),
				'headers' => $this->headers,
				'body'    => $this->body,
			),
			'response' => array(
				'status'  => $this->status,
				'headers' => $this->responseHeaders,
				'body'    => $this->responseBody,
			),
		);

		if ( $this->answerLost ) {
			$taped['answer_lost'] = true;
		}

		return $taped;
	}

	/**
	 * Returns the request's host.
	 *
	 * @since 0.2.0
	 *
	 * @return string The host.
	 */
	public function host(): string {
		return (string) wp_parse_url( $this->url, PHP_URL_HOST );
	}

	/**
	 * Returns the request's path with its query, as a replay compares it.
	 *
	 * @since 0.2.0
	 *
	 * @return string The path, and `?` and the query when there is one.
	 */
	public function path(): string {
		$path  = (string) wp_parse_url( $this->url, PHP_URL_PATH );
		$query = wp_parse_url( $this->url, PHP_URL_QUERY );

		return ( '' === $path ? '/' : $path ) . ( is_string( $query ) && '' !== $query ? '?' . $query : '' );
	}

	/**
	 * Returns the same exchange with other parts.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $parts The parts to replace, by constructor parameter name.
	 * @return self The new exchange.
	 */
	public function with( array $parts ): self {
		return new self(
			(string) ( $parts['method'] ?? $this->method ),
			(string) ( $parts['url'] ?? $this->url ),
			(array) ( $parts['headers'] ?? $this->headers ),
			array_key_exists( 'body', $parts ) ? $parts['body'] : $this->body,
			(int) ( $parts['status'] ?? $this->status ),
			(array) ( $parts['responseHeaders'] ?? $this->responseHeaders ),
			(string) ( $parts['responseBody'] ?? $this->responseBody ),
			(bool) ( $parts['answerLost'] ?? $this->answerLost )
		);
	}

	/**
	 * Lower-cases header names and sorts them, so a tape's headers read the same however they were sent.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $headers The headers.
	 * @return array<string, string> The headers by lower-case name, sorted.
	 */
	private static function lowerCased( array $headers ): array {
		$lowered = array();

		foreach ( $headers as $name => $value ) {
			$lowered[ strtolower( (string) $name ) ] = (string) $value;
		}

		ksort( $lowered, SORT_STRING );

		return $lowered;
	}
}
