<?php
/**
 * AccessKeys: the keys a guest reads an order with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The port that makes, hashes and checks order access keys.
 *
 * Owns one fact: how an access key is made and stored. A key is random, with at least 128 bits
 * from a cryptographically secure source; only its hash is stored, and a presented key is
 * compared with it in constant time. The raw key reaches the shopper once, when the order is
 * placed.
 *
 * @since 0.1.0
 */
interface AccessKeys {

	/**
	 * How long a key works after the order is placed: 72 hours, measured by the database clock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LIFETIME_SECONDS = 259200;

	/**
	 * Makes a new raw key.
	 *
	 * @since 0.1.0
	 *
	 * @return string The key, safe in a URL.
	 */
	public function generate(): string;

	/**
	 * Hashes a raw key for storage.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw The raw key.
	 * @return string The hash, which is what the order stores.
	 */
	public function hash( string $raw ): string;

	/**
	 * Tells whether a presented key is the one a hash was made from, in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw  The presented key.
	 * @param string $hash The stored hash.
	 * @return bool True when they match.
	 */
	public function verify( string $raw, string $hash ): bool;
}
