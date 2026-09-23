<?php
/**
 * SecretVault: seals the plugin's secrets, opens them for the code that uses them, and re-seals them after a rotation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets;

use SEOCart\Platform\Settings\SecretSealer;
use SEOCart\Platform\Settings\Setting;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsRegistry;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Platform\Settings\SettingValues;
use SEOCart\Platform\Settings\Storage;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exceptions below name settings for the developer whose code misused them; a client gets a generic internal error, never these messages.

/**
 * Seals and opens the secret settings, counts them per data key, and moves them to the active key.
 *
 * This class owns one fact: which data key each stored secret is sealed with. Every secret setting
 * the registry declares is a record, named by Setting::recordName(), except the data keys
 * document's own settings, which SecretKeys keeps.
 *
 * - seal() checks the plain text against its setting and seals it with the active key of
 *   SecretKeys::ringForWrite(), which holds the keys until the caller's transaction ends; the store
 *   keeps only the sealed form.
 * - reveal() opens a stored secret for the code that uses it, such as a gateway. A secret that does
 *   not open is a typed error, never an empty string.
 * - counts() counts the records each key seals, from the header of each record's stored text as it
 *   is in the database, so a record whose document cannot be read as a whole is still counted
 *   under its key. damaged() names the records the store cannot read as stored.
 * - rekey() re-seals, with the active key, up to a batch of records sealed with another key. Each
 *   record is re-sealed in a transaction of its own that holds the keys (SecretKeys::underLock()):
 *   it reads the keys as they are now, reads the record, and replaces it by compare-and-swap before
 *   the keys can change. A record is therefore only ever re-sealed with the key that is active
 *   while it is written, whatever rotations, re-sealings and retirements run beside it, and a
 *   record changed meanwhile is left for the next batch rather than overwritten. After the batch
 *   the retiring key is retired through SecretKeys::retireIfUnused(), which counts again with every
 *   writer held off, and refuses while any record names the key or cannot be read as stored. It
 *   can stop at any point and be run again: every record is at every moment sealed with a key the
 *   site still has.
 *
 * What rotation does not give: protection against rollback. A sealed value binds the record it
 * belongs to, not the moment it was written, so an older sealed value of the same record — from a
 * backup, say — still opens once written back, as long as the key it names is active or retiring.
 * Retiring that key is what ends it.
 *
 * Nothing here returns, prints or logs a secret, except reveal() to its caller.
 *
 * @since 0.1.0
 */
final class SecretVault implements SecretSealer {

	/**
	 * The codes sealing can end in, which an operation that writes a secret declares.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecretsError>
	 */
	public const SEAL_ERRORS = array( SecretsError::NotInitialized, SecretsError::KeysDamaged, SecretsError::KeyUnavailable, SecretsError::NoCipher );

	/**
	 * The key under which counts() counts the records that name no key: stored text without a v1 header, or not text at all.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NO_KEY = 'none';

	/**
	 * What re-sealing one record came to: it was already sealed with the active key.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CURRENT = 'current';

	/**
	 * What re-sealing one record came to: it was re-sealed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RESEALED = 'resealed';

	/**
	 * What re-sealing one record came to: it changed after it was read, and was left.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CHANGED = 'changed';

	/**
	 * What re-sealing one record came to: it did not open.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNREADABLE = 'unreadable';

	/**
	 * What re-sealing one record came to: the store cannot read it as stored.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DAMAGED = 'damaged';

	/**
	 * What re-sealing one record came to: nothing is stored for it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ABSENT = 'absent';

	/**
	 * The settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	private SettingsRegistry $registry;

	/**
	 * The store that keeps the sealed secrets.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $store;

	/**
	 * The data keys.
	 *
	 * @since 0.1.0
	 *
	 * @var SecretKeys
	 */
	private SecretKeys $keys;

	/**
	 * Creates the vault. Reads nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry $registry The settings, the same the store and the keys use.
	 * @param SettingsStore    $store    The settings store of the current site.
	 * @param SecretKeys       $keys     The data keys.
	 */
	public function __construct( SettingsRegistry $registry, SettingsStore $store, SecretKeys $keys ) {
		$this->registry = $registry;
		$this->store    = $store;
		$this->keys     = $keys;
	}

	/**
	 * Checks the plain text of a secret against its setting, and seals it with the active key.
	 *
	 * Call it inside the transaction that writes the sealed value: the active key stays active
	 * until that transaction ends.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the setting is not a secret this vault seals, or the
	 *                                   text does not fit it.
	 * @phpstan-throws \InvalidArgumentException|\LogicException|CodedException
	 *
	 * @param Setting $setting   The secret setting.
	 * @param string  $plaintext The plain text.
	 * @return string The sealed form.
	 */
	public function seal( Setting $setting, #[\SensitiveParameter] string $plaintext ): string {
		if ( ! $this->holds( $setting ) ) {
			throw new \InvalidArgumentException( 'The setting ' . $setting->name() . ' is not a secret the vault seals.' );
		}

		$checked = SettingValues::check( $setting, $plaintext );

		return Envelope::seal( $this->keys->ringForWrite()->active(), (string) $checked, $setting->recordName(), $this->keys->cipher() );
	}

	/**
	 * Opens a stored secret, for the code that uses it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the name is not a secret this vault seals.
	 * @phpstan-throws \InvalidArgumentException|CodedException
	 *
	 * @param string $name The setting's name.
	 * @return string|null The secret, or null when it was never saved.
	 */
	public function reveal( string $name ): ?string {
		$setting = $this->registry->setting( $name );

		if ( ! $this->holds( $setting ) ) {
			throw new \InvalidArgumentException( 'The setting ' . $name . ' is not a secret the vault seals.' );
		}

		$sealed = $this->store->value( $name );

		if ( null === $sealed ) {
			return null;
		}

		$key_id = Envelope::keyId( (string) $sealed );

		if ( null === $key_id ) {
			CodedException::raise( SecretsError::Unreadable, array( 'record' => $setting->recordName() ) );
		}

		return Envelope::open( (string) $sealed, $this->keys->key( $key_id ), $setting->recordName(), $this->keys->cipher() );
	}

	/**
	 * Returns every record: each secret setting but the data keys document's.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Setting> The secret settings, in declaration order.
	 */
	public function records(): array {
		return array_values( array_filter( $this->registry->all(), fn( Setting $setting ): bool => $this->holds( $setting ) ) );
	}

	/**
	 * Counts the stored records each data key seals, from the database as it is now.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, int> The number of records, keyed by the key id their stored text's
	 *                            header names, or by NO_KEY for a record whose stored text names
	 *                            none. A key that seals no record is not listed.
	 */
	public function counts(): array {
		return self::tally( $this->census() );
	}

	/**
	 * Names the records the store cannot read as stored: their option, or a neighbour in their document, is damaged.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The record names.
	 */
	public function damaged(): array {
		return array_keys( array_filter( $this->census(), static fn( array $entry ): bool => ! $entry['readable'] ) );
	}

	/**
	 * Re-seals, with the active key, up to a batch of records sealed with another key.
	 *
	 * Then retires the retiring key if nothing stored names it any more, and nothing stored is unreadable.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the batch is smaller than 1.
	 * @phpstan-throws \InvalidArgumentException|\LogicException|CodedException
	 *
	 * @param int $batch How many records to re-seal at most.
	 * @return RekeyReport What was done, and what is left.
	 */
	public function rekey( int $batch ): RekeyReport {
		if ( $batch < 1 ) {
			throw new \InvalidArgumentException( 'A rekey batch holds at least one record.' );
		}

		$outcomes = array();

		foreach ( $this->records() as $setting ) {
			if ( count( array_intersect( $outcomes, array( self::RESEALED, self::CHANGED ) ) ) >= $batch ) {
				break;
			}

			$outcomes[ $setting->recordName() ] = (string) $this->keys->underLock( fn( KeyRing $ring ): string => $this->resealOne( $setting, $ring ) );
		}

		$retired = $this->keys->retireIfUnused( fn( string $key_id ): bool => $this->unused( $key_id ) );
		$active  = (string) $this->keys->underLock( static fn( KeyRing $ring ): string => $ring->active()->id() );
		$census  = $this->census();
		$pending = 0;

		foreach ( $census as $record => $entry ) {
			if ( $entry['readable'] && $active !== $entry['key_id'] && self::UNREADABLE !== ( $outcomes[ $record ] ?? null ) ) {
				++$pending;
			}
		}

		return new RekeyReport(
			$active,
			count( array_keys( $outcomes, self::RESEALED, true ) ),
			count( array_keys( $outcomes, self::CHANGED, true ) ),
			array_keys( $outcomes, self::UNREADABLE, true ),
			array_keys( array_filter( $census, static fn( array $entry ): bool => ! $entry['readable'] ) ),
			self::tally( $census ),
			$pending,
			$retired
		);
	}

	/**
	 * Re-seals one record with the active key of a ring the caller holds.
	 *
	 * @since 0.1.0
	 *
	 * @param Setting $setting The record's setting.
	 * @param KeyRing $ring    The keys, held until the caller's transaction ends.
	 * @return string What it came to: one of the outcome constants.
	 */
	private function resealOne( Setting $setting, KeyRing $ring ): string {
		$record = $setting->recordName();
		$text   = $this->store->storedText( $setting->name() );

		if ( null === $text ) {
			return self::ABSENT;
		}

		if ( false === $text || ! $this->readableAsStored( $setting ) ) {
			return self::DAMAGED;
		}

		$key_id = Envelope::keyId( $text );

		if ( $ring->active()->id() === $key_id ) {
			return self::CURRENT;
		}

		try {
			$plaintext = null === $key_id ? null : Envelope::open( $text, $ring->key( $key_id ), $record, $this->keys->cipher() );
		} catch ( CodedException ) {
			$plaintext = null;
		}

		if ( null === $plaintext ) {
			return self::UNREADABLE;
		}

		return $this->replace( $setting, $text, Envelope::seal( $ring->active(), $plaintext, $record, $this->keys->cipher() ) ) ? self::RESEALED : self::CHANGED;
	}

	/**
	 * Tells whether a key may be retired: no record names it, and none is unreadable as stored.
	 *
	 * SecretKeys::retireIfUnused() calls it while it holds the keys exclusively.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key_id The retiring key's id.
	 * @return bool True when nothing stored names the key or could name it.
	 */
	private function unused( string $key_id ): bool {
		foreach ( $this->census() as $entry ) {
			if ( ! $entry['readable'] || $key_id === $entry['key_id'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a setting is a record of this vault.
	 *
	 * @since 0.1.0
	 *
	 * @param Setting $setting The setting.
	 * @return bool True for a secret outside the data keys document.
	 */
	private function holds( Setting $setting ): bool {
		return $setting->isSecret() && SecretKeys::GROUP !== $setting->group();
	}

	/**
	 * Reads every record that holds something, from the database as it is now.
	 *
	 * Each record's key is taken from the header of its stored text alone, so a record whose
	 * document holds a broken neighbour is still counted under the key it names.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{key_id: string, readable: bool}> Each stored record, keyed by
	 *         record name: the key its header names, or NO_KEY; and whether the store can read it as
	 *         stored.
	 */
	private function census(): array {
		$census = array();

		foreach ( $this->records() as $setting ) {
			$text = $this->store->storedText( $setting->name() );

			if ( null === $text ) {
				continue;
			}

			$census[ $setting->recordName() ] = array(
				'key_id'   => ( false === $text ? null : Envelope::headerKeyId( $text ) ) ?? self::NO_KEY,
				'readable' => false !== $text && $this->readableAsStored( $setting ),
			);
		}

		return $census;
	}

	/**
	 * Counts the records of a census under the key each names.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{key_id: string, readable: bool}> $census The census.
	 * @return array<string, int> The number of records, keyed by key id or NO_KEY, sorted by key.
	 */
	private static function tally( array $census ): array {
		$counts = array();

		foreach ( $census as $entry ) {
			$counts[ $entry['key_id'] ] = ( $counts[ $entry['key_id'] ] ?? 0 ) + 1;
		}

		ksort( $counts );

		return $counts;
	}

	/**
	 * Tells whether the store can read a record as stored, its whole document included.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With the store's error when the read fails for another reason than a
	 *                        stored value it cannot hold.
	 *
	 * @param Setting $setting The record's setting.
	 * @return bool False when the store reports SettingsError::StoredValueInvalid.
	 */
	private function readableAsStored( Setting $setting ): bool {
		try {
			$this->store->valuesAsStored( array( $setting ) );
		} catch ( CodedException $failure ) {
			if ( SettingsError::StoredValueInvalid !== $failure->errorCode() ) {
				throw $failure;
			}

			return false;
		}

		return true;
	}

	/**
	 * Replaces a record's sealed value, only if it still holds the value read.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With the store's error when the document write fails for another
	 *                        reason than a version conflict.
	 *
	 * @param Setting $setting The record's setting.
	 * @param string  $read    The sealed value read.
	 * @param string  $fresh   The value sealed with the active key.
	 * @return bool True when it was replaced; false when the record changed after it was read.
	 */
	private function replace( Setting $setting, string $read, string $fresh ): bool {
		if ( Storage::Scalar === $setting->storage() ) {
			return $this->store->swapScalar( $setting->name(), $read, $fresh );
		}

		$document = $this->store->documentAsStored( $setting->group() );
		$values   = $document->values();

		if ( ( $values[ $setting->name() ] ?? null ) !== $read ) {
			return false;
		}

		$values[ $setting->name() ] = $fresh;

		try {
			$this->store->replaceDocument( $setting->group(), $document->version(), $values );
		} catch ( CodedException $failure ) {
			if ( SettingsError::VersionConflict !== $failure->errorCode() ) {
				throw $failure;
			}

			return false;
		}

		return true;
	}
}
