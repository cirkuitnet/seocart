<?php
/**
 * RequestRules: rule 6's and rule 12's reading of the requests a gateway sent for one operation
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Tells which requests of an operation move money, and whether each carries the operation's idempotency key where a provider reads one.
 *
 * A request that moves money is any request but a read (GET, HEAD or OPTIONS). Its key travels in
 * a header or in the body, as it is, URL-encoded or escaped for JSON; a key in the URL alone does
 * not count, since a URL is logged and cached along the way and no provider dedupes by it.
 *
 * @since 0.2.0
 */
final class RequestRules {

	/**
	 * The methods of a request that only reads.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const READS = array( 'GET', 'HEAD', 'OPTIONS' );

	/**
	 * Returns the requests among a set that move money, keyed by their number in the set (1 for the first).
	 *
	 * @since 0.2.0
	 *
	 * @param Exchange[] $exchanges The requests of one operation, in order.
	 * @return array<int, Exchange> The requests that are not reads.
	 *
	 * @phpstan-param list<Exchange> $exchanges
	 */
	public static function moneyRequests( array $exchanges ): array {
		$money = array();

		foreach ( $exchanges as $index => $exchange ) {
			if ( ! in_array( strtoupper( $exchange->method ), self::READS, true ) ) {
				$money[ $index + 1 ] = $exchange;
			}
		}

		return $money;
	}

	/**
	 * Tells whether a request carries a key in a header or its body.
	 *
	 * @since 0.2.0
	 *
	 * @param Exchange $exchange The request.
	 * @param string   $key      The key.
	 * @return bool True when a header's value or the body holds it.
	 */
	public static function carriesKey( Exchange $exchange, string $key ): bool {
		foreach ( $exchange->headers as $value ) {
			if ( str_contains( (string) $value, $key ) ) {
				return true;
			}
		}

		$body = (string) $exchange->body;

		foreach ( self::formsOf( $key ) as $form ) {
			if ( str_contains( $body, $form ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a request's URL holds a key.
	 *
	 * @since 0.2.0
	 *
	 * @param Exchange $exchange The request.
	 * @param string   $key      The key.
	 * @return bool True when the URL holds it, as it is or URL-encoded.
	 */
	public static function urlHoldsKey( Exchange $exchange, string $key ): bool {
		foreach ( self::formsOf( $key ) as $form ) {
			if ( str_contains( $exchange->url, $form ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns rule 6's failure for one operation: a request that moves money without its key where a provider reads one, or none sent.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $what      The operation, for the message, such as "the capture".
	 * @param Exchange[] $exchanges The requests the operation sent, in order.
	 * @param string     $key       The key the operation's requests carry.
	 * @return string|null The failure, beginning with the rule's message; null when every request that moves money carries the key.
	 *
	 * @phpstan-param list<Exchange> $exchanges
	 */
	public static function keyFailure( string $what, array $exchanges, string $key ): ?string {
		$money = self::moneyRequests( $exchanges );

		if ( array() === $money ) {
			return Rule::KeysAsRuled->message() . sprintf( '%1$s sent %2$d requests, and none that moves money, so none carries %3$s.', $what, count( $exchanges ), $key );
		}

		foreach ( $money as $number => $exchange ) {
			if ( self::carriesKey( $exchange, $key ) ) {
				continue;
			}

			return Rule::KeysAsRuled->message() . sprintf(
				'request %1$d of %2$s (%3$s %4$s) carries %5$s %6$s: the key travels in a header or the body, where the provider dedupes by it.',
				$number,
				$what,
				$exchange->method,
				$exchange->path(),
				$key,
				self::urlHoldsKey( $exchange, $key ) ? 'only in its URL' : 'nowhere'
			);
		}

		return null;
	}

	/**
	 * Returns rule 12's failure for one operation: none of the requests the client carried while it ran is its own.
	 *
	 * An operation's own request moves money and names the operation's key anywhere, its URL
	 * included: where the key travels is rule 6's question, whether the request went through the
	 * client at all is this one's. A count of requests would not tell: one unrelated request through
	 * the client would hide an operation sent another way.
	 *
	 * @since 0.2.0
	 *
	 * @param string     $what      The operation, for the message, such as "the capture".
	 * @param Exchange[] $exchanges The requests the client carried while the operation ran, in order.
	 * @param string     $key       The operation's key.
	 * @return string|null The failure, beginning with the rule's message; null when one of them is the operation's own.
	 *
	 * @phpstan-param list<Exchange> $exchanges
	 */
	public static function sentFailure( string $what, array $exchanges, string $key ): ?string {
		foreach ( self::moneyRequests( $exchanges ) as $exchange ) {
			if ( self::carriesKey( $exchange, $key ) || self::urlHoldsKey( $exchange, $key ) ) {
				return null;
			}
		}

		return Rule::EveryCallThroughTheClient->message() . sprintf(
			'%1$s sent no request of its own through the HTTP client the plugin gives the gateway: of the %2$d requests the client carried while it ran, none moves money and names its key %3$s, so no tape records or replays the operation.',
			$what,
			count( $exchanges ),
			$key
		);
	}

	/**
	 * Returns the forms a key can be sent in: as it is, URL-encoded both ways, and escaped for JSON.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The key.
	 * @return list<string> The forms, each once.
	 */
	private static function formsOf( string $key ): array {
		return array_values( array_unique( array( $key, rawurlencode( $key ), urlencode( $key ), substr( (string) json_encode( $key ), 1, -1 ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The form a JSON body escapes a key in; wp_json_encode() is not loaded in the unit suite.
	}
}
