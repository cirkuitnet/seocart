<?php
/**
 * KeyMaterialTest: a data key is stored wrapped when there is an encryption key, and opens only with it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\DataKey;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\KeyMaterial;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\SecretsHarness;
use WP_UnitTestCase;

/**
 * Writes data keys in their stored form and reads them back, with and without an encryption key.
 *
 * @since 0.1.0
 */
final class KeyMaterialTest extends WP_UnitTestCase {

	/**
	 * The cipher.
	 *
	 * @since 0.1.0
	 *
	 * @var Cipher
	 */
	private Cipher $cipher;

	/**
	 * The data key.
	 *
	 * @since 0.1.0
	 *
	 * @var DataKey
	 */
	private DataKey $key;

	/**
	 * Detects the cipher and draws a key.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->cipher = Cipher::detect();
		$this->key    = DataKey::generate( $this->cipher );
	}

	/**
	 * Tests that without an encryption key the data key is stored as it is, and says so.
	 *
	 * @since 0.1.0
	 */
	public function test_without_an_encryption_key_the_key_is_stored_as_it_is(): void {
		$material = KeyMaterial::write( $this->key, EncryptionKey::fromValue( null ), $this->cipher );

		$this->assertSame( 'k1:' . $this->key->id() . ':raw:' . $this->cipher->encode( $this->key->bytes() ), $material );
		$this->assertFalse( KeyMaterial::isWrapped( $material ) );
		$this->assertSame( $this->key->bytes(), KeyMaterial::read( $material, EncryptionKey::fromValue( null ), $this->cipher )->bytes() );
	}

	/**
	 * Tests that with an encryption key the data key is wrapped, and opens only with that key.
	 *
	 * Planted violation: in KeyMaterial::write(), return the raw form whatever the encryption key.
	 * The material then holds the key's bytes in the clear.
	 *
	 * @since 0.1.0
	 */
	public function test_with_an_encryption_key_the_key_is_wrapped(): void {
		$kek      = SecretsHarness::newEncryptionKey();
		$material = KeyMaterial::write( $this->key, $kek, $this->cipher );

		$this->assertTrue( KeyMaterial::isWrapped( $material ) );
		$this->assertStringStartsWith( 'k1:' . $this->key->id() . ':kek:', $material );
		$this->assertStringNotContainsString( $this->cipher->encode( $this->key->bytes() ), $material, 'The wrapped material holds the key in the clear.' );
		$this->assertSame( $this->key->id(), KeyMaterial::keyId( $material ) );
		$this->assertSame( $this->key->bytes(), KeyMaterial::read( $material, $kek, $this->cipher )->bytes() );

		$this->assertUnavailable( $material, SecretsHarness::newEncryptionKey() );
		$this->assertUnavailable( $material, EncryptionKey::fromValue( null ) );
		$this->assertUnavailable( $material, EncryptionKey::fromValue( 'not a key' ) );
	}

	/**
	 * Tests that wrapped material given another key id does not open.
	 *
	 * Planted violation: in KeyMaterial::associatedData(), return a constant string. The material
	 * then opens under the id it was moved to.
	 *
	 * @since 0.1.0
	 */
	public function test_wrapped_material_moved_to_another_id_does_not_open(): void {
		$kek      = SecretsHarness::newEncryptionKey();
		$material = KeyMaterial::write( $this->key, $kek, $this->cipher );
		$other    = 'ffffffffffffffff' === $this->key->id() ? '0000000000000000' : 'ffffffffffffffff';

		$this->assertUnavailable( 'k1:' . $other . substr( $material, strlen( 'k1:' ) + 16 ), $kek );
	}

	/**
	 * Tests that damaged material does not open, whatever the encryption key.
	 *
	 * @since 0.1.0
	 */
	public function test_damaged_material_does_not_open(): void {
		$raw = KeyMaterial::write( $this->key, EncryptionKey::fromValue( null ), $this->cipher );

		foreach ( array( '', 'k1:' . $this->key->id() . ':raw:AAAA', substr( $raw, 0, -2 ), 'k2' . substr( $raw, 2 ), $raw . ':x' ) as $material ) {
			$this->assertUnavailable( $material, EncryptionKey::fromValue( null ) );
		}
	}

	/**
	 * Tests that an encryption key that is defined but invalid is refused rather than ignored.
	 *
	 * @since 0.1.0
	 */
	public function test_an_invalid_encryption_key_is_refused_when_writing(): void {
		try {
			KeyMaterial::write( $this->key, EncryptionKey::fromValue( base64_encode( 'thirty-one bytes, one too short' ) ), $this->cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
			$this->fail( 'An invalid encryption key was ignored.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::EncryptionKeyInvalid, $refused->errorCode() );
		}
	}

	/**
	 * Asserts that material does not open with an encryption key.
	 *
	 * @since 0.1.0
	 *
	 * @param string        $material The material.
	 * @param EncryptionKey $kek      The encryption key.
	 */
	private function assertUnavailable( string $material, EncryptionKey $kek ): void {
		try {
			KeyMaterial::read( $material, $kek, $this->cipher );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::KeyUnavailable, $refused->errorCode() );

			return;
		}

		$this->fail( 'Material that should not open was read.' );
	}
}
