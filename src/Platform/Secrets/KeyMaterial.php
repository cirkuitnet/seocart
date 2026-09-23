<?php
/**
 * KeyMaterial: the stored form of a data key, wrapped by the encryption key or not
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
 * Writes a data key in the form the database keeps it in, and reads it back.
 *
 * This class owns one fact: the stored form of a data key, version k1.
 *
 * - With an encryption key, the data key is wrapped: `k1:{key id}:kek:{nonce}:{sealed key}`. It is
 *   sealed with the encryption key, and the associated data binds the whole header, so the
 *   material of one key id cannot pose as another's.
 * - Without one, it is stored as it is: `k1:{key id}:raw:{key}`. Anyone who can read the database
 *   can then read the key; Site Health says so.
 *
 * Opening a wrapped key without the encryption key that wrapped it, or damaged material, fails
 * with SecretsError::KeyUnavailable; wrapping when the encryption key is invalid fails with
 * SecretsError::EncryptionKeyInvalid, rather than falling back to storing the key as it is.
 *
 * Nothing here calls WordPress.
 *
 * @since 0.1.0
 */
final class KeyMaterial {

	/**
	 * The shape of the material: its id, then either the key or the wrapped key and its nonce.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERN = '/^k1:([0-9a-f]{16}):(?:raw:([A-Za-z0-9_-]+)|kek:([A-Za-z0-9_-]+):([A-Za-z0-9_-]+))\z/';

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Writes a data key in its stored form: wrapped when there is an encryption key.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::EncryptionKeyInvalid when the encryption key is defined but unusable.
	 *
	 * @param DataKey       $key    The data key.
	 * @param EncryptionKey $kek    The encryption key.
	 * @param Cipher        $cipher The cipher.
	 * @return string The material.
	 */
	public static function write( DataKey $key, EncryptionKey $kek, Cipher $cipher ): string {
		$wrapping = $kek->bytes();

		if ( EncryptionKeyState::Invalid === $kek->state() || ( EncryptionKeyState::Present === $kek->state() && null === $wrapping ) ) {
			CodedException::raise( SecretsError::EncryptionKeyInvalid );
		}

		if ( null === $wrapping ) {
			return 'k1:' . $key->id() . ':raw:' . $cipher->encode( $key->bytes() );
		}

		$nonce  = $cipher->randomNonce();
		$header = 'k1:' . $key->id() . ':kek:' . $cipher->encode( $nonce );

		return $header . ':' . $cipher->encode( $cipher->encrypt( $key->bytes(), self::associatedData( $header ), $nonce, $wrapping ) );
	}

	/**
	 * Reads a data key from its stored form, unwrapping it with the encryption key when it is wrapped.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::KeyUnavailable when the material is damaged, or is
	 *                        wrapped and the encryption key is absent or does not open it.
	 *
	 * @param string        $material The material.
	 * @param EncryptionKey $kek      The encryption key.
	 * @param Cipher        $cipher   The cipher.
	 * @return DataKey The data key.
	 */
	public static function read( string $material, EncryptionKey $kek, Cipher $cipher ): DataKey {
		if ( 1 !== preg_match( self::PATTERN, $material, $parts ) ) {
			CodedException::raise( SecretsError::KeyUnavailable, array( 'key_id' => self::keyId( $material ) ?? 'unknown' ) );
		}

		$id = $parts[1];

		if ( '' !== ( $parts[2] ?? '' ) ) {
			$bytes = $cipher->decode( $parts[2] );
		} else {
			$nonce    = $cipher->decode( $parts[3] );
			$wrapped  = $cipher->decode( $parts[4] );
			$wrapping = $kek->bytes();
			$bytes    = null === $nonce || null === $wrapped || null === $wrapping || Cipher::NONCE_BYTES !== strlen( $nonce )
				? null
				: $cipher->decrypt( $wrapped, self::associatedData( 'k1:' . $id . ':kek:' . $parts[3] ), $nonce, $wrapping );
		}

		if ( null === $bytes || Cipher::KEY_BYTES !== strlen( $bytes ) ) {
			CodedException::raise( SecretsError::KeyUnavailable, array( 'key_id' => $id ) );
		}

		return new DataKey( $id, $bytes );
	}

	/**
	 * Returns the id a material names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $material The material.
	 * @return string|null The key id, or null when the text is not key material.
	 */
	public static function keyId( string $material ): ?string {
		return 1 === preg_match( '/^k1:([0-9a-f]{16}):/', $material, $parts ) ? $parts[1] : null;
	}

	/**
	 * Tells whether a material is wrapped by an encryption key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $material The material.
	 * @return bool True when it is wrapped.
	 */
	public static function isWrapped( string $material ): bool {
		return 1 === preg_match( self::PATTERN, $material, $parts ) && '' === ( $parts[2] ?? '' );
	}

	/**
	 * Returns the associated data a wrapped key is sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @param string $header The material up to and including its nonce.
	 * @return string The associated data.
	 */
	private static function associatedData( string $header ): string {
		return "seocart data key\n" . $header;
	}
}
