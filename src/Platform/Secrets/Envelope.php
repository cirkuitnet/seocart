<?php
/**
 * Envelope: the sealed form of a secret
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
 * Seals a secret with a data key, and opens it again.
 *
 * This class owns one fact: the sealed form of a secret, version v1:
 * `v1:{key id}:{nonce}:{cipher text}`, the nonce and the cipher text in base64url without padding.
 * The key id says which data key opens it, so keys can be rotated one record at a time.
 *
 * The associated data binds the header and the record the secret belongs to — the option and the
 * setting it is stored as — so a sealed value altered in any byte, given another key id, or copied
 * into another record does not open. Opening fails with SecretsError::Unreadable, never with an
 * empty string.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class Envelope {

	/**
	 * The shape of a sealed value: key id, a 24-byte nonce in base64url, and the cipher text.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^v1:([0-9a-f]{16}):([A-Za-z0-9_-]{32}):([A-Za-z0-9_-]+)\z/';

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Seals a secret.
	 *
	 * @since 0.1.0
	 *
	 * @param DataKey $key       The data key to seal it with.
	 * @param string  $plaintext The secret.
	 * @param string  $record    The record it belongs to.
	 * @param Cipher  $cipher    The cipher.
	 * @return string The sealed value.
	 */
	public static function seal( DataKey $key, #[\SensitiveParameter] string $plaintext, string $record, Cipher $cipher ): string {
		$nonce  = $cipher->randomNonce();
		$header = 'v1:' . $key->id() . ':' . $cipher->encode( $nonce );

		return $header . ':' . $cipher->encode( $cipher->encrypt( $plaintext, self::associatedData( $header, $record ), $nonce, $key->bytes() ) );
	}

	/**
	 * Returns the id of the data key a sealed value names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sealed The sealed value.
	 * @return string|null The key id, or null when the text is not a sealed value.
	 */
	public static function keyId( string $sealed ): ?string {
		return 1 === preg_match( self::PATTERN, $sealed, $parts ) ? $parts[1] : null;
	}

	/**
	 * Returns the id of the data key a stored text's header names, whatever follows the header.
	 *
	 * For counting what a key still seals: a sealed value damaged after its header still names
	 * the key it was sealed with, and still keeps that key from being retired.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The stored text.
	 * @return string|null The key id, or null when the text does not start with a v1 header.
	 */
	public static function headerKeyId( string $text ): ?string {
		return 1 === preg_match( '/^v1:([0-9a-f]{16}):/', $text, $parts ) ? $parts[1] : null;
	}

	/**
	 * Opens a sealed value.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::Unreadable when the value is not a sealed value, is
	 *                        not sealed with this key, was altered, or belongs to another record.
	 *
	 * @param string  $sealed The sealed value.
	 * @param DataKey $key    The data key its header names.
	 * @param string  $record The record it belongs to.
	 * @param Cipher  $cipher The cipher.
	 * @return string The secret.
	 */
	public static function open( string $sealed, DataKey $key, string $record, Cipher $cipher ): string {
		if ( 1 !== preg_match( self::PATTERN, $sealed, $parts ) || $parts[1] !== $key->id() ) {
			CodedException::raise( SecretsError::Unreadable, array( 'record' => $record ) );
		}

		$nonce      = $cipher->decode( $parts[2] );
		$ciphertext = $cipher->decode( $parts[3] );
		$plaintext  = null === $nonce || null === $ciphertext || Cipher::NONCE_BYTES !== strlen( $nonce )
			? null
			: $cipher->decrypt( $ciphertext, self::associatedData( 'v1:' . $parts[1] . ':' . $parts[2], $record ), $nonce, $key->bytes() );

		if ( null === $plaintext ) {
			CodedException::raise( SecretsError::Unreadable, array( 'record' => $record ) );
		}

		return $plaintext;
	}

	/**
	 * Returns the associated data a secret is sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $header The sealed value up to and including its nonce.
	 * @param string $record The record it belongs to.
	 * @return string The associated data.
	 */
	private static function associatedData( string $header, string $record ): string {
		return "seocart secret\n" . $header . "\n" . $record;
	}
}
