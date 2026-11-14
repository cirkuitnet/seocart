<?php
/**
 * CredentialRequests: the requests a gateway's test declares exchange the gateway's credentials for a token
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The requests with which a gateway exchanges its credentials for a token, as its test declares them (credentialRequests()), each a method and a path.
 *
 * Owns one fact: which requests of a gateway carry no money though they are no read. A provider
 * that exchanges credentials for a token, such as with `POST /v1/oauth2/token`, has the gateway
 * send that request before its money request. The suite's request rules read such a request as
 * carrying no money (RequestRules::movesMoney()): it needs no idempotency key, no rule takes it for
 * an operation's own request, and a lost answer is never its own. A request is one of them when
 * its method and its path, with its query, are a declared one's, and its host is one the gateway's
 * descriptor declares.
 *
 * So that a declaration cannot hide a request that moves money, it names MOST requests at most,
 * each a method in capitals and a path from `/` (refusal()), and rules 6 and 12 refuse it when an
 * operation sends money on a declared path (RequestRules::hiddenMoney()).
 *
 * @since 0.2.0
 */
final readonly class CredentialRequests {

	/**
	 * The most credential requests a test may declare: a token, and a few more steps of a provider's exchange.
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
	 * Keeps the declared requests and the gateway's hosts.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<string, string>> $declared The requests, each a method and a path, as refusal() accepts them. Default none.
	 * @param string[]                          $hosts    The hosts the gateway's descriptor declares. Default none.
	 *
	 * @phpstan-param list<array{method: string, path: string}> $declared
	 * @phpstan-param list<string> $hosts
	 */
	public function __construct( private array $declared = array(), private array $hosts = array() ) {
	}

	/**
	 * Returns why a test's declaration of credential requests is refused, in one sentence; null when it is not.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $declared What the test's credentialRequests() returned.
	 * @return string|null The sentence; null for at most MOST requests, each a method in capitals and a path from `/`.
	 */
	public static function refusal( array $declared ): ?string {
		if ( count( $declared ) > self::MOST ) {
			return sprintf( 'The test declares %1$d credential requests in credentialRequests(), and a gateway exchanges its credentials in %2$d at most: a longer list could hide a request that moves money.', count( $declared ), self::MOST );
		}

		foreach ( array_values( $declared ) as $at => $request ) {
			if ( ! is_array( $request ) || ! is_string( $request['method'] ?? null ) || ! is_string( $request['path'] ?? null ) || 1 !== preg_match( self::METHOD, $request['method'] ) || 1 !== preg_match( self::PATH, $request['path'] ) ) {
				return sprintf( 'Credential request %d of credentialRequests() is not a method in capitals and a path from `/`, such as POST /v1/oauth2/token.', $at + 1 );
			}
		}

		return null;
	}

	/**
	 * Tells whether a request is a declared credential request: its method and its path, with its query, a declared one's, at a host the gateway declares.
	 *
	 * @since 0.2.0
	 *
	 * @param string $method The request's method.
	 * @param string $url    The request's whole URL.
	 * @return bool True when it is one.
	 */
	public function covers( string $method, string $url ): bool {
		$path = Exchange::pathOf( $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

		foreach ( $this->declared as $request ) {
			if ( strtoupper( $method ) === $request['method'] && $path === $request['path'] && in_array( $host, array_map( 'strtolower', $this->hosts ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the declared credential request on a path, whatever its method.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path The path, with its query.
	 * @return string|null The request, such as `POST /v1/oauth2/token`; null when none is declared on the path.
	 */
	public function onPath( string $path ): ?string {
		foreach ( $this->declared as $request ) {
			if ( $path === $request['path'] ) {
				return $request['method'] . ' ' . $request['path'];
			}
		}

		return null;
	}
}
