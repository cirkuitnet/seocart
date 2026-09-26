<?php
/**
 * WordPressAccessKeys: order access keys, hashed with WordPress's fast hash
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Infrastructure;

use SEOCart\Order\Domain\AccessKeys;

defined( 'ABSPATH' ) || exit;

/**
 * Makes access keys from 128 random bits, and stores and checks them with `wp_fast_hash()`.
 *
 * Owns one fact: the primitives an access key is made and checked with. The key is 16 bytes from
 * `random_bytes()`, written as 32 hexadecimal digits so it is safe in a URL. `wp_fast_hash()` is
 * the primitive WordPress itself uses for random keys with that much entropy, such as application
 * passwords, and `wp_verify_fast_hash()` compares in constant time.
 *
 * @since 0.1.0
 */
final class WordPressAccessKeys implements AccessKeys {

	/**
	 * The random bytes a key is made of: 128 bits.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const BYTES = 16;

	/**
	 * Makes a new raw key.
	 *
	 * @since 0.1.0
	 *
	 * @return string 32 lowercase hexadecimal digits.
	 */
	public function generate(): string {
		return bin2hex( random_bytes( self::BYTES ) );
	}

	/**
	 * Hashes a raw key for storage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw The raw key.
	 * @return string The hash.
	 */
	public function hash( string $raw ): string {
		return wp_fast_hash( $raw );
	}

	/**
	 * Tells whether a presented key is the one a hash was made from, in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw  The presented key.
	 * @param string $hash The stored hash.
	 * @return bool True when they match.
	 */
	public function verify( string $raw, string $hash ): bool {
		return wp_verify_fast_hash( $raw, $hash );
	}
}
