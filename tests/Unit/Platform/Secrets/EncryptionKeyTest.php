<?php
/**
 * EncryptionKeyTest: what SEOCART_ENCRYPTION_KEY amounts to, and that the key never shows
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Secrets;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\DataKey;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\EncryptionKeyState;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Support\Error\CodedException;

/**
 * The encryption key's states, the data key's shape, the choice of cipher library, and the ways a
 * key could leak through PHP's own debugging functions.
 *
 * @since 0.1.0
 */
final class EncryptionKeyTest extends TestCase {

	/**
	 * Tests that no constant is an absent key, a base64 encoding of 32 bytes a present one, and anything else invalid.
	 *
	 * @since 0.1.0
	 */
	public function test_the_constant_is_absent_present_or_invalid(): void {
		$bytes = random_bytes( 32 );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
		$present = EncryptionKey::fromValue( base64_encode( $bytes ) );
		$padded  = EncryptionKey::fromValue( "  \n" . base64_encode( $bytes ) . "\n" );
		// phpcs:enable

		$this->assertSame( EncryptionKeyState::Absent, EncryptionKey::fromValue( null )->state() );
		$this->assertNull( EncryptionKey::fromValue( null )->bytes() );
		$this->assertSame( EncryptionKeyState::Present, $present->state() );
		$this->assertSame( $bytes, $present->bytes() );
		$this->assertSame( $bytes, $padded->bytes(), 'Surrounding white space from wp-config.php is not ignored.' );

		foreach (
			array(
				'text'                     => 'correct horse battery staple',
				'31 bytes'                 => base64_encode( random_bytes( 31 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
				'33 bytes'                 => base64_encode( random_bytes( 33 ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
				'32 raw bytes'             => str_repeat( "\xff", 32 ),
				'an empty string'          => '',
				'a number'                 => 42,
				'true'                     => true,
				'an array of the encoding' => array( base64_encode( $bytes ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
			) as $case => $value
		) {
			$key = EncryptionKey::fromValue( $value );

			$this->assertSame( EncryptionKeyState::Invalid, $key->state(), $case );
			$this->assertNull( $key->bytes(), $case );
		}
	}

	/**
	 * Tests that neither key shows its bytes to var_dump(), print_r() or serialize().
	 *
	 * @since 0.1.0
	 */
	public function test_the_keys_do_not_show_their_bytes(): void {
		$bytes = str_repeat( 'K', 32 );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
		$kek  = EncryptionKey::fromValue( base64_encode( $bytes ) );
		$data = new DataKey( '0123456789abcdef', $bytes );

		foreach ( array( $kek, $data ) as $key ) {
			ob_start();
			var_dump( $key ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- what a developer's dump would show.
			$dumped = (string) ob_get_clean();

			$this->assertStringNotContainsString( $bytes, $dumped );
			$this->assertStringNotContainsString( $bytes, print_r( $key, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- what a developer's dump would show.

			try {
				serialize( $key ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the test proves it is refused.
				$this->fail( 'A key was serialized.' );
			} catch ( \LogicException ) {
				$this->addToAssertionCount( 1 );
			}
		}

		$this->assertSame( array( 'id' => '0123456789abcdef' ), $data->__debugInfo() );
		$this->assertSame( array( 'state' => 'present' ), $kek->__debugInfo() );
	}

	/**
	 * Tests that a data key has a sixteen-digit hexadecimal id and 32 bytes, or is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_data_key_has_its_shape_or_is_refused(): void {
		foreach ( array( array( '0123456789ABCDEF', 32 ), array( '0123456789abcde', 32 ), array( '0123456789abcdef', 31 ) ) as $case ) {
			try {
				new DataKey( $case[0], str_repeat( 'k', $case[1] ) );
				$this->fail( 'A data key of the wrong shape was made.' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Tests that the cipher comes from the extension when it is loaded, from sodium_compat otherwise, and is required.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cipher_prefers_the_extension_and_is_required(): void {
		$this->assertSame( Cipher::EXTENSION, Cipher::choose( true, true )->library() );
		$this->assertSame( Cipher::POLYFILL, Cipher::choose( false, true )->library() );

		foreach ( array( true, false ) as $extension ) {
			try {
				Cipher::choose( $extension, false );
				$this->fail( 'A cipher was chosen without its functions.' );
			} catch ( CodedException $refused ) {
				$this->assertSame( SecretsError::NoCipher, $refused->errorCode() );
			}
		}
	}
}
