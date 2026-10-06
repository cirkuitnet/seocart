<?php
/**
 * RequestKey: the idempotency key a refund was asked with, as its claim keeps it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * The key a caller sent with a refund request: the key's hash, scoped to the user who asks, and the fingerprint of the whole request.
 *
 * Owns one fact: what names a refund on the caller's side. A retry of a request whose answer was
 * lost sends the same key with the same request, and finds the refund it asked for by the key's
 * hash; the same key sent with another request is refused by the fingerprint. Neither the key nor
 * the user is kept in clear: the refund operation hashes both.
 *
 * @since 0.2.0
 */
final readonly class RequestKey {

	/**
	 * A SHA-256 in lower-case hexadecimal.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SHA256 = '/^[0-9a-f]{64}\z/';

	/**
	 * Records the key.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When either is not a SHA-256 in lower-case hexadecimal.
	 *
	 * @param string $keyHash     The hash of the key, scoped to the user who asks.
	 * @param string $fingerprint The hash of the request the key was sent with, in its canonical form.
	 */
	public function __construct(
		public string $keyHash,
		public string $fingerprint
	) {
		if ( 1 !== preg_match( self::SHA256, $keyHash ) || 1 !== preg_match( self::SHA256, $fingerprint ) ) {
			throw new \InvalidArgumentException( 'A refund request\'s key is kept as two SHA-256 hashes, in lower-case hexadecimal.' );
		}
	}
}
