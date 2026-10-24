<?php
/**
 * MysqlLocationRepository: every location statement, over the plugin's Database
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure;

use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The location repository on MySQL: the one class that sends SQL to `locations`.
 *
 * Owns one fact: the text of every location statement, each a public constant naming its table as
 * `{locations}`. The list of every location is kept in the object cache, in the CACHE_GROUP
 * group, because routing reads it on every order and it changes only when a merchant edits a
 * location; every write of this class removes it, at once and again once the write has committed,
 * so a request that read the list meanwhile cannot keep an older one. A list read inside a
 * transaction is recorded with it, so a rollback removes it too.
 *
 * The reads for `doctor` and the store's first location, which the upgrade creates, are here too,
 * because they are location SQL; they are not part of the port the service sees.
 *
 * @since 0.2.0
 */
final class MysqlLocationRepository implements LocationRepository {

	/**
	 * The object-cache group the list of locations is kept in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_GROUP = 'seocart_inventory';

	/**
	 * The object-cache key of the list of locations.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'locations';

	/**
	 * Every location, in the order they are tried.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ALL = 'SELECT id, uuid, name, active, fulfils_online, offers_pickup, priority, is_default FROM {locations} ORDER BY priority, id';

	/**
	 * The default location's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEFAULT_ID = 'SELECT id FROM {locations} WHERE is_default = 1';

	/**
	 * Any one location: whether the store has one at all.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIRST_ID = 'SELECT id FROM {locations} ORDER BY id LIMIT 1';

	/**
	 * The active location with the lowest id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOWEST_ACTIVE_ID = 'SELECT id FROM {locations} WHERE active = 1 ORDER BY id LIMIT 1';

	/**
	 * The default location, when it has no address yet.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEFAULT_WITHOUT_ADDRESS = "SELECT id FROM {locations} WHERE is_default = 1 AND country = ''";

	/**
	 * The store's first location, its default: no address, active, fulfilling online orders, no pickup.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_DEFAULT = 'INSERT INTO {locations} ( uuid, name, active, fulfils_online, offers_pickup, priority, is_default, created_at, updated_at ) VALUES ( %s, %s, 1, 1, 0, 0, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )';

	/**
	 * Marks an active location as the default, when it is not; the unique key refuses a second default.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const MARK_DEFAULT = 'UPDATE {locations} SET is_default = 1, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND active = 1 AND is_default IS NULL';

	/**
	 * The connection.
	 *
	 * @since 0.2.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Sends the statements.
	 *
	 * @since 0.2.0
	 *
	 * @var ModuleStatements
	 */
	private ModuleStatements $statements;

	/**
	 * Creates the repository. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db         = $db;
		$this->statements = new ModuleStatements( $db, 'inventory', InventoryTables::names() );
	}

	/**
	 * Lists every location, from the object cache when it holds the list.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Location> The locations, in the order they are tried.
	 */
	public function all(): array {
		$found = false;
		$rows  = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP, false, $found );

		if ( ! $found || ! is_array( $rows ) ) {
			$rows = $this->statements->rows( self::ALL );

			$this->db->touchCacheKey( self::CACHE_KEY, self::CACHE_GROUP );
			wp_cache_set( self::CACHE_KEY, $rows, self::CACHE_GROUP );
		}

		return array_values( array_map( static fn( array $row ): Location => self::location( $row ), $rows ) );
	}

	/**
	 * Marks the active location with the lowest id as the default, when the store has none: doctor's repair.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null The location marked, or null.
	 */
	public function markLowestActiveDefault(): ?int {
		$this->requireTransaction( __FUNCTION__ );

		$lowest = $this->firstId( self::LOWEST_ACTIVE_ID );

		if ( null !== $this->defaultId() || null === $lowest || 1 !== $this->statements->execute( self::MARK_DEFAULT, $lowest ) ) {
			return null;
		}

		$this->forgetList();

		return $lowest;
	}

	/**
	 * Reads the default location's id. A plain read, never from the cache.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null The id, or null when the store has no default location.
	 */
	public function defaultId(): ?int {
		return $this->firstId( self::DEFAULT_ID );
	}

	/**
	 * Tells whether the store has any location.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it has one.
	 */
	public function any(): bool {
		return null !== $this->firstId( self::FIRST_ID );
	}

	/**
	 * Reads the default location's id when it has no address yet, for doctor.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null The id, or null when there is no default or it has an address.
	 */
	public function defaultWithoutAddress(): ?int {
		return $this->firstId( self::DEFAULT_WITHOUT_ADDRESS );
	}

	/**
	 * Creates the store's first location, its default, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The location's public identifier.
	 * @param string $name Its name.
	 * @return int The id.
	 */
	public function createDefault( string $uuid, string $name ): int {
		$this->requireTransaction( __FUNCTION__ );

		$this->statements->execute( self::INSERT_DEFAULT, $uuid, $name );
		$this->forgetList();

		return $this->statements->lastInsertId();
	}

	/**
	 * Removes the cached list of locations now, and again once the write that changed it has committed.
	 *
	 * @since 0.2.0
	 */
	private function forgetList(): void {
		wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );

		$this->db->afterCommit( static fn(): bool => wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP ) );
	}

	/**
	 * Sends a read of one id.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement One of this class's constants, without placeholders.
	 * @return int|null The id of its first row, or null when it has none.
	 */
	private function firstId( string $statement ): ?int {
		$row = $this->statements->rows( $statement )[0] ?? null;

		return null === $row ? null : (int) $row['id'];
	}

	/**
	 * Refuses to change a location outside a transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireTransaction( string $method ): void {
		if ( 0 === $this->db->depth() ) {
			throw new \LogicException( sprintf( 'MysqlLocationRepository::%s() runs only inside a transaction.', $method ) );
		}
	}

	/**
	 * Builds a location from a row.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row The row.
	 * @return Location The location.
	 */
	private static function location( array $row ): Location {
		return new Location(
			(int) $row['id'],
			(string) $row['uuid'],
			(string) $row['name'],
			'1' === (string) $row['active'],
			'1' === (string) $row['fulfils_online'],
			'1' === (string) $row['offers_pickup'],
			(int) $row['priority'],
			'1' === (string) $row['is_default']
		);
	}
}
