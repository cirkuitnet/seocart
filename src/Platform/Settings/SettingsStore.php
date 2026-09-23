<?php
/**
 * SettingsStore: reads and writes the plugin's settings in the options table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Settings;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exceptions below name settings, groups and options for the developer; a client gets a coded error or a generic internal error, never these messages.

/**
 * The one place the plugin writes an option, and the reader of every setting.
 *
 * This class owns one fact: how a declared setting is kept in the options table. It stores only
 * what the registry declares, checks every value with SettingValues before it is written, and
 * writes every option with autoload off. The coding standard lets no other plugin code add,
 * change or delete an option, so these rules cannot be bypassed.
 *
 * - Reads prime every option of the groups they touch with one wp_prime_option_caches() call, so
 *   reading a group costs one query however many settings it holds, and none once cached. A
 *   setting that was never saved reads as its default. A stored value its setting cannot hold is
 *   reported with SettingsError::StoredValueInvalid rather than used.
 * - While this store has written an option inside a transaction that is still open, every read it
 *   makes goes straight to the database and caches nothing, not even the lists core keeps: the
 *   row is not committed yet, and a persistent object cache would serve it to other requests.
 *   When the outermost transaction ends, reads cache as usual again.
 * - A scalar is written with one statement through the database wrapper, an insert that updates
 *   the row when it exists, with autoload off either way: each scalar is its own row, so two
 *   writers of two settings never touch the same row, and a row something else created
 *   autoloaded is switched off even when its value does not change. update_option() is not used:
 *   it would put the new value into the object cache before the transaction around it commits.
 *   The value is serialized as core would, so get_option() reads back exactly what was written.
 * - A document is written whole, by compare-and-swap: the writer names the version it read, the
 *   store reads the row, and one conditional statement replaces the row, and sets autoload off,
 *   only if it still holds exactly what was read. Every write stores the next version, so a
 *   writer that read an older version changes nothing and gets SettingsError::VersionConflict
 *   (HTTP 409). A document that does not exist yet is created by an INSERT, which the option
 *   name's unique key lets only one writer win. The row is written through the database wrapper.
 * - After every write attempt the option's own entry in the object cache is deleted, and so are
 *   the `alloptions` and `notoptions` lists core keeps option names in — deleted, never edited and
 *   stored back: two writers editing a shared list at once would each store the other's stale
 *   copy. The next reader rebuilds them. Inside a transaction all three are deleted again after
 *   the commit, and on a rollback, so no reader is served the value it replaced.
 *
 * Every write is checked in full before anything is written, so a write refused for one value
 * writes none of the others.
 *
 * @since 0.1.0
 */
final class SettingsStore {

	/**
	 * The statement that replaces a document only if its row still holds exactly what was read.
	 *
	 * The comparison is on bytes, not under the column's collation, which would equate text that
	 * differs only in case. It also sets autoload off, whoever created the row. Placeholders: the
	 * options table, the new value, the option name and the value read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMPARE_AND_SWAP = "UPDATE %i SET option_value = %s, autoload = 'off' WHERE option_name = %s AND option_value = CAST( %s AS BINARY )";

	/**
	 * The statement that creates a document; the unique option name makes a second creator fail.
	 *
	 * Placeholders: the options table, the option name and the value.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CREATE_DOCUMENT = "INSERT INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )";

	/**
	 * The statement that writes a scalar: inserts the row, or updates it, with autoload off either way.
	 *
	 * Placeholders: the options table, the option name, the value, and the value again.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WRITE_SCALAR = "INSERT INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' ) ON DUPLICATE KEY UPDATE option_value = %s, autoload = 'off'";

	/**
	 * The statement that reads the row a document write compares against.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const READ_ROW = 'SELECT option_value FROM %i WHERE option_name = %s';

	/**
	 * The object-cache group core keeps options in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'options';

	/**
	 * The lists in the options cache group that core keeps option names in.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const CACHED_LISTS = array( 'alloptions', 'notoptions' );

	/**
	 * The settings.
	 *
	 * @since 0.1.0
	 *
	 * @var SettingsRegistry
	 */
	private SettingsRegistry $registry;

	/**
	 * The database wrapper, through which every option is written.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $database;

	/**
	 * The options written inside the open transaction, as keys; emptied when the outermost transaction ends.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $written = array();

	/**
	 * Creates the store. Reads and writes nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param SettingsRegistry $registry The settings.
	 * @param Database         $database The database wrapper.
	 */
	public function __construct( SettingsRegistry $registry, Database $database ) {
		$this->registry = $registry;
		$this->database = $database;
	}

	/**
	 * Reads settings, priming every option of their groups with one query.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when an option holds a value its
	 *                        setting cannot hold.
	 *
	 * @param Setting[] $settings Settings of the registry.
	 * @return array<string, int|string|null> Each value, keyed by setting name: the stored one or
	 *                                        the default, null for a setting without a default that
	 *                                        was never saved.
	 *
	 * @phpstan-param list<Setting> $settings
	 */
	public function values( array $settings ): array {
		$groups = array();

		foreach ( $settings as $setting ) {
			$groups[ $setting->group() ] = true;
		}

		$stored    = $this->storedValues( array_keys( $groups ) );
		$documents = array();
		$values    = array();

		foreach ( $settings as $setting ) {
			$default = $setting->field()->defaultValue();
			$option  = $setting->optionName();

			if ( Storage::Document === $setting->storage() ) {
				$documents[ $setting->group() ] ??= $this->decode( $setting->group(), $stored[ $option ] );
				$values[ $setting->name() ]       = $documents[ $setting->group() ]->values()[ $setting->name() ] ?? $default;

				continue;
			}

			$values[ $setting->name() ] = false === $stored[ $option ] ? $default : SettingValues::fromStorage( $setting, $stored[ $option ] );
		}

		return $values;
	}

	/**
	 * Reads one setting, priming every option of its group.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when its option holds a value
	 *                        the setting cannot hold.
	 *
	 * @param string $name The setting's name.
	 * @return int|string|null The stored value, or the default; null for a setting without a
	 *                         default that was never saved.
	 */
	public function value( string $name ): int|string|null {
		return $this->values( array( $this->registry->setting( $name ) ) )[ $name ];
	}

	/**
	 * Writes independent settings, each to its own option. Checks every value before writing any.
	 *
	 * A value a setting's own check refuses is refused with the check's code, before anything is
	 * written, and a failed statement with the database layer's typed exception.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a name is not a scalar setting, or a value does not
	 *                                   fit its setting: a programming error.
	 * @phpstan-throws \InvalidArgumentException|CodedException
	 *
	 * @param array<string, mixed> $values The values, keyed by setting name.
	 */
	public function writeScalars( array $values ): void {
		$checked = array();

		foreach ( $values as $name => $value ) {
			$setting = $this->registry->setting( (string) $name );

			if ( Storage::Scalar !== $setting->storage() ) {
				throw new \InvalidArgumentException( 'The setting ' . $setting->name() . ' is stored in a document; write it with replaceDocument().' );
			}

			$checked[ $setting->optionName() ] = SettingValues::toOption( SettingValues::check( $setting, $value ) );
		}

		$table = $this->database->prefix() . 'options';

		foreach ( $checked as $option => $text ) {
			$stored = maybe_serialize( $text );

			$this->database->execute( self::WRITE_SCALAR, $table, $option, $stored, $stored );

			$this->forget( $option );
			$this->settle( $option );
		}
	}

	/**
	 * Reads a group's document, priming its option.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the group is not a document: a programming error.
	 * @throws CodedException            With SettingsError::StoredValueInvalid when the option does
	 *                                   not hold a document the group can hold.
	 *
	 * @param string $group The group.
	 * @return SettingsDocument The document: version 0 and no values when it was never written.
	 */
	public function document( string $group ): SettingsDocument {
		$option = $this->documentOption( $group );

		return $this->decode( $group, $this->storedValues( array( $group ) )[ $option ] );
	}

	/**
	 * Reads a group's document from the database, past the object cache, and caches nothing.
	 *
	 * For a writer that must know what is committed now, not what a cache remembers.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the group is not a document: a programming error.
	 * @throws CodedException            With SettingsError::StoredValueInvalid when the option does
	 *                                   not hold a document the group can hold.
	 *
	 * @param string $group The group.
	 * @return SettingsDocument The document: version 0 and no values when it was never written.
	 */
	public function documentAsStored( string $group ): SettingsDocument {
		$row = $this->readRow( $this->documentOption( $group ) );

		return $this->decode( $group, null === $row ? false : maybe_unserialize( $row ) );
	}

	/**
	 * Replaces a group's document, if it is still the version the writer read.
	 *
	 * The values are the whole document: a setting of the group not among them is removed from
	 * it and reads as its default. Every value is checked before anything is written.
	 *
	 * A writer that lost the race gets SettingsError::VersionConflict and changes nothing: the
	 * document is no longer the version it read. An unreadable stored document is reported with
	 * SettingsError::StoredValueInvalid, and a value a setting's own check refuses with its code.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the group is not a document, a name is not a setting of
	 *                                   it, or a value does not fit its setting.
	 * @throws \RuntimeException         When the document cannot be encoded as JSON.
	 *
	 * @param string               $group        The group.
	 * @param int                  $read_version The version the writer read, 0 for a document
	 *                                           never written.
	 * @param array<string, mixed> $values       The document's values, keyed by setting name.
	 * @return SettingsDocument The document written.
	 */
	public function replaceDocument( string $group, int $read_version, array $values ): SettingsDocument {
		$option   = $this->documentOption( $group );
		$settings = $this->registry->group( $group );
		$checked  = array();

		foreach ( $settings as $setting ) {
			if ( array_key_exists( $setting->name(), $values ) ) {
				$checked[ $setting->name() ] = SettingValues::check( $setting, $values[ $setting->name() ] );
			}
		}

		$unknown = array_diff_key( $values, $checked );

		if ( array() !== $unknown ) {
			throw new \InvalidArgumentException( 'The document ' . $group . ' has no setting named ' . implode( ', ', array_keys( $unknown ) ) . '.' );
		}

		$table   = $this->database->prefix() . 'options';
		$current = $this->readRow( $option );

		if ( $this->decode( $group, null === $current ? false : $current )->version() !== $read_version ) {
			$this->conflict( $group, $option );
		}

		$written = new SettingsDocument( $read_version + 1, $checked );
		$json    = wp_json_encode(
			array(
				'version' => $written->version(),
				'values'  => (object) $written->values(),
			)
		);

		if ( false === $json ) {
			throw new \RuntimeException( 'The document ' . $group . ' could not be encoded as JSON.' );
		}

		if ( null === $current ) {
			try {
				$this->database->execute( self::CREATE_DOCUMENT, $table, $option, $json );
			} catch ( DuplicateKey ) {
				$this->conflict( $group, $option );
			}
		} elseif ( 1 !== $this->database->execute( self::COMPARE_AND_SWAP, $table, $json, $option, $current ) ) {
			$this->conflict( $group, $option );
		}

		$this->forget( $option );
		$this->settle( $option );

		return $written;
	}

	/**
	 * Returns what every option of the given groups holds, as get_option() would.
	 *
	 * Normally the options are primed with one query and read through the object cache. While
	 * this store has writes pending in an open transaction, each option is read from the database
	 * instead and nothing is cached: priming and get_option() may load `alloptions`, and when a site
	 * has no autoloaded option at all, core's fallback caches every option in it, the uncommitted
	 * ones included.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $groups The groups.
	 * @return array<string, mixed> What each option holds, false when it does not exist, keyed by
	 *                              option name.
	 *
	 * @phpstan-param list<string> $groups
	 */
	private function storedValues( array $groups ): array {
		$options = array();

		foreach ( $groups as $group ) {
			foreach ( $this->registry->group( $group ) as $setting ) {
				$options[ $setting->optionName() ] = false;
			}
		}

		if ( array() !== $this->written && 0 !== $this->database->depth() ) {
			foreach ( array_keys( $options ) as $option ) {
				$row = $this->readRow( $option );

				$options[ $option ] = null === $row ? false : maybe_unserialize( $row );
			}

			return $options;
		}

		wp_prime_option_caches( array_keys( $options ) );

		foreach ( array_keys( $options ) as $option ) {
			$options[ $option ] = get_option( $option );
		}

		return $options;
	}

	/**
	 * Reads an option's row from the database, past the object cache. Caches nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 * @return string|null The stored text, or null when there is no row.
	 */
	private function readRow( string $option ): ?string {
		$row = $this->database->fetchValue( self::READ_ROW, $this->database->prefix() . 'options', $option );

		return null === $row ? null : (string) $row;
	}

	/**
	 * Empties the list of pending writes when the outermost transaction ends, however it ends.
	 *
	 * The callback runs after a commit, which is always the outermost level's, and after a rollback
	 * of the level it was registered on. After the outermost rollback nothing is open any more, and
	 * the list is emptied. After a nested rollback the level around it is still open and may have
	 * written the same options, so every entry is kept, and the callback registers itself again on
	 * that level.
	 *
	 * @since 0.1.0
	 */
	private function forgetPendingWhenTheTransactionEnds(): void {
		$ended = function (): void {
			if ( 0 === $this->database->depth() ) {
				$this->written = array();

				return;
			}

			$this->forgetPendingWhenTheTransactionEnds();
		};

		$this->database->afterCommit( $ended );
		$this->database->afterRollback( $ended );
	}

	/**
	 * Returns the option of a group's document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the group is unknown or not stored as a document.
	 *
	 * @param string $group The group.
	 * @return string The option name.
	 */
	private function documentOption( string $group ): string {
		$first = $this->registry->group( $group )[0];

		if ( Storage::Document !== $first->storage() ) {
			throw new \InvalidArgumentException( 'The group ' . $group . ' is not stored as a document.' );
		}

		return $first->optionName();
	}

	/**
	 * Reads a stored document.
	 *
	 * A value for a name the group does not declare is left out: the next write drops it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::StoredValueInvalid when the stored value is not a
	 *                        document of the group.
	 *
	 * @param string $group  The group.
	 * @param mixed  $stored What the option holds, or false when it does not exist.
	 * @return SettingsDocument The document.
	 */
	private function decode( string $group, mixed $stored ): SettingsDocument {
		if ( false === $stored ) {
			return new SettingsDocument( 0, array() );
		}

		$settings = $this->registry->group( $group );
		$data     = is_string( $stored ) ? json_decode( $stored, true, 3 ) : null;

		if ( ! is_array( $data ) || array( 'version', 'values' ) !== array_keys( $data ) || ! is_int( $data['version'] ) || $data['version'] < 1 || ! is_array( $data['values'] ) ) {
			CodedException::raise( SettingsError::StoredValueInvalid, array( 'option' => $settings[0]->optionName() ) );
		}

		$values = array();

		foreach ( $settings as $setting ) {
			if ( array_key_exists( $setting->name(), $data['values'] ) ) {
				$values[ $setting->name() ] = SettingValues::fromStorage( $setting, $data['values'][ $setting->name() ] );
			}
		}

		return new SettingsDocument( $data['version'], $values );
	}

	/**
	 * Refuses a document write that lost the race, after dropping the cached copy it was based on.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException With SettingsError::VersionConflict, always.
	 *
	 * @param string $group  The group.
	 * @param string $option The option.
	 * @return never
	 */
	private function conflict( string $group, string $option ): never {
		$this->forget( $option );

		CodedException::raise( SettingsError::VersionConflict, array( 'group' => $group ) );
	}

	/**
	 * Removes an option from the object cache, where core keeps it: its own entry and both lists.
	 *
	 * The lists are deleted whole, never edited and stored back, so a writer cannot store a copy
	 * of a list that another writer changed after it was read. The next reader rebuilds them.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 */
	private function forget( string $option ): void {
		wp_cache_delete( $option, self::CACHE_GROUP );

		foreach ( self::CACHED_LISTS as $list ) {
			wp_cache_delete( $list, self::CACHE_GROUP );
		}
	}

	/**
	 * Inside a transaction, keeps a written option out of the cache until the outcome is known.
	 *
	 * Until then the store reads from the database only. Once it is known, the option and
	 * both lists are removed from the cache again: on a rollback a cached value may be one that
	 * never became durable; after a commit, another request may have cached the value this write
	 * replaced while the transaction was open. Outside a transaction there is nothing to wait for.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option The option.
	 */
	private function settle( string $option ): void {
		if ( 0 === $this->database->depth() ) {
			return;
		}

		if ( array() === $this->written ) {
			$this->forgetPendingWhenTheTransactionEnds();
		}

		$this->written[ $option ] = true;

		foreach ( array_merge( array( $option ), self::CACHED_LISTS ) as $key ) {
			$this->database->touchCacheKey( $key, self::CACHE_GROUP );
		}

		$this->database->afterCommit(
			function () use ( $option ): void {
				$this->forget( $option );
			}
		);
	}
}
