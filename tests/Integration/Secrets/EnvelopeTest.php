<?php
/**
 * EnvelopeTest: a sealed secret opens only as it was sealed, for the record it was sealed for
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\DataKey;
use SEOCart\Platform\Secrets\Envelope;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Support\Error\CodedException;
use WP_UnitTestCase;

/**
 * Seals and opens secrets with the cipher this PHP provides: the sodium extension when it is loaded,
 * WordPress's sodium_compat otherwise. It runs as an integration test because WordPress is what
 * loads sodium_compat.
 *
 * Every way of tampering with a sealed value fails with SecretsError::Unreadable, never with an
 * empty string or another text.
 *
 * @since 0.1.0
 */
final class EnvelopeTest extends WP_UnitTestCase {

	/**
	 * The record the tests seal for.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RECORD = 'seocart_fixture_gateway_api_key/api_key';

	/**
	 * The secret the tests seal.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SECRET = 'sk_live_4f9a1c77e2b8d6';

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
	 * Tests that the cipher comes from the extension when it is loaded and from sodium_compat otherwise.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cipher_comes_from_the_extension_when_there_is_one(): void {
		$this->assertSame( extension_loaded( 'sodium' ) ? Cipher::EXTENSION : Cipher::POLYFILL, $this->cipher->library() );
	}

	/**
	 * Tests that a sealed secret opens to itself, has the documented shape, and never repeats.
	 *
	 * @since 0.1.0
	 */
	public function test_a_sealed_secret_opens_to_itself(): void {
		$sealed = Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher );

		$this->assertMatchesRegularExpression( '/^v1:' . $this->key->id() . ':[A-Za-z0-9_-]{32}:[A-Za-z0-9_-]+\z/', $sealed );
		$this->assertStringNotContainsString( self::SECRET, $sealed );
		$this->assertSame( $this->key->id(), Envelope::keyId( $sealed ) );
		$this->assertSame( self::SECRET, Envelope::open( $sealed, $this->key, self::RECORD, $this->cipher ) );
		$this->assertNotSame( $sealed, Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher ), 'Two seals of one secret are identical: the nonce is not fresh.' );
		$this->assertSame( '', Envelope::open( Envelope::seal( $this->key, '', self::RECORD, $this->cipher ), $this->key, self::RECORD, $this->cipher ) );
	}

	/**
	 * Tests that a sealed value with one byte of its cipher text changed does not open.
	 *
	 * @since 0.1.0
	 */
	public function test_a_flipped_byte_does_not_open(): void {
		$sealed = Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher );
		$parts  = explode( ':', $sealed );
		$bytes  = (string) $this->cipher->decode( $parts[3] );

		$bytes[0] = chr( ord( $bytes[0] ) ^ 0x01 );
		$parts[3] = $this->cipher->encode( $bytes );

		$this->assertUnreadable( implode( ':', $parts ), $this->key, self::RECORD );

		$nonce    = (string) $this->cipher->decode( $parts[2] );
		$nonce[0] = chr( ord( $nonce[0] ) ^ 0x01 );
		$parts    = explode( ':', $sealed );
		$parts[2] = $this->cipher->encode( $nonce );

		$this->assertUnreadable( implode( ':', $parts ), $this->key, self::RECORD );
	}

	/**
	 * Tests that a sealed value copied into another option does not open there.
	 *
	 * Planted violation: in Envelope::associatedData(), return `"seocart secret\n" . $header`,
	 * leaving the record out. The value copied to the other option then opens.
	 *
	 * @since 0.1.0
	 */
	public function test_a_value_copied_into_another_option_does_not_open(): void {
		$sealed = Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher );

		$this->assertUnreadable( $sealed, $this->key, 'seocart_fixture_gateway_webhook_secret/webhook_secret' );
	}

	/**
	 * Tests that a sealed value whose header names another key id does not open, even with the same key bytes.
	 *
	 * The same bytes under another id stand for a key's material copied under another id: only the
	 * header, bound by the associated data, tells them apart.
	 *
	 * Planted violation: in Envelope::associatedData(), leave the header out
	 * (`return "seocart secret\n" . $record;`). The value moved to the other id then opens.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wrong_key_id_does_not_open(): void {
		$sealed = Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher );
		$other  = new DataKey( 'ffffffffffffffff' === $this->key->id() ? '0000000000000000' : 'ffffffffffffffff', $this->key->bytes() );
		$moved  = 'v1:' . $other->id() . substr( $sealed, strlen( 'v1:' ) + 16 );

		$this->assertUnreadable( $moved, $other, self::RECORD );
		$this->assertUnreadable( $sealed, $other, self::RECORD );
		$this->assertUnreadable( $sealed, DataKey::generate( $this->cipher ), self::RECORD );
	}

	/**
	 * Tests that text that is not a sealed value is refused with the same code.
	 *
	 * @since 0.1.0
	 */
	public function test_text_that_is_not_a_sealed_value_does_not_open(): void {
		$sealed = Envelope::seal( $this->key, self::SECRET, self::RECORD, $this->cipher );

		foreach ( array( '', self::SECRET, 'v2' . substr( $sealed, 2 ), $sealed . '=', substr( $sealed, 0, -3 ), 'v1:' . $this->key->id() . ':short:AAAA' ) as $text ) {
			$this->assertUnreadable( $text, $this->key, self::RECORD );
		}
	}

	/**
	 * Asserts that a value does not open, with SecretsError::Unreadable naming the record.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $sealed The value.
	 * @param DataKey $key    The key to open it with.
	 * @param string  $record The record to open it for.
	 */
	private function assertUnreadable( string $sealed, DataKey $key, string $record ): void {
		try {
			$opened = Envelope::open( $sealed, $key, $record, $this->cipher );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::Unreadable, $refused->errorCode() );
			$this->assertSame( array( 'record' => $record ), $refused->context() );

			return;
		}

		$this->fail( 'A tampered value opened, to ' . strlen( $opened ) . ' bytes.' );
	}
}
