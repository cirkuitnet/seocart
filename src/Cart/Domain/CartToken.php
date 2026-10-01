<?php
/**
 * CartToken: the secret a client holds for its cart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The token that gives a client its cart: 256 random bits, written as 64 hexadecimal characters.
 *
 * Owns one fact: what a cart token is. A token is generated from the system's cryptographically
 * secure source, and a text is accepted as one only in exactly that form; anything else is not a
 * token at all. Whoever holds a token holds the cart, so it is never logged or shown, and never
 * stored as it is: only a hash of it is kept.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class CartToken {

	/**
	 * How many random bytes a token carries.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BYTES = 32;

	/**
	 * The one form of a token: 64 lowercase hexadecimal characters.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^[0-9a-f]{64}\z/';

	/**
	 * The token.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Keeps a token. Use generate() or fromString().
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The token, already checked.
	 */
	private function __construct( #[\SensitiveParameter] string $value ) {
		$this->value = $value;
	}

	/**
	 * Generates a new token.
	 *
	 * @since 0.1.0
	 *
	 * @return self A token no one has held before.
	 */
	public static function generate(): self {
		return new self( bin2hex( random_bytes( self::BYTES ) ) );
	}

	/**
	 * Reads a token a client sent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The text the client sent.
	 * @return self|null The token, or null when the text is not one.
	 */
	public static function fromString( #[\SensitiveParameter] string $value ): ?self {
		return 1 === preg_match( self::PATTERN, $value ) ? new self( $value ) : null;
	}

	/**
	 * Returns the token, for the cookie and the header that carry it, and for its hash.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 hexadecimal characters.
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Returns the hash the cart is stored under: the token's SHA-256, never the token itself.
	 *
	 * @since 0.1.0
	 *
	 * @return string 64 hexadecimal characters.
	 */
	public function hash(): string {
		return hash( 'sha256', $this->value );
	}

	/**
	 * Tells whether two tokens are the same, in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param self $other The other token.
	 * @return bool True when they are equal.
	 */
	public function equals( self $other ): bool {
		return hash_equals( $this->value, $other->value );
	}

	/**
	 * Keeps the token out of debugging output.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The token, redacted.
	 */
	public function __debugInfo(): array {
		return array( 'value' => '[redacted]' );
	}
}
