<?php
/**
 * SecretsHarness: the secrets module wired over the fixture settings, for tests that commit
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Secrets\Cipher;
use SEOCart\Platform\Secrets\EncryptionKey;
use SEOCart\Platform\Secrets\Migrations\CreateSecretKeysMigration;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Platform\Secrets\SecretKeysTable;
use SEOCart\Platform\Secrets\SecretsCanary;
use SEOCart\Platform\Secrets\SecretsStatus;
use SEOCart\Platform\Secrets\SecretVault;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Platform\Settings\SettingsStore;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The harness creates and removes the key registry and the options a test committed, and tampers with stored rows on purpose.

/**
 * Wires the keys, the vault, the canary and the status over SettingsFixtures::withSecrets(), the
 * way the kernel will over the plugin's settings.
 *
 * A harness stands for one request: its keys remember the keys they unwrapped. withKey() builds
 * the next request, over the same database, with another SEOCART_ENCRYPTION_KEY, which is how a
 * test changes, adds or removes the constant. Everything is committed: the tests that use it are
 * DatabaseTestCase tests, and removeAll() deletes what they wrote.
 *
 * @since 0.1.0
 */
final class SecretsHarness {

	/**
	 * The name of the data keys option.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DATA_KEYS_OPTION = 'seocart_data_keys';

	/**
	 * The database wrapper.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	public Database $db;

	/**
	 * The settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	public SettingsRegistry $registry;

	/**
	 * The store.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	public SettingsStore $store;

	/**
	 * The encryption key this request sees.
	 *
	 * @since 0.1.0
	 *
	 * @var EncryptionKey
	 */
	public EncryptionKey $encryptionKey;

	/**
	 * The data keys.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretKeys
	 */
	public SecretKeys $keys;

	/**
	 * The vault.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretVault
	 */
	public SecretVault $vault;

	/**
	 * The canary.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretsCanary
	 */
	public SecretsCanary $canary;

	/**
	 * The status.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretsStatus
	 */
	public SecretsStatus $status;

	/**
	 * Wires the module.
	 *
	 * @since 0.1.0
	 *
	 * @param Database           $db            The database wrapper.
	 * @param EncryptionKey|null $encryptionKey Optional. The encryption key. Default none defined.
	 * @param \Closure|null      $detect        Optional. Detects the cipher. Default Cipher::detect().
	 *
	 * @phpstan-param (\Closure(): Cipher)|null $detect
	 */
	public function __construct( Database $db, ?EncryptionKey $encryptionKey = null, ?\Closure $detect = null ) {
		$this->db            = $db;
		$this->registry      = SettingsFixtures::withSecrets();
		$this->store         = new SettingsStore( $this->registry, $db );
		$this->encryptionKey = $encryptionKey ?? EncryptionKey::fromValue( null );
		$this->keys          = new SecretKeys( $this->registry, $this->store, $db, $this->encryptionKey, $detect );
		$this->vault         = new SecretVault( $this->registry, $this->store, $this->keys );
		$this->canary        = new SecretsCanary( $this->keys );
		$this->status        = new SecretsStatus( $this->keys, $this->vault, $this->canary );
	}

	/**
	 * Builds the next request, over the same database, with another encryption key.
	 *
	 * @since 0.1.0
	 *
	 * @param EncryptionKey $encryptionKey The encryption key the next request sees.
	 * @return self The harness.
	 */
	public function withKey( EncryptionKey $encryptionKey ): self {
		wp_cache_flush();

		return new self( $this->db, $encryptionKey );
	}

	/**
	 * Returns a new random encryption key, as wp-config.php would define it.
	 *
	 * @since 0.1.0
	 *
	 * @return EncryptionKey The key.
	 */
	public static function newEncryptionKey(): EncryptionKey {
		return EncryptionKey::fromValue( base64_encode( random_bytes( Cipher::KEY_BYTES ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding the constant is documented in.
	}

	/**
	 * Seals secrets and writes them, in one transaction, as the settings service does.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $secrets The plain texts, keyed by setting name.
	 */
	public function write( array $secrets ): void {
		$this->db->transaction(
			function () use ( $secrets ): void {
				$scalars  = array();
				$document = array();

				foreach ( $secrets as $name => $plaintext ) {
					$setting = $this->registry->setting( $name );
					$sealed  = $this->vault->seal( $setting, $plaintext );

					if ( SettingsFixtures::VAULT === $setting->group() ) {
						$document[ $name ] = $sealed;
					} else {
						$scalars[ $name ] = $sealed;
					}
				}

				$this->store->writeScalars( $scalars );

				if ( array() !== $document ) {
					$current = $this->store->documentAsStored( SettingsFixtures::VAULT );

					$this->store->replaceDocument( SettingsFixtures::VAULT, $current->version(), array_merge( $current->values(), $document ) );
				}
			}
		);
	}

	/**
	 * Reads an option as it is stored, past every cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 * @return string|null The stored text, or null when there is no row.
	 */
	public static function stored( string $option ): ?string {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $option ) );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Returns a sealed value with the first byte of its cipher text flipped.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sealed The sealed value.
	 * @return string The altered value, still in the shape of a sealed value.
	 */
	public static function flipped( string $sealed ): string {
		$cipher   = Cipher::detect();
		$parts    = explode( ':', $sealed );
		$bytes    = (string) $cipher->decode( $parts[3] );
		$bytes[0] = chr( ord( $bytes[0] ) ^ 0x01 );
		$parts[3] = $cipher->encode( $bytes );

		return implode( ':', $parts );
	}

	/**
	 * Replaces an option's stored text behind the store's back, and empties the object cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 * @param string $text   The text to store.
	 */
	public static function tamper( string $option, string $text ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, $text, $option ) );
		wp_cache_flush();
	}

	/**
	 * Deletes an option behind the store's back, and empties the object cache.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 */
	public static function removeOption( string $option ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $option ) );
		wp_cache_flush();
	}

	/**
	 * Returns the data keys document as stored: its values, keyed by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The values.
	 */
	public static function dataKeys(): array {
		$document = json_decode( (string) self::stored( self::DATA_KEYS_OPTION ), true );

		return is_array( $document ) && is_array( $document['values'] ?? null ) ? array_map( 'strval', $document['values'] ) : array();
	}

	/**
	 * Replaces values of the data keys document behind the store's back, keeping its version.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|null> $values The values to set, keyed by setting name; null removes one.
	 */
	public static function tamperDataKeys( array $values ): void {
		$document = json_decode( (string) self::stored( self::DATA_KEYS_OPTION ), true );

		foreach ( $values as $name => $value ) {
			if ( null === $value ) {
				unset( $document['values'][ $name ] );
			} else {
				$document['values'][ $name ] = $value;
			}
		}

		self::tamper( self::DATA_KEYS_OPTION, (string) wp_json_encode( $document ) );
	}

	/**
	 * Returns the key registry's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{key_id: string, state: string, retired_at: string|null}> The rows, oldest first.
	 */
	public static function registryRows(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT key_id, state, retired_at FROM %i ORDER BY created_at, key_id', $wpdb->prefix . 'seocart_' . SecretKeysTable::NAME ), ARRAY_A );

		return array_values(
			array_map(
				static fn( array $row ): array => array(
					'key_id'     => (string) $row['key_id'],
					'state'      => (string) $row['state'],
					'retired_at' => null === $row['retired_at'] ? null : (string) $row['retired_at'],
				),
				(array) $rows
			)
		);
	}

	/**
	 * Creates the key registry, replacing one an earlier run left.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The database wrapper.
	 */
	public static function createRegistry( Database $db ): void {
		self::dropRegistry();

		( new CreateSecretKeysMigration() )->up( new SchemaOperations( $db, new DdlGenerator(), new SchemaVerifier( $db ) ) );
	}

	/**
	 * Removes every option the fixture settings and the data keys wrote, and the key registry.
	 *
	 * @since 0.1.0
	 */
	public static function removeAll(): void {
		global $wpdb;

		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s OR option_name = %s', $wpdb->options, $wpdb->esc_like( SettingsFixtures::OPTION_PREFIX ) . '%', self::DATA_KEYS_OPTION ) );

		self::dropRegistry();
		wp_cache_flush();
	}

	/**
	 * Drops the key registry.
	 *
	 * @since 0.1.0
	 */
	private static function dropRegistry(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'seocart_' . SecretKeysTable::NAME ) );
	}
}
