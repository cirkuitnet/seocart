<?php
/**
 * FakeLocationRepository: an in-memory location repository holding the store's one default location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\LocationDetails;
use SEOCart\Inventory\Domain\LocationRecord;
use SEOCart\Inventory\Domain\LocationRepository;

/**
 * A LocationRepository with one location: the default, active and fulfilling online orders, of the id FakeStockRepository keeps its stock at.
 *
 * It reads the one location and changes nothing: a change of a location is a transaction of
 * several statements whose locks are the point, so it is tested against the real tables, and a
 * write here fails the test that reached it.
 *
 * @since 0.2.0
 */
final class FakeLocationRepository implements LocationRepository {

	/**
	 * The one location's public identifier.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UUID = '00000000-0000-7000-8000-00000000000a';

	/**
	 * Lists the one location.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Location> The default location.
	 */
	public function all(): array {
		return array( new Location( FakeStockRepository::LOCATION, self::UUID, 'Store', true, true, false, 0, true ) );
	}

	/**
	 * Tells whether the store has a location: it has its one.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True.
	 */
	public function any(): bool {
		return true;
	}

	/**
	 * Reads the one location's record: no address, no phone, no pickup.
	 *
	 * @since 0.2.0
	 *
	 * @return list<LocationRecord> The record.
	 */
	public function records(): array {
		return array( new LocationRecord( FakeStockRepository::LOCATION, self::UUID, true, true, new LocationDetails( 'Store', null, null, true, false, null, null, 0 ), array() ) );
	}

	/**
	 * Reads the one location, when a uuid is its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $uuids The uuids.
	 * @return array<string, Location> The location, by uuid, or nothing.
	 */
	public function lockShared( array $uuids ): array {
		return in_array( self::UUID, array_map( 'strtolower', $uuids ), true ) ? array( self::UUID => $this->all()[0] ) : array();
	}

	/**
	 * Reads the one location's record, when the uuid is its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The uuid.
	 * @return LocationRecord|null The record, or null.
	 */
	public function lockRecord( string $uuid ): ?LocationRecord {
		return self::UUID === $uuid ? $this->records()[0] : null;
	}

	/**
	 * Refuses to create a location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param string          $uuid      The uuid.
	 * @param LocationDetails $details   The fields.
	 * @param bool            $isDefault Whether it is the default.
	 * @return never
	 */
	public function insert( string $uuid, LocationDetails $details, bool $isDefault ): int {
		unset( $uuid, $details, $isDefault );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to change the location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int             $locationId The location.
	 * @param LocationDetails $details    The fields.
	 */
	public function updateDetails( int $locationId, LocationDetails $details ): void {
		unset( $locationId, $details );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to change the location's hours.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int   $locationId The location.
	 * @param array $hours      The hours.
	 *
	 * @phpstan-param list<\SEOCart\Inventory\Domain\PickupHours> $hours
	 */
	public function replaceHours( int $locationId, array $hours ): void {
		unset( $locationId, $hours );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to deactivate the location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $locationId The location.
	 * @return never
	 */
	public function deactivate( int $locationId ): bool {
		unset( $locationId );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to activate the location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $locationId The location.
	 */
	public function activate( int $locationId ): void {
		unset( $locationId );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to move the default.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $locationId The location.
	 * @return never
	 */
	public function moveDefault( int $locationId ): bool {
		unset( $locationId );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Refuses to delete the location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param int $locationId The location.
	 * @return never
	 */
	public function delete( int $locationId ): bool {
		unset( $locationId );

		self::refuse( __FUNCTION__ );
	}

	/**
	 * Fails the test that reached a write.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Always.
	 *
	 * @param string $method The write called.
	 * @return never
	 */
	private static function refuse( string $method ): never {
		throw new \LogicException( sprintf( 'FakeLocationRepository::%s(): a change of a location is tested against the real tables.', $method ) );
	}
}
