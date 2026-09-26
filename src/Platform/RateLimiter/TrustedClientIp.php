<?php
/**
 * TrustedClientIp: the address of the client, trusting a forwarded header only from configured proxies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which address a request came from.
 *
 * Owns one fact: when a forwarded header may name the client. By default never: the address is
 * `REMOTE_ADDR`, because a header any client can send is worse than a coarse address. A site
 * behind a CDN or a load balancer, where `REMOTE_ADDR` is the proxy's, opts in with two constants
 * in wp-config.php:
 *
 *     define( 'SEOCART_TRUSTED_PROXIES', '173.245.48.0/20, 2400:cb00::/32' ); // the proxies' ranges
 *     define( 'SEOCART_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );           // default X-Forwarded-For
 *
 * Only when `REMOTE_ADDR` is inside one of the trusted ranges is the header read. Its
 * comma-separated addresses are walked from the right, the side the proxies appended to, past
 * every trusted proxy; the first address that is not one is the client's. Anything that is not an
 * address ends the walk, and the client is then `REMOTE_ADDR`. An entry of SEOCART_TRUSTED_PROXIES
 * that is not an address or a range is ignored, which can only trust less.
 *
 * An IPv6 address is cut to its /64 network: one host is routinely given a whole /64, and could
 * otherwise count as a new client with every address in it. An IPv4 address written in IPv6 form
 * (`::ffff:192.0.2.1`) is read as the IPv4 address.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class TrustedClientIp {

	/**
	 * The header read by default when REMOTE_ADDR is a trusted proxy.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_HEADER = 'X-Forwarded-For';

	/**
	 * The server variables of the request.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private array $server;

	/**
	 * The trusted proxies' ranges, each as its packed network address and prefix length.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: string, 1: int}>
	 */
	private array $trusted = array();

	/**
	 * The server variable holding the forwarded header, such as HTTP_X_FORWARDED_FOR.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $headerVariable;

	/**
	 * Creates the resolver.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $server          The server variables, as $_SERVER holds them.
	 * @param string               $trusted_proxies Optional. Comma- or space-separated addresses and
	 *                                              ranges (CIDR) of the trusted proxies. Default none.
	 * @param string               $header          Optional. The header naming the client. Default
	 *                                              DEFAULT_HEADER.
	 */
	public function __construct( array $server, string $trusted_proxies = '', string $header = self::DEFAULT_HEADER ) {
		$this->server         = $server;
		$this->headerVariable = 'HTTP_' . strtoupper( str_replace( '-', '_', trim( $header ) ) );

		$entries = preg_split( '/[\s,]+/', $trusted_proxies, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( false === $entries ? array() : $entries as $entry ) {
			$range = self::range( $entry );

			if ( null !== $range ) {
				$this->trusted[] = $range;
			}
		}
	}

	/**
	 * Creates the resolver for the request being served, from $_SERVER and the two constants.
	 *
	 * @since 0.1.0
	 *
	 * @return self The resolver.
	 */
	public static function fromRequest(): self {
		$proxies = defined( 'SEOCART_TRUSTED_PROXIES' ) ? constant( 'SEOCART_TRUSTED_PROXIES' ) : '';
		$header  = defined( 'SEOCART_TRUSTED_PROXY_HEADER' ) ? constant( 'SEOCART_TRUSTED_PROXY_HEADER' ) : self::DEFAULT_HEADER;

		return new self(
			$_SERVER, // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Every value read from it is validated as an IP address before it is used.
			is_array( $proxies ) ? implode( ',', array_map( 'strval', $proxies ) ) : (string) $proxies,
			is_string( $header ) && '' !== trim( $header ) ? $header : self::DEFAULT_HEADER
		);
	}

	/**
	 * Returns the client's address.
	 *
	 * @since 0.1.0
	 *
	 * @return string The address, an IPv6 one cut to its /64; an empty string when REMOTE_ADDR is
	 *                not an address, as on the command line.
	 */
	public function address(): string {
		$remote = self::packed( $this->server['REMOTE_ADDR'] ?? null );

		if ( null === $remote ) {
			return '';
		}

		if ( ! $this->isTrusted( $remote ) || ! is_string( $this->server[ $this->headerVariable ] ?? null ) ) {
			return self::normalized( $remote );
		}

		$forwarded = array_reverse( explode( ',', $this->server[ $this->headerVariable ] ) );

		foreach ( $forwarded as $entry ) {
			$address = self::packed( trim( $entry ) );

			if ( null === $address ) {
				break;
			}

			if ( ! $this->isTrusted( $address ) ) {
				return self::normalized( $address );
			}
		}

		return self::normalized( $remote );
	}

	/**
	 * Tells whether an address is inside one of the trusted ranges.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address A packed address.
	 * @return bool True when a trusted range of the same family holds it.
	 */
	private function isTrusted( string $address ): bool {
		foreach ( $this->trusted as list( $network, $prefix ) ) {
			if ( strlen( $network ) === strlen( $address ) && self::masked( $address, $prefix ) === $network ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parses one entry of the trusted proxies: an address, or an address and a prefix length.
	 *
	 * @since 0.1.0
	 *
	 * @param string $entry For example `173.245.48.0/20`, `2400:cb00::/32` or `10.0.0.5`.
	 * @return array{0: string, 1: int}|null The packed network address and the prefix length, or
	 *                                       null for an entry that is neither.
	 */
	private static function range( string $entry ): ?array {
		$parts   = explode( '/', $entry, 2 );
		$address = self::packed( $parts[0] );

		if ( null === $address ) {
			return null;
		}

		$bits   = strlen( $address ) * 8;
		$prefix = isset( $parts[1] ) ? ( ctype_digit( $parts[1] ) ? (int) $parts[1] : -1 ) : $bits;

		if ( $prefix < 0 || $prefix > $bits ) {
			return null;
		}

		return array( self::masked( $address, $prefix ), $prefix );
	}

	/**
	 * Packs an address, reading an IPv4 address written in IPv6 form as the IPv4 address.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $address The text of an address.
	 * @return string|null The 4 or 16 bytes, or null when the text is not an address.
	 */
	private static function packed( $address ): ?string {
		if ( ! is_string( $address ) || false === filter_var( $address, FILTER_VALIDATE_IP ) ) {
			return null;
		}

		$packed = (string) inet_pton( $address );

		if ( 16 === strlen( $packed ) && str_starts_with( $packed, str_repeat( "\0", 10 ) . "\xff\xff" ) ) {
			return substr( $packed, 12 );
		}

		return $packed;
	}

	/**
	 * Keeps the first bits of a packed address and clears the rest.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address A packed address.
	 * @param int    $prefix  How many leading bits to keep.
	 * @return string The packed network address.
	 */
	private static function masked( string $address, int $prefix ): string {
		$masked = '';

		foreach ( str_split( $address ) as $index => $byte ) {
			$keep    = max( 0, min( 8, $prefix - $index * 8 ) );
			$masked .= chr( ord( $byte ) & ( ( 0xff << ( 8 - $keep ) ) & 0xff ) );
		}

		return $masked;
	}

	/**
	 * Writes a packed address as text, an IPv6 one cut to its /64 network.
	 *
	 * @since 0.1.0
	 *
	 * @param string $address A packed address.
	 * @return string The address, such as `192.0.2.1` or `2001:db8:1:2::`.
	 */
	private static function normalized( string $address ): string {
		return (string) inet_ntop( 16 === strlen( $address ) ? self::masked( $address, 64 ) : $address );
	}
}
