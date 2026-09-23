<?php
/**
 * SecretKeys: the site's data keys, where they are kept, and how a new one replaces the old
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsDocument;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the data keys, creates the first one, rotates to a new one and retires the old one.
 *
 * This class owns one fact: which data keys the site has and which one seals new secrets. The
 * keys live in one internal settings document, `seocart_data_keys`, secret and never autoloaded,
 * written by compare-and-swap:
 *
 * - `active_data_key`, the key new secrets are sealed with;
 * - `retiring_data_key`, the previous key, while records sealed with it wait to be re-sealed;
 * - `secrets_canary`, a known text sealed with the active key, which proves the keys still open.
 *
 * Each key is stored in the form KeyMaterial writes: wrapped by SEOCART_ENCRYPTION_KEY when
 * wp-config.php defines it, as it is otherwise. The `secret_keys` table records every key's id,
 * state and times, never its material; the document and the table change in one transaction.
 *
 * There are two slots, so a rotation waits for the previous one: rotate() refuses while a key is
 * still retiring. The records it seals are re-sealed by SecretVault::rekey(), which retires it once
 * none is left. A key is retired only when no record names it.
 *
 * The locks that keep a secret from outliving its key, all on the data keys row:
 *
 * - every write of a secret, and every re-seal, reads the keys with ringForWrite() or underLock(),
 *   under a shared lock it holds until its transaction ends, and seals only with the active key of
 *   that read;
 * - rotate() and retireIfUnused() write the row, so each waits for all of them; retireIfUnused()
 *   also takes the row's exclusive lock before it counts, so its count sees every one of them
 *   committed, and none can start before it ends.
 *
 * So a secret is sealed only with a key that stays active until the secret is committed, and a key
 * is retired only when, with every writer held off, no stored secret names it.
 *
 * initialize() creates the first key and the canary; the plugin's activation calls it, and
 * calling it again changes nothing. It creates a key only on a site that never had one: when the
 * data keys option exists but holds no usable active key, or is gone while the registry lists
 * keys, it refuses with SecretsError::KeysDamaged and leaves everything as it is, so the option
 * can still be restored. Reading a key never writes.
 *
 * @since 0.1.0
 */
final class SecretKeys {

	/**
	 * The settings group of the data keys document.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP = 'data_keys';

	/**
	 * What the data keys document holds, as the data registry lists it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PURPOSE = 'The data keys that seal the plugin\'s secrets, wrapped by SEOCART_ENCRYPTION_KEY when wp-config.php defines it, and the canary that proves they still open.';

	/**
	 * The setting that holds the active key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ACTIVE = 'active_data_key';

	/**
	 * The setting that holds the retiring key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETIRING = 'retiring_data_key';

	/**
	 * The setting that holds the canary.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CANARY = 'secrets_canary';

	/**
	 * The text the canary seals.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CANARY_TEXT = 'SEOCart secrets canary, version 1';

	/**
	 * The statement that records a new key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const INSERT_KEY = 'INSERT INTO %i ( key_id, state, algorithm, created_at ) VALUES ( %s, %s, %s, UTC_TIMESTAMP(6) )';

	/**
	 * The statement that moves a key from one state to the next, and records when it was retired.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CHANGE_STATE = 'UPDATE %i SET state = %s, retired_at = IF( %s = %s, UTC_TIMESTAMP(6), retired_at ) WHERE key_id = %s AND state = %s';

	/**
	 * The query that lists the keys.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LIST_KEYS = 'SELECT key_id, state, algorithm, created_at, retired_at FROM %i ORDER BY created_at, key_id';

	/**
	 * The query that tells whether the registry lists any key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ANY_KEY = 'SELECT 1 FROM %i LIMIT 1';

	/**
	 * The settings, which hold the data keys document.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	private SettingsRegistry $registry;

	/**
	 * The store that keeps the document.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * The database wrapper, for the key registry and the transactions.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * The key that wraps the data keys, or its absence.
	 *
	 * @since 0.1.0
	 *
	 * @var EncryptionKey
	 */
	private EncryptionKey $encryptionKey;

	/**
	 * The cipher, once detected.
	 *
	 * @since 0.1.0
	 *
	 * @var Cipher|null
	 */
	private ?Cipher $cipher = null;

	/**
	 * Detects the cipher.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Cipher
	 */
	private \Closure $detect;

	/**
	 * The keys read so far in this request, keyed by their stored material.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, DataKey>
	 */
	private array $opened = array();

	/**
	 * Creates the keys service. Reads nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry $registry      The settings, which must hold settings().
	 * @param SettingsStore    $store         The settings store of the current site.
	 * @param Database         $database      The database wrapper.
	 * @param EncryptionKey    $encryptionKey The key that wraps the data keys, from
	 *                                        EncryptionKey::fromEnvironment().
	 * @param \Closure|null    $detect        Optional. Returns the cipher, or raises
	 *                                        SecretsError::NoCipher; called once, on first use.
	 *                                        Default Cipher::detect().
	 *
	 * @phpstan-param (\Closure(): Cipher)|null $detect
	 */
	public function __construct( SettingsRegistry $registry, SettingsStore $store, Database $database, EncryptionKey $encryptionKey, ?\Closure $detect = null ) {
		$this->registry      = $registry;
		$this->store         = $store;
		$this->database      = $database;
		$this->encryptionKey = $encryptionKey;
		$this->detect        = $detect ?? Cipher::detect( ... );
	}

	/**
	 * Declares the data keys document: three internal secrets.
	 *
	 * The labels are plain text: no screen shows an internal setting, so they are not translated.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The settings.
	 */
	public static function settings(): array {
		$slots    = array(
			self::ACTIVE   => array( 'The data key new secrets are sealed with, in its stored form.', 'k1:0123456789abcdef:raw:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' ),
			self::RETIRING => array( 'The previous data key, in its stored form, while records sealed with it wait to be sealed again.', 'k1:fedcba9876543210:raw:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' ),
			self::CANARY   => array( 'A known text sealed with the active data key, which proves the keys still open.', 'v1:0123456789abcdef:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA:AAAA' ),
		);
		$settings = array();

		foreach ( $slots as $name => $slot ) {
			$settings[] = Setting::inDocument(
				group: self::GROUP,
				field: new FieldSpec(
					name: $name,
					type: FieldType::String,
					description: $slot[0],
					label: static fn(): string => 'Data keys',
					example: $slot[1],
					privacy: Privacy::Secret
				),
				exposed: false
			);
		}

		return $settings;
	}

	/**
	 * Returns the cipher, detecting it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::NoCipher when this PHP provides none.
	 *
	 * @return Cipher The cipher.
	 */
	public function cipher(): Cipher {
		$this->cipher ??= ( $this->detect )();

		return $this->cipher;
	}

	/**
	 * Returns the key that wraps the data keys.
	 *
	 * @since 0.1.0
	 *
	 * @return EncryptionKey The encryption key, or its absence.
	 */
	public function encryptionKey(): EncryptionKey {
		return $this->encryptionKey;
	}

	/**
	 * Returns the key new secrets are sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::NotInitialized when there is none yet,
	 *                        SecretsError::KeysDamaged when the data keys option holds no usable
	 *                        active key, or SecretsError::KeyUnavailable when it cannot be unwrapped.
	 *
	 * @return DataKey The active key.
	 */
	public function active(): DataKey {
		return $this->open( self::activeMaterial( $this->readDocument( fn(): SettingsDocument => $this->store->document( self::GROUP ) ) ) );
	}

	/**
	 * Returns the keys as they are now, and keeps them so until the caller's transaction ends.
	 *
	 * The data keys document is read from the database under a shared lock, so a rotation or a
	 * retirement, which write the document, waits until the caller commits. A secret sealed with the
	 * ring's active key is therefore written while that key is still active, and the re-sealing that
	 * follows any later rotation finds it.
	 *
	 * With no key yet it raises SecretsError::NotInitialized; with damaged or missing keys on a site
	 * that has had keys, SecretsError::KeysDamaged; with an active key that cannot be unwrapped,
	 * SecretsError::KeyUnavailable.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When no transaction is open: read the keys inside the transaction that writes.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @return KeyRing The active key, and the retiring key when there is one.
	 */
	public function ringForWrite(): KeyRing {
		if ( 0 === $this->database->depth() ) {
			throw new \LogicException( 'Read the keys for a write inside the transaction that writes, so no rotation or retirement can come between.' );
		}

		$document = $this->readDocument( fn(): SettingsDocument => $this->store->documentForShare( self::GROUP ) );

		if ( 0 === $document->version() && $this->hasHistory() ) {
			CodedException::raise( SecretsError::KeysDamaged );
		}

		$retiring = (string) ( $document->values()[ self::RETIRING ] ?? '' );

		return new KeyRing( $this->open( self::activeMaterial( $document ) ), KeyMaterial::keyId( $retiring ), fn(): DataKey => $this->open( $retiring ) );
	}

	/**
	 * Runs work in a transaction of its own, holding the keys as ringForWrite() does.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With what ringForWrite() raises, or what the work raises.
	 *
	 * @param callable $work Receives the KeyRing; what it returns is returned.
	 * @return mixed What the work returned.
	 *
	 * @phpstan-param callable(KeyRing): mixed $work
	 */
	public function underLock( callable $work ): mixed {
		return $this->database->transaction( fn(): mixed => $work( $this->ringForWrite() ) );
	}

	/**
	 * Returns the key a sealed value names, if the site still has it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::UnknownKey when neither the active nor the retiring
	 *                        key has the id, or SecretsError::KeyUnavailable when it cannot be unwrapped.
	 *
	 * @param string $keyId The key id.
	 * @return DataKey The key.
	 */
	public function key( string $keyId ): DataKey {
		foreach ( array( self::ACTIVE, self::RETIRING ) as $slot ) {
			$material = (string) ( $this->slots()[ $slot ] ?? '' );

			if ( '' !== $material && KeyMaterial::keyId( $material ) === $keyId ) {
				return $this->open( $material );
			}
		}

		CodedException::raise( SecretsError::UnknownKey, array( 'key_id' => $keyId ) );
	}

	/**
	 * Returns the id of the active key.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when there is no key yet.
	 */
	public function activeKeyId(): ?string {
		return KeyMaterial::keyId( (string) ( $this->slots()[ self::ACTIVE ] ?? '' ) );
	}

	/**
	 * Returns the id of the retiring key.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when no key is retiring.
	 */
	public function retiringKeyId(): ?string {
		return KeyMaterial::keyId( (string) ( $this->slots()[ self::RETIRING ] ?? '' ) );
	}

	/**
	 * Tells whether each stored key is wrapped by the encryption key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, bool> Whether each key is wrapped, keyed by key id.
	 */
	public function wrapped(): array {
		$wrapped = array();

		foreach ( array( self::ACTIVE, self::RETIRING ) as $slot ) {
			$material = (string) ( $this->slots()[ $slot ] ?? '' );
			$keyId    = KeyMaterial::keyId( $material );

			if ( null !== $keyId ) {
				$wrapped[ $keyId ] = KeyMaterial::isWrapped( $material );
			}
		}

		return $wrapped;
	}

	/**
	 * Returns the sealed canary.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The sealed canary, or null when there is none yet.
	 */
	public function sealedCanary(): ?string {
		$canary = $this->slots()[ self::CANARY ] ?? null;

		return null === $canary ? null : (string) $canary;
	}

	/**
	 * Returns the record name the canary is sealed for.
	 *
	 * @since 0.1.0
	 *
	 * @return string The record name.
	 */
	public function canaryRecord(): string {
		return $this->registry->setting( self::CANARY )->recordName();
	}

	/**
	 * Creates the first data key and the canary on a site that never had one. Calling it again changes nothing.
	 *
	 * A site that has had keys is never given a new one here: a data keys option that exists but
	 * holds no usable active key, or that is gone while the registry lists keys, is refused with
	 * SecretsError::KeysDamaged and left as it is.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::KeysDamaged; SecretsError::EncryptionKeyInvalid when
	 *                        the encryption key is defined but unusable; or SecretsError::NoCipher.
	 *
	 * @return string The id of the active key.
	 */
	public function initialize(): string {
		$document = $this->readDocument( fn(): SettingsDocument => $this->store->documentAsStored( self::GROUP ) );

		if ( 0 !== $document->version() ) {
			$current = KeyMaterial::keyId( (string) ( $document->values()[ self::ACTIVE ] ?? '' ) );

			if ( null === $current ) {
				CodedException::raise( SecretsError::KeysDamaged );
			}

			return $current;
		}

		if ( $this->hasHistory() ) {
			CodedException::raise( SecretsError::KeysDamaged );
		}

		$key      = DataKey::generate( $this->cipher() );
		$material = KeyMaterial::write( $key, $this->encryptionKey, $this->cipher() );

		try {
			$this->database->transaction(
				function () use ( $key, $material ): void {
					$this->store->replaceDocument(
						self::GROUP,
						0,
						array(
							self::ACTIVE => $material,
							self::CANARY => Envelope::seal( $key, self::CANARY_TEXT, $this->canaryRecord(), $this->cipher() ),
						)
					);
					$this->database->execute( self::INSERT_KEY, $this->table(), $key->id(), SecretKeysTable::ACTIVE, Cipher::ALGORITHM );
				}
			);
		} catch ( CodedException $failure ) {
			$winner = KeyMaterial::keyId( (string) ( $this->store->documentAsStored( self::GROUP )->values()[ self::ACTIVE ] ?? '' ) );

			// Another activation created the first key between the read and the write: that key stands.
			if ( SettingsError::VersionConflict !== $failure->errorCode() || null === $winner ) {
				throw $failure;
			}

			return $winner;
		}

		return $key->id();
	}

	/**
	 * Creates a new data key, makes it the active one and moves the active one to retiring.
	 *
	 * Records sealed with the old key still open; SecretVault::rekey() re-seals them. The canary is
	 * sealed again with the new key at once, so it always proves the key new secrets are sealed with.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::RotationPending while a key is still retiring;
	 *                        SecretsError::NotInitialized when there is no key yet;
	 *                        SecretsError::KeysDamaged when the stored keys are damaged;
	 *                        SecretsError::KeyUnavailable when the active key cannot be unwrapped;
	 *                        SecretsError::EncryptionKeyInvalid; or SettingsError::VersionConflict
	 *                        when another rotation won the race.
	 *
	 * @return string The id of the new active key.
	 */
	public function rotate(): string {
		$document = $this->readDocument( fn(): SettingsDocument => $this->store->documentAsStored( self::GROUP ) );
		$values   = $document->values();
		$current  = self::activeMaterial( $document );

		if ( isset( $values[ self::RETIRING ] ) ) {
			CodedException::raise( SecretsError::RotationPending, array( 'key_id' => KeyMaterial::keyId( (string) $values[ self::RETIRING ] ) ?? 'unknown' ) );
		}

		$old      = $this->open( $current );
		$key      = DataKey::generate( $this->cipher() );
		$material = KeyMaterial::write( $key, $this->encryptionKey, $this->cipher() );

		$this->database->transaction(
			function () use ( $document, $current, $old, $key, $material ): void {
				$this->store->replaceDocument(
					self::GROUP,
					$document->version(),
					array(
						self::ACTIVE   => $material,
						self::RETIRING => $current,
						self::CANARY   => Envelope::seal( $key, self::CANARY_TEXT, $this->canaryRecord(), $this->cipher() ),
					)
				);
				$this->changeState( $old->id(), SecretKeysTable::ACTIVE, SecretKeysTable::RETIRING );
				$this->database->execute( self::INSERT_KEY, $this->table(), $key->id(), SecretKeysTable::ACTIVE, Cipher::ALGORITHM );
			}
		);

		return $key->id();
	}

	/**
	 * Retires the retiring key if no stored secret names it any more: removes its material and records when.
	 *
	 * It runs in a transaction of its own whose first statement takes the exclusive lock on the data
	 * keys row. It therefore waits for every secret being written or re-sealed, none can start until
	 * it ends, and the caller's check, which runs under that lock, reads everything committed before
	 * it. SecretVault::rekey() is the caller; its check refuses while any record names the key or
	 * cannot be read as stored.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is already open, whose reads could predate the lock.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param callable $unused Receives the retiring key's id and answers true only when nothing stored
	 *                         names it or could name it.
	 * @return string|null The id of the key retired, or null when none was.
	 *
	 * @phpstan-param callable(string): bool $unused
	 */
	public function retireIfUnused( callable $unused ): ?string {
		if ( 0 !== $this->database->depth() ) {
			throw new \LogicException( 'A data key is retired in a transaction of its own, so everything it counts is read after it holds the keys.' );
		}

		return $this->database->transaction(
			function () use ( $unused ): ?string {
				$document = $this->readDocument( fn(): SettingsDocument => $this->store->documentForUpdate( self::GROUP ) );
				$values   = $document->values();
				$key_id   = KeyMaterial::keyId( (string) ( $values[ self::RETIRING ] ?? '' ) );

				if ( null === $key_id || true !== $unused( $key_id ) ) {
					return null;
				}

				unset( $values[ self::RETIRING ] );

				$this->store->replaceDocument( self::GROUP, $document->version(), $values );
				$this->changeState( $key_id, SecretKeysTable::RETIRING, SecretKeysTable::RETIRED );

				return $key_id;
			}
		);
	}

	/**
	 * Tells whether the registry lists any key: whether the site has ever had one.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it lists at least one key.
	 */
	public function hasHistory(): bool {
		return null !== $this->database->fetchValue( self::ANY_KEY, $this->table() );
	}

	/**
	 * Lists every key the site has had, from the registry.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{key_id: string, state: string, algorithm: string, created_at: string, retired_at: string|null}> The keys, oldest first.
	 */
	public function registered(): array {
		$keys = array();

		foreach ( $this->database->fetchAll( self::LIST_KEYS, $this->table() ) as $row ) {
			$keys[] = array(
				'key_id'     => (string) $row['key_id'],
				'state'      => (string) $row['state'],
				'algorithm'  => (string) $row['algorithm'],
				'created_at' => (string) $row['created_at'],
				'retired_at' => null === $row['retired_at'] ? null : (string) $row['retired_at'],
			);
		}

		return $keys;
	}

	/**
	 * Returns the data keys document's values.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::KeysDamaged when the option is not a document.
	 *
	 * @return array<string, int|string> The stored material and canary, keyed by setting name.
	 */
	private function slots(): array {
		return $this->readDocument( fn(): SettingsDocument => $this->store->document( self::GROUP ) )->values();
	}

	/**
	 * Reads the data keys document, reporting one the store cannot read as damaged keys.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::KeysDamaged when the option does not hold a document,
	 *                        or with the read's own failure.
	 *
	 * @param \Closure $read Reads the document.
	 * @return SettingsDocument The document.
	 *
	 * @phpstan-param \Closure(): SettingsDocument $read
	 */
	private function readDocument( \Closure $read ): SettingsDocument {
		try {
			return $read();
		} catch ( CodedException $failure ) {
			if ( SettingsError::StoredValueInvalid !== $failure->errorCode() ) {
				throw $failure;
			}

			CodedException::raise( SecretsError::KeysDamaged );
		}
	}

	/**
	 * Returns the active key's material from a read of the data keys document.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::NotInitialized when the document was never written,
	 *                        or SecretsError::KeysDamaged when it was and holds no active key.
	 *
	 * @param SettingsDocument $document The document.
	 * @return string The material.
	 */
	private static function activeMaterial( SettingsDocument $document ): string {
		$material = $document->values()[ self::ACTIVE ] ?? null;

		if ( null === $material ) {
			CodedException::raise( 0 === $document->version() ? SecretsError::NotInitialized : SecretsError::KeysDamaged );
		}

		return (string) $material;
	}

	/**
	 * Reads a key from its material, once per request.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SecretsError::KeyUnavailable when it cannot be read.
	 *
	 * @param string $material The material.
	 * @return DataKey The key.
	 */
	private function open( string $material ): DataKey {
		$this->opened[ $material ] ??= KeyMaterial::read( $material, $this->encryptionKey, $this->cipher() );

		return $this->opened[ $material ];
	}

	/**
	 * Moves a key from one state to the next in the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param string $keyId The key id.
	 * @param string $from  The state it is in.
	 * @param string $to    The state it moves to; retiring it records the time.
	 */
	private function changeState( string $keyId, string $from, string $to ): void {
		$this->database->execute( self::CHANGE_STATE, $this->table(), $to, $to, SecretKeysTable::RETIRED, $keyId, $from );
	}

	/**
	 * Returns the key registry's table name on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return string The table name.
	 */
	private function table(): string {
		return $this->database->table( SecretKeysTable::NAME );
	}
}
