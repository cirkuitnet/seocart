<?php
/**
 * SecretVaultTest: secrets stored sealed, opened for their user, and moved to a new key without loss
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\Envelope;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Platform\Secrets\SecretsError;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecretsHarness;
use SEOCart\Tests\Support\SettingsFixtures;

/**
 * The vault over the fixture secrets — two scalars and one in a document — committed to a real
 * database, with and without SEOCART_ENCRYPTION_KEY.
 *
 * @since 0.1.0
 */
final class SecretVaultTest extends DatabaseTestCase {

	/**
	 * The secrets the tests store, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const SECRETS = array(
		'api_key'        => 'sk_live_51HxQ2eKp9vT3mZ',
		'webhook_secret' => 'whsec_Zk42LmNq8rT0',
		'signing_secret' => 'sign_7Yp3Qr9Tz2Lk5Mn8',
	);

	/**
	 * The option of each fixture secret, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const OPTIONS = array(
		'api_key'        => 'seocart_fixture_gateway_api_key',
		'webhook_secret' => 'seocart_fixture_gateway_webhook_secret',
		'signing_secret' => 'seocart_fixture_vault',
	);

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
	 * Returns the configurations every round trip runs in: without the constant, and with it.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: bool}> The cases.
	 */
	public static function configurations(): array {
		return array(
			'without SEOCART_ENCRYPTION_KEY' => array( false ),
			'with SEOCART_ENCRYPTION_KEY'    => array( true ),
		);
	}

	/**
	 * Tests that every secret is stored sealed, never as plain text, and opens again in the next request.
	 *
	 * @dataProvider configurations
	 *
	 * @since 0.1.0
	 *
	 * @param bool $with_constant Whether SEOCART_ENCRYPTION_KEY is defined.
	 */
	public function test_a_secret_is_stored_sealed_and_opens_again( bool $with_constant ): void {
		$kek     = $with_constant ? SecretsHarness::newEncryptionKey() : null;
		$secrets = new SecretsHarness( $this->db, $kek );
		$key_id  = $secrets->keys->initialize();

		$this->assertNull( $secrets->vault->reveal( 'api_key' ), 'A secret never saved is not null.' );

		$secrets->write( self::SECRETS );

		foreach ( self::OPTIONS as $name => $option ) {
			$stored = (string) SecretsHarness::stored( $option );

			$this->assertStringNotContainsString( self::SECRETS[ $name ], $stored, "The option {$option} holds the plain text." );
			$this->assertStringContainsString( 'v1:' . $key_id . ':', $stored );
		}

		$next = null === $kek ? new SecretsHarness( $this->db ) : $secrets->withKey( $kek );

		foreach ( self::SECRETS as $name => $plaintext ) {
			$this->assertSame( $plaintext, $next->vault->reveal( $name ) );
		}

		$this->assertSame( array( $key_id => 3 ), $next->vault->counts() );
	}

	/**
	 * Tests that the store refuses a secret that is not sealed, whoever writes it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_store_refuses_a_plain_text_secret(): void {
		$secrets = new SecretsHarness( $this->db );

		try {
			$secrets->store->writeScalars( array( 'api_key' => self::SECRETS['api_key'] ) );
			$this->fail( 'The store wrote a secret as plain text.' );
		} catch ( \InvalidArgumentException $refused ) {
			$this->assertStringNotContainsString( self::SECRETS['api_key'], $refused->getMessage() );
		}

		$this->assertNull( SecretsHarness::stored( self::OPTIONS['api_key'] ) );
	}

	/**
	 * Tests that a secret is sealed only inside a transaction, and only for a secret setting that fits.
	 *
	 * @since 0.1.0
	 */
	public function test_sealing_refuses_what_it_must_not_seal(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();

		$api_key = $secrets->registry->setting( 'api_key' );

		foreach (
			array(
				'outside a transaction' => array( fn() => $secrets->vault->seal( $api_key, 'x' ), \LogicException::class ),
				'an ordinary setting'   => array( fn() => $this->db->transaction( fn() => $secrets->vault->seal( $secrets->registry->setting( 'weight_unit' ), 'kg' ) ), \InvalidArgumentException::class ),
				'a data key slot'       => array( fn() => $this->db->transaction( fn() => $secrets->vault->seal( $secrets->registry->setting( SecretKeys::CANARY ), 'x' ) ), \InvalidArgumentException::class ),
				'a text too long'       => array( fn() => $this->db->transaction( fn() => $secrets->vault->seal( $api_key, str_repeat( 'k', 61 ) ) ), \InvalidArgumentException::class ),
			) as $case => $attempt
		) {
			try {
				( $attempt[0] )();
				$this->fail( "The vault sealed {$case}." );
			} catch ( \Throwable $refused ) {
				$this->assertInstanceOf( $attempt[1], $refused, $case );
			}
		}
	}

	/**
	 * Tests that a stored secret altered in the database does not open, with a typed error.
	 *
	 * @since 0.1.0
	 */
	public function test_an_altered_secret_does_not_open(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();
		$secrets->write( self::SECRETS );

		SecretsHarness::tamper( self::OPTIONS['api_key'], SecretsHarness::flipped( (string) SecretsHarness::stored( self::OPTIONS['api_key'] ) ) );

		$this->assertRefused( SecretsError::Unreadable, fn() => ( new SecretsHarness( $this->db ) )->vault->reveal( 'api_key' ) );
	}

	/**
	 * Tests that a sealed secret copied into another secret's option does not open there.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_copied_into_another_option_does_not_open(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();
		$secrets->write( self::SECRETS );

		SecretsHarness::tamper( self::OPTIONS['webhook_secret'], (string) SecretsHarness::stored( self::OPTIONS['api_key'] ) );

		$this->assertRefused( SecretsError::Unreadable, fn() => ( new SecretsHarness( $this->db ) )->vault->reveal( 'webhook_secret' ) );
	}

	/**
	 * Tests that a secret sealed with a key the site does not have is an unknown key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_secret_sealed_with_an_unknown_key_is_a_typed_error(): void {
		$secrets = new SecretsHarness( $this->db );
		$key_id  = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );

		SecretsHarness::tamper( self::OPTIONS['api_key'], str_replace( 'v1:' . $key_id . ':', 'v1:0123456789abcdef:', (string) SecretsHarness::stored( self::OPTIONS['api_key'] ) ) );

		$this->assertRefused( SecretsError::UnknownKey, fn() => ( new SecretsHarness( $this->db ) )->vault->reveal( 'api_key' ) );
	}

	/**
	 * Tests that after a rotation every secret still opens, with the retiring key.
	 *
	 * @since 0.1.0
	 */
	public function test_secrets_open_with_the_retiring_key_after_a_rotation(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );

		$new  = $secrets->keys->rotate();
		$next = new SecretsHarness( $this->db );

		foreach ( self::SECRETS as $name => $plaintext ) {
			$this->assertSame( $plaintext, $next->vault->reveal( $name ) );
		}

		$this->assertSame( array( $old => 3 ), $next->vault->counts() );

		$next->write( array( 'api_key' => 'sk_live_rotated' ) );

		$this->assertSame( Envelope::keyId( (string) SecretsHarness::stored( self::OPTIONS['api_key'] ) ), $new, 'A secret written after the rotation was not sealed with the new key.' );
	}

	/**
	 * Tests that rotate then rekey leaves every record with the new key, and retires the old key only at zero.
	 *
	 * The first batch re-seals one record: two still name the old key, so it stays. The second
	 * re-seals the rest, and the old key is retired: its material leaves the document, and the
	 * registry records when.
	 *
	 * @dataProvider configurations
	 *
	 * @since 0.1.0
	 *
	 * @param bool $with_constant Whether SEOCART_ENCRYPTION_KEY is defined.
	 */
	public function test_rotate_then_rekey_moves_every_record_and_retires_the_old_key_at_zero( bool $with_constant ): void {
		$kek     = $with_constant ? SecretsHarness::newEncryptionKey() : null;
		$secrets = new SecretsHarness( $this->db, $kek );
		$old     = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );

		$new   = $secrets->keys->rotate();
		$first = $secrets->vault->rekey( 1 );

		$this->assertSame( 1, $first->resealed );
		$this->assertNull( $first->retired, 'The old key was retired while records still named it.' );
		$this->assertSame(
			self::sorted(
				array(
					$new => 1,
					$old => 2,
				)
			),
			$first->counts
		);
		$this->assertSame( 2, $first->remaining() );
		$this->assertSame( SecretKeysTable::RETIRING, SecretsHarness::registryRows()[0]['state'] );

		$second = $secrets->vault->rekey( 10 );

		$this->assertSame( 2, $second->resealed );
		$this->assertSame( $old, $second->retired );
		$this->assertSame( array( $new => 3 ), $second->counts );
		$this->assertSame( 0, $second->remaining() );
		$this->assertArrayNotHasKey( SecretKeys::RETIRING, SecretsHarness::dataKeys(), 'The retired key\'s material is still stored.' );

		$rows = SecretsHarness::registryRows();

		$this->assertSame( array( SecretKeysTable::RETIRED, SecretKeysTable::ACTIVE ), array_column( $rows, 'state' ) );
		$this->assertNotNull( $rows[0]['retired_at'] );

		$next = null === $kek ? new SecretsHarness( $this->db ) : $secrets->withKey( $kek );

		foreach ( self::SECRETS as $name => $plaintext ) {
			$this->assertSame( $plaintext, $next->vault->reveal( $name ) );
		}

		$this->assertSame( 0, $next->vault->rekey( 10 )->resealed, 'A rekey with nothing to do re-sealed something.' );
		$this->assertNotSame( $new, $next->keys->rotate(), 'The next rotation was refused although the old key is retired.' );
	}

	/**
	 * Tests that a record that cannot be opened keeps its key from being retired, and is named.
	 *
	 * Planted violation: in SecretVault::unused(), answer true whatever the census. The old key is
	 * then retired while a record still names it.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unreadable_record_keeps_its_key_from_retiring(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );
		$secrets->keys->rotate();

		SecretsHarness::tamper( self::OPTIONS['webhook_secret'], SecretsHarness::flipped( (string) SecretsHarness::stored( self::OPTIONS['webhook_secret'] ) ) );

		$report = ( new SecretsHarness( $this->db ) )->vault->rekey( 10 );

		$this->assertSame( 2, $report->resealed );
		$this->assertSame( array( 'seocart_fixture_gateway_webhook_secret/webhook_secret' ), $report->unreadable );
		$this->assertNull( $report->retired );
		$this->assertSame( 1, $report->counts[ $old ] ?? null );
		$this->assertSame( 0, $report->pending );
		$this->assertArrayHasKey( SecretKeys::RETIRING, SecretsHarness::dataKeys() );

		$next = new SecretsHarness( $this->db );

		$next->write( array( 'webhook_secret' => 'whsec_entered_again' ) );

		$this->assertSame( $old, $next->vault->rekey( 10 )->retired, 'Entering the record again did not let the key retire.' );
	}

	/**
	 * Tests that a record changed between the rekey's read and its write is left for the next batch, not overwritten.
	 *
	 * Another request, on a second connection, writes a newer value at the moment the rekey's
	 * replacement is about to leave: after the rekey has read the record and sealed it again. The
	 * replacement must find the row changed and leave it.
	 *
	 * Planted violation: in SecretVault::replace(), write scalars with
	 * `$this->store->writeScalars( array( $setting->name() => $fresh ) ); return true;`. The rekey then
	 * overwrites the newer value with the older secret.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_changed_meanwhile_is_left_for_the_next_batch(): void {
		global $wpdb;

		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();
		$secrets->write( array( 'api_key' => 'sk_live_newer' ) );

		$newer = (string) SecretsHarness::stored( self::OPTIONS['api_key'] );

		$secrets->write( array( 'api_key' => 'sk_live_older' ) );
		$secrets->keys->rotate();

		$b     = $this->secondConnection();
		$fired = false;

		add_filter(
			'query',
			static function ( string $sql ) use ( $b, $newer, &$fired, $wpdb ): string {
				if ( ! $fired && 1 === preg_match( '/^(?:UPDATE|INSERT)\b/', $sql ) && str_contains( $sql, "'" . self::OPTIONS['api_key'] . "'" ) ) {
					$fired = true;

					$b->query( (string) $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $newer, self::OPTIONS['api_key'] ) );
				}

				return $sql;
			}
		);

		$report = $secrets->vault->rekey( 10 );

		$this->assertTrue( $fired, 'The other request never wrote, so the test proves nothing.' );
		$this->assertSame( 0, $report->resealed );
		$this->assertSame( 1, $report->changed );
		$this->assertSame( 1, $report->pending );
		$this->assertNull( $report->retired, 'The old key was retired while the newer value still names it.' );
		$this->assertSame( $newer, SecretsHarness::stored( self::OPTIONS['api_key'] ), 'The rekey overwrote a newer value.' );

		$again = $secrets->vault->rekey( 10 );

		$this->assertSame( 1, $again->resealed );
		$this->assertNotNull( $again->retired );
		$this->assertSame( 'sk_live_newer', ( new SecretsHarness( $this->db ) )->vault->reveal( 'api_key' ) );
	}

	/**
	 * Tests that a rekey acts on the keys and records as committed, not on what the object cache remembers.
	 *
	 * This request cached the data keys before another request rotated them, as a persistent object
	 * cache would still hold them. The rekey must seal with the key the other request made active,
	 * and retire the key it made retiring.
	 *
	 * Planted violation: in SecretKeys::ringForWrite(), read the document through the cache
	 * (`$this->store->document( self::GROUP )`). The rekey then takes the replaced key for the
	 * active one, and leaves every record sealed with it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rekey_acts_on_the_committed_keys_not_on_a_cached_rotation(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( self::SECRETS );

		$this->assertSame( $old, $secrets->keys->activeKeyId(), 'This request did not cache the keys first.' );

		$cached = wp_cache_get( SecretsHarness::DATA_KEYS_OPTION, 'options' );
		$new    = ( new SecretsHarness( $this->db ) )->keys->rotate();

		wp_cache_set( SecretsHarness::DATA_KEYS_OPTION, $cached, 'options' );

		$this->assertSame( $old, $secrets->keys->activeKeyId(), 'The cache does not lag behind the rotation, so the test proves nothing.' );

		$report = $secrets->vault->rekey( 10 );

		$this->assertSame( $new, $report->activeKeyId );
		$this->assertSame( 3, $report->resealed );
		$this->assertSame( $old, $report->retired );

		foreach ( self::OPTIONS as $option ) {
			$this->assertStringContainsString( 'v1:' . $new . ':', (string) SecretsHarness::stored( $option ) );
		}
	}

	/**
	 * Tests that a record whose stored text is not a sealed value is counted under no key and reported.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_that_is_not_sealed_is_counted_under_no_key(): void {
		$secrets = new SecretsHarness( $this->db );

		$secrets->keys->initialize();
		$secrets->write( array( 'api_key' => 'sk_live_one' ) );

		SecretsHarness::tamper( self::OPTIONS['api_key'], 'typed straight into the database' );

		$next = new SecretsHarness( $this->db );

		$this->assertSame( array( SecretVault::NO_KEY => 1 ), $next->vault->counts() );
		$this->assertSame( array( 'seocart_fixture_gateway_api_key/api_key' ), $next->vault->rekey( 10 )->damaged );
	}

	/**
	 * Tests that a secret whose document holds a broken neighbour is still counted under its key, keeps that key, and opens once the neighbour is repaired.
	 *
	 * The secret is sealed with key 1, the keys are rotated, and then the ordinary setting stored in
	 * the same document is broken by hand. The store cannot read the document as a whole any more,
	 * but the secret's own text still names key 1, which must not be retired.
	 *
	 * Planted violation: in SecretVault::census(), take the key from the store's checked read
	 * instead of the stored text's header (`$this->readableAsStored( $setting ) ? Envelope::keyId(
	 * (string) $this->store->valuesAsStored( array( $setting ) )[ $setting->name() ] ) : null`). The
	 * secret is then counted under no key.
	 *
	 * @since 0.1.0
	 */
	public function test_a_broken_neighbour_keeps_the_secret_counted_under_its_key(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( array( 'signing_secret' => self::SECRETS['signing_secret'] ) );
		$secrets->keys->rotate();

		$stored = (string) SecretsHarness::stored( self::OPTIONS['signing_secret'] );
		$broken = json_decode( $stored, true );

		$broken['values']['endpoint'] = str_repeat( 'x', 201 );

		SecretsHarness::tamper( self::OPTIONS['signing_secret'], (string) wp_json_encode( $broken ) );

		$next = new SecretsHarness( $this->db );

		$this->assertSame( array( $old => 1 ), $next->vault->counts(), 'The secret is not counted under the key its text names.' );

		$report = $next->vault->rekey( 10 );

		$this->assertNull( $report->retired, 'The key was retired while a secret it sealed could not be read.' );
		$this->assertSame( array( 'seocart_fixture_vault/signing_secret' ), $report->damaged );
		$this->assertArrayHasKey( SecretKeys::RETIRING, SecretsHarness::dataKeys() );

		SecretsHarness::tamper( self::OPTIONS['signing_secret'], $stored );

		$repaired = new SecretsHarness( $this->db );

		$this->assertSame( self::SECRETS['signing_secret'], $repaired->vault->reveal( 'signing_secret' ) );
		$this->assertSame( $old, $repaired->vault->rekey( 10 )->retired );
		$this->assertSame( self::SECRETS['signing_secret'], ( new SecretsHarness( $this->db ) )->vault->reveal( 'signing_secret' ) );
	}

	/**
	 * Tests that a record the store cannot read at all keeps every key from being retired.
	 *
	 * The document holding the secret is cut short, so no header names its key any more; only the
	 * rule that nothing is retired while a record cannot be read as stored keeps key 1.
	 *
	 * Planted violation: in SecretVault::unused(), drop the `! $entry['readable']` condition. Key 1 is
	 * then retired, and the secret can never be opened again, even once the document is repaired.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_unreadable_as_stored_keeps_every_key_from_retiring(): void {
		$secrets = new SecretsHarness( $this->db );
		$old     = $secrets->keys->initialize();

		$secrets->write( array( 'signing_secret' => self::SECRETS['signing_secret'] ) );
		$secrets->keys->rotate();

		$stored = (string) SecretsHarness::stored( self::OPTIONS['signing_secret'] );

		SecretsHarness::tamper( self::OPTIONS['signing_secret'], substr( $stored, 0, 20 ) );

		$report = ( new SecretsHarness( $this->db ) )->vault->rekey( 10 );

		$this->assertNull( $report->retired, 'A key was retired while a record could not be read.' );
		$this->assertSame( array( SecretVault::NO_KEY => 1 ), $report->counts );
		$this->assertSame( array( 'seocart_fixture_vault/signing_secret' ), $report->damaged );

		SecretsHarness::tamper( self::OPTIONS['signing_secret'], $stored );

		$repaired = new SecretsHarness( $this->db );

		$this->assertSame( $old, $repaired->vault->rekey( 10 )->retired );
		$this->assertSame( self::SECRETS['signing_secret'], ( new SecretsHarness( $this->db ) )->vault->reveal( 'signing_secret' ) );
	}

	/**
	 * Tests that the vault's records are the secret settings, the data keys' own excepted.
	 *
	 * @since 0.1.0
	 */
	public function test_the_records_are_the_secrets_but_the_data_keys(): void {
		$names = array_map( static fn( $setting ): string => $setting->name(), ( new SecretsHarness( $this->db ) )->vault->records() );

		$this->assertSame( array_keys( self::SECRETS ), $names );
		$this->assertSame( SettingsFixtures::VAULT, ( new SecretsHarness( $this->db ) )->registry->setting( 'signing_secret' )->group() );
	}

	/**
	 * Asserts that an attempt fails with a secrets code.
	 *
	 * @since 0.1.0
	 *
	 * @param SecretsError $code    The code.
	 * @param \Closure     $attempt The attempt.
	 */
	private function assertRefused( SecretsError $code, \Closure $attempt ): void {
		try {
			$opened = $attempt();
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode() );

			return;
		}

		$this->fail( 'A secret that should not open was opened, to ' . strlen( (string) $opened ) . ' bytes.' );
	}

	/**
	 * Sorts counts by key id, as counts() does.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int> $counts The counts.
	 * @return array<string, int> The counts, sorted by key id.
	 */
	private static function sorted( array $counts ): array {
		ksort( $counts );

		return $counts;
	}
}
