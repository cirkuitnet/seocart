<?php
/**
 * ReadRequests: the requests a gateway's test declares only read, though their method is not a read's
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The requests with which a gateway only reads though they are POSTs, as its test declares them (readRequests()), each a method, a path and the one top-level key of its JSON body.
 *
 * Owns one fact: which requests of a gateway read though their method is not GET, HEAD or OPTIONS.
 * A provider with a single endpoint names the request in its JSON body, such as
 * `{"getTransactionDetailsRequest": {…}}` sent to `POST /xml/v1/request.api`, so neither the
 * method nor the path tells a read from a payment. A request is a declared read when its method
 * and its path, with its query, are a declared one's, its host is one the gateway's descriptor
 * declares, and its body is a JSON object whose one top-level key is the declared one. The suite's
 * request rules read it as moving no money (RequestRules::movesMoney()): rule 6 asks it for no
 * idempotency key, no rule takes it for an operation's own request, and the lost answer of rule 2
 * is never its own.
 *
 * So that a declaration cannot hide a request that moves money, it names MOST requests at most,
 * each a method in capitals that is not already a read's, a path from `/` and a key (refusal());
 * rules 6 and 12 refuse it when a declared read carries the operation's key or names the
 * operation's amount beside its currency (RequestRules::keyFailure()); and an operation every one
 * of whose requests is a declared read sends none that moves money, which both rules fail. A read
 * names the payment it reads, by the provider's id of it or the store's, so those are not held
 * against it as they are against a credential request.
 *
 * @since 0.2.0
 */
final readonly class ReadRequests {

	/**
	 * The most read requests a test may declare: a lookup, a search, and a few more of a provider's reads.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST = 4;

	/**
	 * A declared method: letters in capitals, as HTTP writes them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const METHOD = '/^[A-Z]+$/D';

	/**
	 * A declared path: from `/`, with its query if it has one, and no space or fragment.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATH = '#^/[^\s\#]*$#D';

	/**
	 * A declared body key: a name of letters, digits, `_`, `.` and `-`, beginning with a letter, of 64 characters at most.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BODY_KEY = '/^[A-Za-z][A-Za-z0-9_.\-]{0,63}$/D';

	/**
	 * Keeps the declared requests and the gateway's hosts.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, string>> $declared The requests, each a method, a path and a body key, as refusal() accepts them. Default none.
	 * @param string[]                          $hosts    The hosts the gateway's descriptor declares. Default none.
	 *
	 * @phpstan-param list<array{method: string, path: string, body_key: string}> $declared
	 * @phpstan-param list<string> $hosts
	 */
	public function __construct( private array $declared = array(), private array $hosts = array() ) {
	}

	/**
	 * Returns why a test's declaration of read requests is refused, in one sentence; null when it is not.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $declared What the test's readRequests() returned.
	 * @return string|null The sentence; null for at most MOST requests, each a method in capitals other than a read's, a path from `/` and a body key.
	 */
	public static function refusal( array $declared ): ?string {
		if ( count( $declared ) > self::MOST ) {
			return sprintf( 'The test declares %1$d read requests in readRequests(), and a gateway reads in %2$d at most: a longer list could hide a request that moves money.', count( $declared ), self::MOST );
		}

		foreach ( array_values( $declared ) as $at => $request ) {
			if ( ! self::wellFormed( $request ) ) {
				return sprintf( 'Read request %d of readRequests() is not a method in capitals other than GET, HEAD or OPTIONS, a path from `/` and the one top-level key of its JSON body, such as POST /xml/v1/request.api with getTransactionDetailsRequest.', $at + 1 );
			}
		}

		return null;
	}

	/**
	 * Tells whether a request is a declared read: its method and its path, with its query, a declared one's, at a host the gateway declares, with a JSON object of the declared one key as its body.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $method The request's method.
	 * @param string      $url    The request's whole URL.
	 * @param string|null $body   The request's body.
	 * @return bool True when it is one.
	 */
	public function covers( string $method, string $url, ?string $body ): bool {
		return null !== $this->covering( $method, $url, $body );
	}

	/**
	 * Returns the declared read that covers a request, as a message names it.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $method The request's method.
	 * @param string      $url    The request's whole URL.
	 * @param string|null $body   The request's body.
	 * @return string|null The read, such as `POST /xml/v1/request.api with the one key getTransactionDetailsRequest`; null when none covers it.
	 */
	public function covering( string $method, string $url, ?string $body ): ?string {
		$key = self::bodyKeyOf( $body );

		if ( null === $key || ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), array_map( 'strtolower', $this->hosts ), true ) ) {
			return null;
		}

		$path = Exchange::pathOf( $url );

		foreach ( $this->declared as $request ) {
			if ( strtoupper( $method ) === $request['method'] && $path === $request['path'] && $key === $request['body_key'] ) {
				return sprintf( '%1$s %2$s with the one key %3$s', $request['method'], $request['path'], $request['body_key'] );
			}
		}

		return null;
	}

	/**
	 * Tells whether an entry of a declaration is a method in capitals other than a read's, a path from `/` and a body key.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed $request The entry.
	 * @return bool True when it is.
	 */
	private static function wellFormed( mixed $request ): bool {
		if ( ! is_array( $request ) || ! is_string( $request['method'] ?? null ) || ! is_string( $request['path'] ?? null ) || ! is_string( $request['body_key'] ?? null ) ) {
			return false;
		}

		return 1 === preg_match( self::METHOD, $request['method'] )
			&& ! in_array( $request['method'], RequestRules::READS, true )
			&& 1 === preg_match( self::PATH, $request['path'] )
			&& 1 === preg_match( self::BODY_KEY, $request['body_key'] );
	}

	/**
	 * Returns the one top-level key of a body that is a JSON object of one member.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $body The body.
	 * @return string|null The key; null for a body that is no JSON object, or one of no member or of more.
	 */
	private static function bodyKeyOf( ?string $body ): ?string {
		$decoded = null === $body ? null : json_decode( $body );

		if ( ! $decoded instanceof \stdClass ) {
			return null;
		}

		$keys = array_keys( get_object_vars( $decoded ) );

		return 1 === count( $keys ) ? (string) $keys[0] : null;
	}
}
