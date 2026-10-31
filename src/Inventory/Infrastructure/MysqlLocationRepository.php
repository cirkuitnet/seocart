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
use SEOCart\Inventory\Domain\LocationDetails;
use SEOCart\Inventory\Domain\LocationRecord;
use SEOCart\Inventory\Domain\LocationRepository;
use SEOCart\Inventory\Domain\PickupHours;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The location repository on MySQL: the one class that sends SQL to `locations` and its two child tables.
 *
 * Owns one fact: the text of every location statement, each a public constant naming its table as
 * `{locations}`, `{location_pickup_hours}` or `{location_markets}`. The list of every location is
 * kept in the object cache, in the CACHE_GROUP group, because routing reads it on every order and
 * it changes only when a merchant edits a location; every write of this class removes it, at once
 * and again once the write has committed, so a request that read the list meanwhile cannot keep
 * an older one. A list read inside a transaction is recorded with it, so a rollback removes it too.
 *
 * The record reads are never cached. A change of a location begins with lockRecord(), whose
 * locking read takes the location's row lock; an adjustment at the location takes the same row's
 * shared lock first, so a deletion and an adjustment of one location never interleave.
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
	public const ALL = 'SELECT ' . self::LOCATION_COLUMNS . ' FROM {locations} ORDER BY priority, id';

	/**
	 * Some locations by public identifier, locked in share mode until the commit, ascending by id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_SHARED = 'SELECT ' . self::LOCATION_COLUMNS . ' FROM {locations} WHERE uuid IN ({list}) ORDER BY id LOCK IN SHARE MODE';

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
	 * Every location's record, in the order they are tried.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECORDS = 'SELECT ' . self::RECORD_COLUMNS . ' FROM {locations} ORDER BY priority, id';

	/**
	 * One location's record, by its public identifier, with a locking read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_RECORD = 'SELECT ' . self::RECORD_COLUMNS . ' FROM {locations} WHERE uuid = %s FOR UPDATE';

	/**
	 * The days some locations are open for pickup, by location then day.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const HOURS = 'SELECT location_id, weekday, opens_at, closes_at FROM {location_pickup_hours} WHERE location_id IN ({list}) AND opens_at IS NOT NULL AND closes_at IS NOT NULL ORDER BY location_id, weekday';

	/**
	 * A new location, active; the default only when the caller says it is the store's first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_LOCATION = "INSERT INTO {locations} ( uuid, name, country, line1, line2, city, region, postcode, phone, active, fulfils_online, offers_pickup, pickup_ready_in, pickup_instructions, priority, is_default, created_at, updated_at ) VALUES ( %s, %s, %s, %s, %s, %s, %s, %s, NULLIF( %s, '' ), 1, %d, %d, NULLIF( %s, '' ), NULLIF( %s, '' ), %d, NULLIF( %d, 0 ), UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )";

	/**
	 * Every field the merchant writes about a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_DETAILS = "UPDATE {locations} SET name = %s, country = %s, line1 = %s, line2 = %s, city = %s, region = %s, postcode = %s, phone = NULLIF( %s, '' ), fulfils_online = %d, offers_pickup = %d, pickup_ready_in = NULLIF( %s, '' ), pickup_instructions = NULLIF( %s, '' ), priority = %d, updated_at = UTC_TIMESTAMP(6) WHERE id = %d";

	/**
	 * A location's pickup hours, all of them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_HOURS = 'DELETE FROM {location_pickup_hours} WHERE location_id = %d';

	/**
	 * One day of a location's pickup hours; empty times for a day it is closed.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const INSERT_HOURS = "INSERT INTO {location_pickup_hours} ( location_id, weekday, opens_at, closes_at ) VALUES ( %d, %d, NULLIF( %s, '' ), NULLIF( %s, '' ) )";

	/**
	 * Deactivates a location, unless it is the default.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEACTIVATE = 'UPDATE {locations} SET active = 0, updated_at = UTC_TIMESTAMP(6) WHERE id = %d AND is_default IS NULL';

	/**
	 * Activates a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACTIVATE = 'UPDATE {locations} SET active = 1, updated_at = UTC_TIMESTAMP(6) WHERE id = %d';

	/**
	 * The default location, with a locking read: the first step of a move of the default.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCK_DEFAULT = 'SELECT id FROM {locations} WHERE is_default = 1 FOR UPDATE';

	/**
	 * Clears the default marker of every location but one.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLEAR_DEFAULT = 'UPDATE {locations} SET is_default = NULL, updated_at = UTC_TIMESTAMP(6) WHERE is_default = 1 AND id <> %d';

	/**
	 * The markets a location ships to, all of them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_MARKETS = 'DELETE FROM {location_markets} WHERE location_id = %d';

	/**
	 * A location, unless it is the default.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_LOCATION = 'DELETE FROM {locations} WHERE id = %d AND is_default IS NULL';

	/**
	 * The columns of a location's record, in the order record() reads them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const RECORD_COLUMNS = 'id, uuid, name, country, line1, line2, city, region, postcode, phone, active, fulfils_online, offers_pickup, pickup_ready_in, pickup_instructions, priority, is_default';

	/**
	 * The columns of a location as routing reads it, in the order location() reads them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LOCATION_COLUMNS = 'id, uuid, name, active, fulfils_online, offers_pickup, priority, is_default';

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
	 * Reads every location's record and its pickup hours: two reads.
	 *
	 * @since 0.2.0
	 *
	 * @return list<LocationRecord> The records, in the order locations are tried.
	 */
	public function records(): array {
		$rows  = $this->statements->rows( self::RECORDS );
		$hours = $this->hoursOf( array_map( static fn( array $row ): int => (int) $row['id'], $rows ) );

		return array_map( static fn( array $row ): LocationRecord => self::record( $row, $hours[ (int) $row['id'] ] ?? array() ), $rows );
	}

	/**
	 * Reads one location's record under its row lock, then its pickup hours.
	 *
	 * The hours are a plain read: every write to them is made under the same row lock, so they are
	 * the latest committed once the lock is held.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The location's public identifier.
	 * @return LocationRecord|null The record, or null when no location has the uuid.
	 */
	public function lockRecord( string $uuid ): ?LocationRecord {
		$this->requireTransaction( __FUNCTION__ );

		$row = $this->statements->rows( self::LOCK_RECORD, strtolower( $uuid ) )[0] ?? null;

		if ( null === $row ) {
			return null;
		}

		return self::record( $row, $this->hoursOf( array( (int) $row['id'] ) )[ (int) $row['id'] ] ?? array() );
	}

	/**
	 * Reads locations by uuid in share mode.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $uuids The locations' public identifiers.
	 * @return array<string, Location> The locations found, by uuid.
	 */
	public function lockShared( array $uuids ): array {
		$this->requireTransaction( __FUNCTION__ );

		$found = array();

		if ( array() === $uuids ) {
			return $found;
		}

		foreach ( $this->statements->rows( self::LOCK_SHARED, array_values( array_map( 'strtolower', $uuids ) ) ) as $row ) {
			$location                 = self::location( $row );
			$found[ $location->uuid ] = $location;
		}

		return $found;
	}

	/**
	 * Creates an active location with its fields, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @param string          $uuid      Its public identifier.
	 * @param LocationDetails $details   What the merchant wrote about it.
	 * @param bool            $isDefault Whether it is the store's first location, and so its default.
	 * @return int The id.
	 */
	public function insert( string $uuid, LocationDetails $details, bool $isDefault ): int {
		$this->requireTransaction( __FUNCTION__ );

		list( $country, $line1, $line2, $city, $region, $postcode ) = self::addressColumns( $details->address );

		$this->statements->execute(
			self::INSERT_LOCATION,
			$uuid,
			$details->name,
			$country,
			$line1,
			$line2,
			$city,
			$region,
			$postcode,
			$details->phone ?? '',
			$details->fulfilsOnline ? 1 : 0,
			$details->offersPickup ? 1 : 0,
			$details->pickupReadyIn ?? '',
			$details->pickupInstructions ?? '',
			$details->priority,
			$isDefault ? 1 : 0
		);
		$this->forgetList();

		return $this->statements->lastInsertId();
	}

	/**
	 * Writes every field the merchant writes about a location.
	 *
	 * @since 0.2.0
	 *
	 * @param int             $locationId The location, locked.
	 * @param LocationDetails $details    The fields.
	 */
	public function updateDetails( int $locationId, LocationDetails $details ): void {
		$this->requireTransaction( __FUNCTION__ );

		list( $country, $line1, $line2, $city, $region, $postcode ) = self::addressColumns( $details->address );

		$this->statements->execute(
			self::UPDATE_DETAILS,
			$details->name,
			$country,
			$line1,
			$line2,
			$city,
			$region,
			$postcode,
			$details->phone ?? '',
			$details->fulfilsOnline ? 1 : 0,
			$details->offersPickup ? 1 : 0,
			$details->pickupReadyIn ?? '',
			$details->pickupInstructions ?? '',
			$details->priority,
			$locationId
		);
		$this->forgetList();
	}

	/**
	 * Replaces a location's pickup hours with seven rows, one per day, a day not given with no times.
	 *
	 * @since 0.2.0
	 *
	 * @param int           $locationId The location, locked.
	 * @param PickupHours[] $hours      The days it is open, each day once.
	 *
	 * @phpstan-param list<PickupHours> $hours
	 */
	public function replaceHours( int $locationId, array $hours ): void {
		$this->requireTransaction( __FUNCTION__ );

		$open = array();

		foreach ( $hours as $day ) {
			$open[ $day->weekday ] = $day;
		}

		$values = array();

		for ( $weekday = 0; $weekday < PickupHours::DAYS; $weekday++ ) {
			array_push( $values, $locationId, $weekday, isset( $open[ $weekday ] ) ? $open[ $weekday ]->opensAt : '', isset( $open[ $weekday ] ) ? $open[ $weekday ]->closesAt : '' );
		}

		$this->statements->execute( self::DELETE_HOURS, $locationId );
		$this->statements->execute( ModuleStatements::forRows( self::INSERT_HOURS, PickupHours::DAYS ), ...$values );
		$this->forgetList();
	}

	/**
	 * Deactivates a location, unless it is the default: the statement's WHERE clause keeps the rule.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location, locked.
	 * @return bool True when it is now inactive.
	 */
	public function deactivate( int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		$deactivated = 1 === $this->statements->execute( self::DEACTIVATE, $locationId );

		$this->forgetList();

		return $deactivated;
	}

	/**
	 * Activates a location.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location, locked.
	 */
	public function activate( int $locationId ): void {
		$this->requireTransaction( __FUNCTION__ );

		$this->statements->execute( self::ACTIVATE, $locationId );
		$this->forgetList();
	}

	/**
	 * Moves the default marker to an active location: locks the default, clears it, and marks the new one.
	 *
	 * No plain read comes before the writes. The first statement only waits: a move of the default
	 * in flight holds the old default's row, so this one waits for it to commit. The clear is a
	 * statement of its own after that wait, because a statement that waited on one row may have
	 * passed over the row the other move marked; sent afterwards, at READ COMMITTED, the clear sees
	 * that row. The mark's WHERE clause requires the location to be active and not the default
	 * already, and the unique key on the marker refuses a second default whatever happens.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location, locked, not the default yet.
	 * @return bool True when it is now the default; false when it is inactive.
	 */
	public function moveDefault( int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		$this->statements->rows( self::LOCK_DEFAULT );
		$this->statements->execute( self::CLEAR_DEFAULT, $locationId );

		$marked = 1 === $this->statements->execute( self::MARK_DEFAULT, $locationId );

		$this->forgetList();

		return $marked;
	}

	/**
	 * Deletes a location's pickup hours and markets, then the location, unless it is the default.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location, locked.
	 * @return bool True when the location was deleted.
	 */
	public function delete( int $locationId ): bool {
		$this->requireTransaction( __FUNCTION__ );

		$this->statements->execute( self::DELETE_HOURS, $locationId );
		$this->statements->execute( self::DELETE_MARKETS, $locationId );

		$deleted = 1 === $this->statements->execute( self::DELETE_LOCATION, $locationId );

		$this->forgetList();

		return $deleted;
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
	 * Reads the days some locations are open for pickup.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $locationIds The locations; none sends nothing.
	 * @return array<int, list<PickupHours>> Each location's open days, by day, by location id.
	 *
	 * @phpstan-param list<int> $locationIds
	 */
	private function hoursOf( array $locationIds ): array {
		if ( array() === $locationIds ) {
			return array();
		}

		$hours = array();

		foreach ( $this->statements->rows( self::HOURS, $locationIds ) as $row ) {
			// The time columns read back as HH:MM:SS; a day's hours are written and shown to the minute.
			$hours[ (int) $row['location_id'] ][] = new PickupHours( (int) $row['weekday'], substr( (string) $row['opens_at'], 0, 5 ), substr( (string) $row['closes_at'], 0, 5 ) );
		}

		return $hours;
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

	/**
	 * Builds a location's record from its row and its open days.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $row   The row, of RECORD_COLUMNS.
	 * @param PickupHours[]        $hours Its open days.
	 * @return LocationRecord The record.
	 *
	 * @phpstan-param list<PickupHours> $hours
	 */
	private static function record( array $row, array $hours ): LocationRecord {
		$country = (string) $row['country'];
		$address = '' === $country ? null : new Address(
			$country,
			line1: (string) $row['line1'],
			line2: (string) $row['line2'],
			city: (string) $row['city'],
			region: (string) $row['region'],
			postcode: (string) $row['postcode']
		);

		return new LocationRecord(
			(int) $row['id'],
			(string) $row['uuid'],
			'1' === (string) $row['active'],
			'1' === (string) $row['is_default'],
			new LocationDetails(
				(string) $row['name'],
				$address,
				null === $row['phone'] ? null : (string) $row['phone'],
				'1' === (string) $row['fulfils_online'],
				'1' === (string) $row['offers_pickup'],
				null === $row['pickup_ready_in'] ? null : (string) $row['pickup_ready_in'],
				null === $row['pickup_instructions'] ? null : (string) $row['pickup_instructions'],
				(int) $row['priority']
			),
			$hours
		);
	}

	/**
	 * Returns the address columns of an address: all empty for none, as a location starts.
	 *
	 * @since 0.2.0
	 *
	 * @param Address|null $address The address, or null.
	 * @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} The country, the two lines, the city, the region and the postcode.
	 */
	private static function addressColumns( ?Address $address ): array {
		if ( null === $address ) {
			return array( '', '', '', '', '', '' );
		}

		return array( $address->country(), $address->line1(), $address->line2(), $address->city(), $address->region(), $address->postcode() );
	}
}
