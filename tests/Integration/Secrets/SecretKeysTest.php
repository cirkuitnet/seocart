<?php
/**
 * SecretKeysTest: creating, rotating and storing the data keys, with and without the encryption key
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\KeyMaterial;
use SEOCart\Platform\Secrets\KeyRing;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test reads the options table as it is stored.

/**
 * The data keys document and the key registry, committed to a real database.
 *
 * @since 0.1.0
 */
final class SecretKeysTest extends DatabaseTestCase {

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
	 * Tests that the first key is created once, with its canary and its registry row, never autoloaded.
	 *
	 * @since 0.1.0
	 */
	public function test_initialize_creates_one_key_and_changes_nothing_the_second_time(): void {
		global $wpdb;

		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}\z/', $key_id );
		$this->assertSame( $key_id, $secrets->keys->initialize(), 'A second initialization created another key.' );
		$this->assertSame( $key_id, ( new SecretsHarness( $this->db ) )->keys->initialize(), 'A later request created another key.' );

		$stored = SecretsHarness::dataKeys();

		$this->assertSame( array( SecretKeys::ACTIVE, SecretKeys::CANARY ), array_keys( $stored ) );
		$this->assertSame( $key_id, KeyMaterial::keyId( $stored[ SecretKeys::ACTIVE ] ) );
		$this->assertStringStartsWith( 'v1:' . $key_id . ':', $stored[ SecretKeys::CANARY ] );
		$this->assertSame(
			array(
				array(
					'key_id'     => $key_id,
					'state'      => SecretKeysTable::ACTIVE,
					'retired_at' => null,
				),
			),
			SecretsHarness::registryRows()
		);
		$this->assertSame( 'off', $wpdb->get_var( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name = %s', $wpdb->options, SecretsHarness::DATA_KEYS_OPTION ) ) );
	}

	/**
	 * Tests that without the constant the key is stored as it is, and with it the key is wrapped.
	 *
	 * @since 0.1.0
	 */
	public function test_the_key_is_wrapped_exactly_when_the_constant_is_defined(): void {
		$plain = new SecretsHarness( $this->db );

		$plain->keys->initialize();

		$this->assertFalse( KeyMaterial::isWrapped( SecretsHarness::dataKeys()[ SecretKeys::ACTIVE ] ) );
		$this->assertSame( array( (string) $plain->keys->activeKeyId() => false ), $plain->keys->wrapped() );

		SecretsHarness::removeAll();
		SecretsHarness::createRegistry( $this->db );

		$kek     = SecretsHarness::newEncryptionKey();
		$wrapped = new SecretsHarness( $this->db, $kek );

		$wrapped->keys->initialize();

		$material = SecretsHarness::dataKeys()[ SecretKeys::ACTIVE ];

		$this->assertTrue( KeyMaterial::isWrapped( $material ) );
		$this->assertStringNotContainsString( $wrapped->keys->cipher()->encode( $wrapped->keys->active()->bytes() ), (string) SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ), 'The data key is in the database in the clear.' );
		$this->assertSame( $wrapped->keys->active()->bytes(), $wrapped->withKey( $kek )->keys->active()->bytes(), 'The next request with the same constant cannot open the key.' );
	}

	/**
	 * Tests that a constant that is defined but invalid stops the first key from being created.
	 *
	 * @since 0.1.0
	 */
	public function test_an_invalid_constant_stops_initialization(): void {
		$secrets = new SecretsHarness( $this->db, EncryptionKey::fromValue( 'not the base64 of 32 bytes' ) );

		try {
			$secrets->keys->initialize();
			$this->fail( 'An invalid constant was ignored and the key stored unprotected.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::EncryptionKeyInvalid, $refused->errorCode() );
		}

		$this->assertNull( SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ) );
		$this->assertSame( array(), SecretsHarness::registryRows() );
	}

	/**
	 * Tests that an activation that loses the race to create the first key returns the winner's.
	 *
	 * The loser read the site before the winner wrote: its store still caches "no document". Its
	 * insert then collides with the winner's row, and nothing of the loser's is kept.
	 *
	 * Planted violation: in SecretKeys::initialize(), rethrow every failure. The loser then fails
	 * with the version conflict instead of adopting the winner's key.
	 *
	 * @since 0.1.0
	 */
	public function test_an_activation_that_loses_the_race_returns_the_winners_key(): void {
		$loser = new SecretsHarness( $this->db );

		$this->assertNull( $loser->keys->activeKeyId(), 'The loser did not read the empty site first.' );

		$winner = ( new SecretsHarness( $this->db ) )->keys->initialize();

		wp_cache_set( SecretsHarness::DATA_KEYS_OPTION, false, 'options' );
		wp_cache_set( 'notoptions', array( SecretsHarness::DATA_KEYS_OPTION => true ), 'options' );

		$this->assertSame( $winner, $loser->keys->initialize() );
		$this->assertCount( 1, SecretsHarness::registryRows(), 'The loser\'s key was recorded too.' );
		$this->assertSame( $winner, KeyMaterial::keyId( SecretsHarness::dataKeys()[ SecretKeys::ACTIVE ] ) );
	}

	/**
	 * Tests that a rotation makes a new key active, keeps the old one retiring, and seals the canary again.
	 *
	 * @since 0.1.0
	 */
	public function test_rotate_replaces_the_active_key_and_keeps_the_old_one(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();
		$new     = $secrets->keys->rotate();
		$stored  = SecretsHarness::dataKeys();

		$this->assertNotSame( $old, $new );
		$this->assertSame( $new, KeyMaterial::keyId( $stored[ SecretKeys::ACTIVE ] ) );
		$this->assertSame( $old, KeyMaterial::keyId( $stored[ SecretKeys::RETIRING ] ) );
		$this->assertStringStartsWith( 'v1:' . $new . ':', $stored[ SecretKeys::CANARY ], 'The canary still proves the old key.' );
		$this->assertSame( array( SecretKeysTable::RETIRING, SecretKeysTable::ACTIVE ), array_column( SecretsHarness::registryRows(), 'state' ) );
		$this->assertSame( $old, $secrets->keys->key( $old )->id(), 'The retiring key no longer opens.' );
	}

	/**
	 * Tests that a second rotation waits until the first one's key is retired, and changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_second_rotation_is_refused_while_a_key_is_retiring(): void {
		$secrets = new SecretsHarness( $this->db );

		$first = $secrets->keys->initialize();

		$secrets->keys->rotate();

		$before = SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION );

		try {
			$secrets->keys->rotate();
			$this->fail( 'A second rotation dropped the retiring key.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::RotationPending, $refused->errorCode() );
			$this->assertSame( array( 'key_id' => $first ), $refused->context() );
		}

		$this->assertSame( $before, SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ) );
		$this->assertCount( 2, SecretsHarness::registryRows() );
	}

	/**
	 * Tests that a key that cannot be unwrapped is never rotated away, and nothing changes.
	 *
	 * @since 0.1.0
	 */
	public function test_rotation_is_refused_when_the_active_key_cannot_be_opened(): void {
		( new SecretsHarness( $this->db, SecretsHarness::newEncryptionKey() ) )->keys->initialize();

		$before  = SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION );
		$changed = new SecretsHarness( $this->db, SecretsHarness::newEncryptionKey() );

		try {
			$changed->keys->rotate();
			$this->fail( 'A key nothing can open any more was rotated away.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::KeyUnavailable, $refused->errorCode() );
		}

		$this->assertSame( $before, SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ) );
		$this->assertCount( 1, SecretsHarness::registryRows() );
	}

	/**
	 * Tests that a key id no slot holds is an unknown key, and no key before initialization is a typed error.
	 *
	 * @since 0.1.0
	 */
	public function test_unknown_and_missing_keys_are_typed_errors(): void {
		$secrets = new SecretsHarness( $this->db );

		try {
			$secrets->keys->active();
			$this->fail( 'A key was returned before any was created.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::NotInitialized, $refused->errorCode() );
		}

		$secrets->keys->initialize();

		try {
			$secrets->keys->key( '0123456789abcdef' );
			$this->fail( 'A key the site does not have was returned.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::UnknownKey, $refused->errorCode() );
			$this->assertSame( array( 'key_id' => '0123456789abcdef' ), $refused->context() );
		}
	}

	/**
	 * Tests that the keys are held for a write only inside a transaction, and retired only outside one.
	 *
	 * @since 0.1.0
	 */
	public function test_the_keys_are_held_inside_a_transaction_and_retired_outside_one(): void {
		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();

		$this->assertSame( $key_id, $this->db->transaction( fn(): string => $secrets->keys->ringForWrite()->active()->id() ) );
		$this->assertSame( $key_id, $secrets->keys->underLock( static fn( KeyRing $ring ): string => $ring->active()->id() ) );

		foreach (
			array(
				'held outside a transaction' => static fn() => $secrets->keys->ringForWrite(),
				'retired inside one'         => fn() => $this->db->transaction( static fn() => $secrets->keys->retireIfUnused( static fn(): bool => true ) ),
			) as $case => $attempt
		) {
			try {
				$attempt();
				$this->fail( "The keys were {$case}." );
			} catch ( \LogicException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	/**
	 * Tests that damaged or missing keys on a site that has had keys are reported, and never replaced.
	 *
	 * The data keys option is left byte for byte as it was, so it can still be restored.
	 *
	 * Planted violation: in SecretKeys::initialize(), create a key over the version read whenever
	 * the document holds no active key, as the first version did (`if ( null !== $current ) {
	 * return $current; }` in place of the refusals, and `$document->version()` in place of 0). The
	 * damaged document is then replaced, and every secret its keys sealed is lost.
	 *
	 * @since 0.1.0
	 */
	public function test_damaged_keys_are_reported_and_never_replaced(): void {
		( new SecretsHarness( $this->db ) )->keys->initialize();

		SecretsHarness::tamperDataKeys( array( SecretKeys::ACTIVE => null ) );

		foreach (
			array(
				'the active key removed' => (string) SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ),
				'no document at all'     => 'not a document',
			) as $case => $stored
		) {
			SecretsHarness::tamper( SecretsHarness::DATA_KEYS_OPTION, $stored );

			$this->assertRefusedAsDamaged( $case );
			$this->assertSame( $stored, SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ), "With {$case}, the data keys option was changed." );
			$this->assertCount( 1, SecretsHarness::registryRows(), "With {$case}, a key was added to the registry." );
		}

		SecretsHarness::removeOption( SecretsHarness::DATA_KEYS_OPTION );

		$this->assertRefusedAsDamaged( 'the option gone while the registry lists a key' );
		$this->assertNull( SecretsHarness::stored( SecretsHarness::DATA_KEYS_OPTION ), 'A new data keys option was created.' );
	}

	/**
	 * Asserts that initialization is refused as damaged keys.
	 *
	 * @since 0.1.0
	 *
	 * @param string $damage What is damaged.
	 */
	private function assertRefusedAsDamaged( string $damage ): void {
		try {
			( new SecretsHarness( $this->db ) )->keys->initialize();
			$this->fail( "With {$damage}, initialization replaced the keys." );
		} catch ( CodedException $refused ) {
			$this->assertSame( SecretsError::KeysDamaged, $refused->errorCode(), $damage );
		}
	}
}
