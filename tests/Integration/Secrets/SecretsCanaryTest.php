<?php
/**
 * SecretsCanaryTest: the canary opens while the keys are sound, and names what broke when they are not
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\CanaryFailure;
use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\DataKey;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\Envelope;
use SEOCart\Platform\Secrets\KeyMaterial;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;

/**
 * Every failure the kernel must act on, each planted in the stored keys or in the constant, and
 * each reported as its own CanaryFailure, never as an exception.
 *
 * @since 0.1.0
 */
final class SecretsCanaryTest extends DatabaseTestCase {

	/**
	 * Creates the key registry and removes what an earlier run left.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );
	}

	/**
	 * Removes what the test wrote.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		SecretsHarness::removeAll();

		parent::tear_down();
	}

	/**
	 * Tests that the canary opens with and without the constant, and after a rotation.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_opens_while_the_keys_are_sound(): void {
		$plain  = new SecretsHarness( $this->db );
		$key_id = $plain->keys->initialize();
		$result = $plain->canary->check();

		$this->assertTrue( $result->ok() );
		$this->assertSame( $key_id, $result->keyId );
		$this->assertNull( $result->cause() );

		$rotated = $plain->keys->rotate();

		$this->assertSame( $rotated, ( new SecretsHarness( $this->db ) )->canary->check()->keyId, 'The canary does not prove the key after a rotation.' );

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );

		$kek     = SecretsHarness::newEncryptionKey();
		$wrapped = new SecretsHarness( $this->db, $kek );

		$wrapped->keys->initialize();

		$this->assertTrue( $wrapped->withKey( $kek )->canary->check()->ok() );
	}

	/**
	 * Tests that the canary fails when the data key is replaced by another key.
	 *
	 * The replacement is planted in the data keys document: a new key, with a new id, in the active
	 * slot, as a restore of another site's option or a hand edit would leave it.
	 *
	 * Planted violation: in SecretsCanary::check(), drop the comparison of the canary's key id
	 * with the active key's. The check then reports Unreadable, not the replacement.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_fails_when_the_data_key_is_replaced(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();

		$cipher      = Cipher::detect();
		$replacement = DataKey::generate( $cipher );

		SecretsHarness::tamperDataKeys( array( SecretKeys::ACTIVE => KeyMaterial::write( $replacement, EncryptionKey::fromValue( null ), $cipher ) ) );

		$result = ( new SecretsHarness( $this->db ) )->canary->check();

		$this->assertSame( CanaryFailure::KeyReplaced, $result->failure );
		$this->assertSame( $replacement->id(), $result->keyId );
		$this->assertNotNull( $result->cause() );
	}

	/**
	 * Tests that the canary fails when the key keeps its id but its bytes are replaced.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_fails_when_the_key_bytes_are_replaced(): void {
		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();
		$cipher  = Cipher::detect();

		SecretsHarness::tamperDataKeys( array( SecretKeys::ACTIVE => KeyMaterial::write( new DataKey( $key_id, $cipher->randomKey() ), EncryptionKey::fromValue( null ), $cipher ) ) );

		$this->assertSame( CanaryFailure::Unreadable, ( new SecretsHarness( $this->db ) )->canary->check()->failure );
	}

	/**
	 * Tests that the canary fails when SEOCART_ENCRYPTION_KEY changes, is removed, or becomes invalid.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_fails_when_the_constant_changes(): void {
		$secrets = new SecretsHarness( $this->db, SecretsHarness::newEncryptionKey() );
		$key_id  = $secrets->keys->initialize();

		$changed = $secrets->withKey( SecretsHarness::newEncryptionKey() )->canary->check();

		$this->assertSame( CanaryFailure::EncryptionKeyMismatch, $changed->failure );
		$this->assertSame( $key_id, $changed->keyId );
		$this->assertSame( CanaryFailure::EncryptionKeyAbsent, $secrets->withKey( EncryptionKey::fromValue( null ) )->canary->check()->failure );
		$this->assertSame( CanaryFailure::EncryptionKeyInvalid, $secrets->withKey( EncryptionKey::fromValue( 'typo' ) )->canary->check()->failure );
	}

	/**
	 * Tests that the canary fails when there is no key, when the canary is gone or altered, and when the keys are damaged or gone.
	 *
	 * A site that never had a key is not initialized. A site that had keys and lost them — the
	 * canary gone, the active key cut short, the option no document or no option at all while the
	 * key registry lists keys — has damaged keys, which activation must not replace.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_names_every_other_failure(): void {
		$this->assertSame( CanaryFailure::NotInitialized, ( new SecretsHarness( $this->db ) )->canary->check()->failure );

		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();

		$canary = SecretsHarness::dataKeys()[ SecretKeys::CANARY ];

		SecretsHarness::tamperDataKeys( array( SecretKeys::CANARY => null ) );

		$this->assertSame( CanaryFailure::KeysDamaged, ( new SecretsHarness( $this->db ) )->canary->check()->failure );

		SecretsHarness::tamperDataKeys( array( SecretKeys::CANARY => SecretsHarness::flipped( $canary ) ) );

		$this->assertSame( CanaryFailure::Unreadable, ( new SecretsHarness( $this->db ) )->canary->check()->failure );

		$active = SecretsHarness::dataKeys()[ SecretKeys::ACTIVE ];

		SecretsHarness::tamperDataKeys(
			array(
				SecretKeys::CANARY => $canary,
				SecretKeys::ACTIVE => substr( $active, 0, -4 ),
			)
		);

		$this->assertSame( CanaryFailure::KeysDamaged, ( new SecretsHarness( $this->db ) )->canary->check()->failure );

		SecretsHarness::tamper( SecretsHarness::DATA_KEYS_OPTION, 'not a document' );

		$this->assertSame( CanaryFailure::KeysDamaged, ( new SecretsHarness( $this->db ) )->canary->check()->failure );

		SecretsHarness::removeOption( SecretsHarness::DATA_KEYS_OPTION );

		$gone = ( new SecretsHarness( $this->db ) )->canary->check();

		$this->assertSame( CanaryFailure::KeysDamaged, $gone->failure, 'Keys gone from a site whose registry lists keys were taken for a site that never had any.' );
		$this->assertNotNull( $gone->cause() );
	}

	/**
	 * Tests that a canary that opens to another text fails, compared in constant time.
	 *
	 * Planted violation: in SecretsCanary::check(), replace the hash_equals() comparison with
	 * `true`. The check then passes a canary that holds another text.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_fails_when_it_opens_to_another_text(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();

		SecretsHarness::tamperDataKeys( array( SecretKeys::CANARY => Envelope::seal( $secrets->keys->active(), 'another text', $secrets->keys->canaryRecord(), $secrets->keys->cipher() ) ) );

		$this->assertSame( CanaryFailure::Mismatch, ( new SecretsHarness( $this->db ) )->canary->check()->failure );
	}

	/**
	 * Tests that a PHP without the cipher fails the canary instead of throwing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_canary_fails_without_a_cipher(): void {
		( new SecretsHarness( $this->db ) )->keys->initialize();

		$result = ( new SecretsHarness( $this->db, null, static fn(): Cipher => Cipher::choose( false, false ) ) )->canary->check();

		$this->assertSame( CanaryFailure::NoCipher, $result->failure );
		$this->assertSame(
			array(
				'ok'      => false,
				'failure' => 'no_cipher',
				'key_id'  => null,
			),
			$result->toArray()
		);
	}
}
