<?php
/**
 * Cipher: XChaCha20-Poly1305 and the encoding of its keys and nonces
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The one authenticated cipher the plugin seals secrets with.
 *
 * This class owns one fact: which cipher seals a secret and where it comes from. It is
 * XChaCha20-Poly1305 (IETF), through PHP's sodium functions: the sodium extension when it is
 * loaded, and otherwise the sodium_compat library WordPress ships and loads when the extension is
 * missing. WordPress defines the same functions only when the extension has not, so calling them
 * uses the extension whenever there is one. One of the two is required: detect() refuses with
 * SecretsError::NoCipher when neither is there.
 *
 * Every key is 32 random bytes and every nonce 24, both from PHP's CSPRNG; a 24-byte nonce drawn at
 * random is safe to never reuse. Binary parts are written in base64url without padding, through
 * sodium's constant-time encoder, so encoding a key does not leak it through timing. Decryption
 * answers null for anything that does not authenticate; it never returns a partial or an empty
 * plain text in place of a failure.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class Cipher {

	/**
	 * The algorithm, as the key registry records it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ALGORITHM = 'xchacha20poly1305-ietf';

	/**
	 * The length of a key, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const KEY_BYTES = 32;

	/**
	 * The length of a nonce, in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const NONCE_BYTES = 24;

	/**
	 * The library name when PHP's sodium extension provides the cipher.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXTENSION = 'sodium';

	/**
	 * The library name when WordPress's sodium_compat provides the cipher.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const POLYFILL = 'sodium_compat';

	/**
	 * The shape of a base64url part without padding.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const BASE64URL_PATTERN = '/^[A-Za-z0-9_-]+\z/';

	/**
	 * The library that provides the cipher.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $library;

	/**
	 * Creates the cipher. Use detect().
	 *
	 * @since 0.1.0
	 *
	 * @param string $library The library that provides it.
	 */
	private function __construct( string $library ) {
		$this->library = $library;
	}

	/**
	 * Returns the cipher this PHP provides.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::NoCipher when neither library provides it.
	 *
	 * @return self The cipher.
	 */
	public static function detect(): self {
		$functions = array( 'sodium_crypto_aead_xchacha20poly1305_ietf_encrypt', 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt', 'sodium_bin2base64', 'sodium_base642bin' );

		return self::choose( extension_loaded( 'sodium' ), array() === array_filter( $functions, static fn( string $name ): bool => ! function_exists( $name ) ) && defined( 'SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING' ) );
	}

	/**
	 * Decides which library provides the cipher, from what this PHP has.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::NoCipher when the functions are missing.
	 *
	 * @param bool $extension Whether the sodium extension is loaded.
	 * @param bool $functions Whether the sodium functions and constants the cipher calls exist,
	 *                        from the extension or from a polyfill.
	 * @return self The cipher.
	 */
	public static function choose( bool $extension, bool $functions ): self {
		if ( ! $functions ) {
			CodedException::raise( SecretsError::NoCipher );
		}

		return new self( $extension ? self::EXTENSION : self::POLYFILL );
	}

	/**
	 * Returns the library that provides the cipher.
	 *
	 * @since 0.1.0
	 *
	 * @return string self::EXTENSION or self::POLYFILL.
	 */
	public function library(): string {
		return $this->library;
	}

	/**
	 * Returns a new random key.
	 *
	 * @since 0.1.0
	 *
	 * @return string KEY_BYTES random bytes.
	 */
	public function randomKey(): string {
		return random_bytes( self::KEY_BYTES );
	}

	/**
	 * Returns a new random nonce.
	 *
	 * @since 0.1.0
	 *
	 * @return string NONCE_BYTES random bytes.
	 */
	public function randomNonce(): string {
		return random_bytes( self::NONCE_BYTES );
	}

	/**
	 * Encrypts and authenticates a plain text, together with associated data.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the nonce or the key has the wrong length.
	 *
	 * @param string $plaintext The plain text.
	 * @param string $ad        The associated data: authenticated, not encrypted, not stored.
	 * @param string $nonce     A fresh nonce.
	 * @param string $key       The key.
	 * @return string The cipher text, with its authentication tag.
	 */
	public function encrypt( #[\SensitiveParameter] string $plaintext, string $ad, string $nonce, #[\SensitiveParameter] string $key ): string {
		self::assertLengths( $nonce, $key );

		return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plaintext, $ad, $nonce, $key );
	}

	/**
	 * Decrypts a cipher text, if it authenticates with the associated data and the key.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the nonce or the key has the wrong length.
	 *
	 * @param string $ciphertext The cipher text, with its tag.
	 * @param string $ad         The associated data it was sealed with.
	 * @param string $nonce      Its nonce.
	 * @param string $key        The key.
	 * @return string|null The plain text, or null when it does not authenticate.
	 */
	public function decrypt( string $ciphertext, string $ad, string $nonce, #[\SensitiveParameter] string $key ): ?string {
		self::assertLengths( $nonce, $key );

		try {
			$plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( $ciphertext, $ad, $nonce, $key );
		} catch ( \SodiumException ) {
			return null;
		}

		return is_string( $plaintext ) ? $plaintext : null;
	}

	/**
	 * Writes bytes in base64url without padding, in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $bytes The bytes.
	 * @return string The text.
	 */
	public function encode( #[\SensitiveParameter] string $bytes ): string {
		return sodium_bin2base64( $bytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
	}

	/**
	 * Reads base64url without padding, in constant time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string|null The bytes, or null when the text is not base64url without padding.
	 */
	public function decode( string $text ): ?string {
		if ( 1 !== preg_match( self::BASE64URL_PATTERN, $text ) ) {
			return null;
		}

		try {
			return sodium_base642bin( $text, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
		} catch ( \SodiumException ) {
			return null;
		}
	}

	/**
	 * Refuses a nonce or a key of the wrong length, which the library would reject less clearly.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a length is wrong.
	 *
	 * @param string $nonce The nonce.
	 * @param string $key   The key.
	 */
	private static function assertLengths( string $nonce, #[\SensitiveParameter] string $key ): void {
		if ( self::NONCE_BYTES !== strlen( $nonce ) || self::KEY_BYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'A nonce is 24 bytes and a key 32 bytes.' );
		}
	}
}
