<?php
/**
 * SecretRekeyConcurrencyTest: a rekey never seals a secret with a key that has been replaced or retired
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Secrets;

use SEOCart\Platform\Secrets\KeyMaterial;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\SecondConnection;
use SEOCart\Tests\Support\SecretsHarness;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- The test snapshots and restores the rows it plays with, and B, another request, sends them as prepared statements.

/**
 * One rekey, A, runs on wpdb's connection while another request, B, on a second connection, moves
 * the site on by one or two whole rotations: new keys, every record re-sealed with them, the old
 * keys retired. B strikes at the moment A reads its first record, after A has learned which key is
 * active. The site B moves to is computed beforehand, by running those rotations for real, and B
 * writes it: first the data keys document, then the records and the key registry.
 *
 * Whatever A does, every record must end sealed with the active or the retiring key of the data
 * keys document as it ends. A must therefore hold the keys while it re-seals a record, so that B's
 * change of the keys waits for A's record to be written.
 *
 * @group concurrency
 *
 * @since 0.1.0
 */
final class SecretRekeyConcurrencyTest extends DatabaseTestCase {

	/**
	 * The secrets the tests store, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const SECRETS = array(
		'api_key'        => 'sk_live_race_5Tq8Wn2Kd',
		'webhook_secret' => 'whsec_race_9Lm3Xp6Vb',
		'signing_secret' => 'sign_race_4Hj7Rc1Fz',
	);

	/**
	 * The options that hold the records.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const RECORD_OPTIONS = array( 'seocart_fixture_gateway_api_key', 'seocart_fixture_gateway_webhook_secret', 'seocart_fixture_vault' );

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
	 * Tests that a rekey that learned the keys before a rotation, a re-sealing and a retirement elsewhere seals nothing with the replaced key.
	 *
	 * A starts with key A active and key B retiring. B then retires key B, rotates to key C, moves
	 * every record to C and retires A. A, reading its records, finds them sealed with C.
	 *
	 * Planted violation: in SecretVault::rekey(), read the keys once, before the records
	 * (`$ring = $this->keys->underLock( static fn( KeyRing $ring ): KeyRing => $ring );`), and re-seal
	 * each record with that ring outside underLock(). B's change of the keys then no longer waits
	 * for A's record. A rekey that also opens each record with the keys as they are when it reads
	 * it, as the first version did, then re-seals C's records with A, which B has retired, and they
	 * never open again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_rekey_seals_nothing_with_a_key_replaced_while_it_ran(): void {
		$site = new SecretsHarness( $this->db );

		$site->keys->initialize();
		$site->write( self::SECRETS );
		$site->keys->rotate();

		$this->race(
			static function ( SecretsHarness $other ): void {
				$other->vault->rekey( 10 );
				$other->keys->rotate();
				$other->vault->rekey( 10 );
			}
		);
	}

	/**
	 * Tests that a rekey that learned the keys before one rotation elsewhere does not seal a record back with the retired key.
	 *
	 * A starts with key 1 active and nothing retiring. B rotates to key 2, moves every record to it
	 * and retires key 1. A, reading its records, finds them sealed with key 2.
	 *
	 * Planted violation: the one above. The first version's rekey re-sealed key 2's records with
	 * key 1.
	 *
	 * @since 0.1.0
	 */
	public function test_a_stale_rekey_does_not_seal_a_record_back_with_a_retired_key(): void {
		$site = new SecretsHarness( $this->db );

		$site->keys->initialize();
		$site->write( self::SECRETS );

		$this->race(
			static function ( SecretsHarness $other ): void {
				$other->keys->rotate();
				$other->vault->rekey( 10 );
			}
		);
	}

	/**
	 * Runs A's rekey while B moves the site to the state the given steps lead to, and checks every record's key.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $steps What the other request does, run beforehand to learn the state B writes.
	 */
	private function race( \Closure $steps ): void {
		$before = self::snapshot();

		$steps( new SecretsHarness( $this->db ) );

		$after = self::snapshot();

		self::restore( $before );

		$this->assertNotSame( $before['options'][ SecretsHarness::DATA_KEYS_OPTION ], $after['options'][ SecretsHarness::DATA_KEYS_OPTION ], 'The other request changed no key, so the race proves nothing.' );

		$statements = self::statements( $after );
		$b          = $this->secondConnection();
		$fired      = false;
		$blocked    = false;

		add_filter(
			'query',
			function ( string $sql ) use ( $b, $statements, &$fired, &$blocked ): string {
				if ( $fired || ! str_starts_with( $sql, 'SELECT' ) || ! str_contains( $sql, "'" . self::RECORD_OPTIONS[0] . "'" ) ) {
					return $sql;
				}

				$fired = true;

				$b->queryAsync( $statements[0] );

				if ( ! $b->isReady( 250 ) ) {
					$this->awaitWaiting( $b, $statements[0], 'updating' );

					$blocked = true;

					return $sql;
				}

				$b->reap();
				self::send( $b, array_slice( $statements, 1 ) );

				return $sql;
			}
		);

		( new SecretsHarness( $this->db ) )->vault->rekey( 10 );

		$this->assertTrue( $fired, 'A read no record, so the race proves nothing.' );

		if ( $blocked ) {
			$b->reap();
			self::send( $b, array_slice( $statements, 1 ) );
		}

		$this->assertSame( array(), self::lost(), 'A record was sealed with a key that is neither active nor retiring, and can never be opened again.' );
		$this->assertTrue( $blocked, 'B changed the keys while A was re-sealing a record.' );

		wp_cache_flush();

		$next = new SecretsHarness( $this->db );

		foreach ( self::SECRETS as $name => $plaintext ) {
			$this->assertSame( $plaintext, $next->vault->reveal( $name ) );
		}
	}

	/**
	 * Names every record whose stored text names a key the data keys document no longer holds.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The records, with the key each names.
	 */
	private static function lost(): array {
		$slots  = SecretsHarness::dataKeys();
		$usable = array_filter( array( KeyMaterial::keyId( $slots[ SecretKeys::ACTIVE ] ?? '' ), KeyMaterial::keyId( $slots[ SecretKeys::RETIRING ] ?? '' ) ) );
		$lost   = array();

		foreach ( array(
			'api_key'        => self::RECORD_OPTIONS[0],
			'webhook_secret' => self::RECORD_OPTIONS[1],
		) as $name => $option ) {
			$key_id = self::keyId( (string) SecretsHarness::stored( $option ) );

			if ( ! in_array( $key_id, $usable, true ) ) {
				$lost[] = $name . ' sealed with ' . (string) $key_id;
			}
		}

		$vault  = json_decode( (string) SecretsHarness::stored( self::RECORD_OPTIONS[2] ), true );
		$key_id = self::keyId( (string) ( $vault['values']['signing_secret'] ?? '' ) );

		if ( ! in_array( $key_id, $usable, true ) ) {
			$lost[] = 'signing_secret sealed with ' . (string) $key_id;
		}

		return $lost;
	}

	/**
	 * Returns the key id a stored text's header names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The stored text.
	 * @return string|null The key id, or null when the text has no v1 header.
	 */
	private static function keyId( string $text ): ?string {
		return 1 === preg_match( '/^v1:([0-9a-f]{16}):/', $text, $parts ) ? $parts[1] : null;
	}

	/**
	 * Reads the rows the race plays with: the data keys, the records and the key registry.
	 *
	 * @since 0.1.0
	 *
	 * @return array{options: array<string, string>, keys: list<array<string, string|null>>} The rows.
	 */
	private static function snapshot(): array {
		global $wpdb;

		$options = array();

		foreach ( array_merge( array( SecretsHarness::DATA_KEYS_OPTION ), self::RECORD_OPTIONS ) as $option ) {
			$options[ $option ] = (string) SecretsHarness::stored( $option );
		}

		$keys = $wpdb->get_results( $wpdb->prepare( 'SELECT key_id, state, algorithm, created_at, retired_at FROM %i ORDER BY key_id', self::registry() ), ARRAY_A );

		return array(
			'options' => $options,
			'keys'    => array_values( (array) $keys ),
		);
	}

	/**
	 * Writes a snapshot back.
	 *
	 * @since 0.1.0
	 *
	 * @param array{options: array<string, string>, keys: list<array<string, string|null>>} $snapshot The rows.
	 */
	private static function restore( array $snapshot ): void {
		global $wpdb;

		foreach ( self::statements( $snapshot ) as $statement ) {
			$wpdb->query( $statement );
		}

		wp_cache_flush();
	}

	/**
	 * Returns the statements that write a snapshot: the data keys first, then the records and the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param array{options: array<string, string>, keys: list<array<string, string|null>>} $snapshot The rows.
	 * @return list<string> The statements, prepared.
	 */
	private static function statements( array $snapshot ): array {
		global $wpdb;

		$statements = array();

		foreach ( $snapshot['options'] as $option => $value ) {
			$statements[] = (string) $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $value, $option );
		}

		$statements[] = (string) $wpdb->prepare( 'DELETE FROM %i', self::registry() );

		foreach ( $snapshot['keys'] as $row ) {
			$statements[] = null === $row['retired_at']
				? (string) $wpdb->prepare( 'INSERT INTO %i ( key_id, state, algorithm, created_at ) VALUES ( %s, %s, %s, %s )', self::registry(), $row['key_id'], $row['state'], $row['algorithm'], $row['created_at'] )
				: (string) $wpdb->prepare( 'INSERT INTO %i ( key_id, state, algorithm, created_at, retired_at ) VALUES ( %s, %s, %s, %s, %s )', self::registry(), $row['key_id'], $row['state'], $row['algorithm'], $row['created_at'], $row['retired_at'] );
		}

		return $statements;
	}

	/**
	 * Sends statements on B, one after the other.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b          Connection B.
	 * @param string[]         $statements The statements.
	 */
	private static function send( SecondConnection $b, array $statements ): void {
		foreach ( $statements as $statement ) {
			$b->query( $statement );
		}
	}

	/**
	 * Returns the key registry's table name.
	 *
	 * @since 0.1.0
	 *
	 * @return string The table name.
	 */
	private static function registry(): string {
		global $wpdb;

		return $wpdb->prefix . 'seocart_' . SecretKeysTable::NAME;
	}
}
