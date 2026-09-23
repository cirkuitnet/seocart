<?php
/**
 * SiteAddress: what identifies a site's address, and how the boot record keeps it out of reach of a text replace
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

defined( 'ABSPATH' ) || exit;

/**
 * The site address as Safe Mode compares it and as the boot record stores it.
 *
 * Owns one fact: how a copy of a site is told from the site even after the copy's database was
 * rewritten. Copying a site means copying its database and then running `wp search-replace`
 * from the old address to the new one, or from one part of it to another (`www.` to `stage.`),
 * which rewrites every occurrence in every table. A record that kept the address, or any part of
 * it, as text would then name the copy's own address, and the copy would take itself for the
 * store. So the record keeps two forms, and no fragment of either is text a replace would match:
 *
 * - hash(): a SHA-256 hash of the address's key. Safe Mode compares it with the hash of the
 *   current address.
 * - encode(): the address in base64url, only for telling the merchant which address was
 *   recorded; decode() reads it back. Its alphabet has no dot, colon or slash, so no host, no
 *   address and no part of one can match inside it.
 *
 * The key is the host in lower case, then the port when it is not a default one, then the path
 * without a trailing slash. The scheme is not part of it, so a site moved from http to https on
 * the same host has not moved; for the same reason both default ports, 80 and 443, count as no
 * port. Any other port is a different site.
 *
 * @since 0.1.0
 */
final class SiteAddress {

	/**
	 * The ports that count as no port: the default ports of both schemes, since the scheme is ignored.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private const DEFAULT_PORTS = array( 80, 443 );

	/**
	 * The stored display form: base64url without padding.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ENCODED_PATTERN = '/^[A-Za-z0-9_-]+\z/';

	/**
	 * Reduces an address to what identifies a site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The address, as home_url() returns it.
	 * @return string The host in lower case, `:port` unless the port is 80 or 443, and the path
	 *                without a trailing slash; no scheme.
	 */
	public static function key( string $url ): string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$port = wp_parse_url( $url, PHP_URL_PORT );
		$path = wp_parse_url( $url, PHP_URL_PATH );

		return strtolower( is_string( $host ) ? $host : '' )
			. ( is_int( $port ) && ! in_array( $port, self::DEFAULT_PORTS, true ) ? ':' . $port : '' )
			. untrailingslashit( is_string( $path ) ? $path : '' );
	}

	/**
	 * Returns the form the decision compares: a hash of the address's key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The address.
	 * @return string 64 lower-case hexadecimal digits. Two addresses with the same key have the same hash.
	 */
	public static function hash( string $url ): string {
		return hash( 'sha256', self::key( $url ) );
	}

	/**
	 * Returns the form kept for display: the address in base64url, without padding.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url The address.
	 * @return string The encoded address, four characters for every three bytes.
	 */
	public static function encode( string $url ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores the site address where a search-replace cannot rewrite it; it is only ever decoded for display, and nothing decoded is ever run.
		return rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
	}

	/**
	 * Reads back an address encode() wrote.
	 *
	 * @since 0.1.0
	 *
	 * @param string $encoded The encoded address.
	 * @return string|null The address, or null when the text is not base64url of a non-empty value.
	 */
	public static function decode( string $encoded ): ?string {
		if ( 1 !== preg_match( self::ENCODED_PATTERN, $encoded ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads the site address encode() stored, for display; nothing decoded is ever run.
		$url = base64_decode( strtr( $encoded, '-_', '+/' ), true );

		return false === $url || '' === $url ? null : $url;
	}
}
