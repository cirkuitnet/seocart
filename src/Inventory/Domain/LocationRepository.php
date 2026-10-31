<?php
/**
 * LocationRepository: the port through which the inventory module reads and writes its locations
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The store's locations: the list routing reads, and the records a merchant edits.
 *
 * Owns one fact: where the inventory module's services learn which locations there are, and how
 * a location's record changes. The list is small and read on every routing, so it may be served
 * from the object cache; every write to a location invalidates it. A record is never served from
 * a cache. A read that a stock change relies on, such as whether the location an adjustment
 * names exists, is a locking read of the stock repository's.
 *
 * Every write runs inside the caller's transaction, under the lock lockRecord() took on the
 * location it changes, so two changes of one location happen one after the other. Whether a
 * location is active and whether it is the store's default each change by a write of their own,
 * whose WHERE clause keeps the rule: the default is never deactivated or deleted, an inactive
 * location is never made the default, and the unique key on the marker refuses a second default.
 *
 * @since 0.2.0
 */
interface LocationRepository {

	/**
	 * Lists every location, in the order they are tried: ascending priority, ties by id.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Location> The locations, active or not.
	 */
	public function all(): array;

	/**
	 * Tells whether the store has any location. A plain read.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it has one.
	 */
	public function any(): bool;

	/**
	 * Reads every location's record with its pickup hours. A plain read, never from a cache.
	 *
	 * @since 0.2.0
	 *
	 * @return list<LocationRecord> The records, in the order locations are tried.
	 */
	public function records(): array;

	/**
	 * Reads one location's record with a locking read, and its pickup hours: its latest committed values, with the location locked.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string $uuid The location's public identifier.
	 * @return LocationRecord|null The record, or null when no location has the uuid.
	 *
	 * @phpstan-impure
	 */
	public function lockRecord( string $uuid ): ?LocationRecord;

	/**
	 * Reads locations by uuid with a locking read in share mode, which keeps each from being changed or deleted until the commit.
	 *
	 * For a write that names a location without changing it, such as a transfer: a deletion of the
	 * location takes its row's exclusive lock before it reads what uses it, so it waits for this
	 * write to commit and then finds it, or comes first and leaves nothing to find. Shared locks do
	 * not wait for each other, so the rows are read in ascending order of id and need no more order
	 * than that against the one location an exclusive lock is ever taken on at a time.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string[] $uuids The locations' public identifiers.
	 * @return array<string, Location> The locations found, by lower-case uuid.
	 *
	 * @phpstan-impure
	 */
	public function lockShared( array $uuids ): array;

	/**
	 * Creates an active location, and returns its id.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param string          $uuid      Its public identifier.
	 * @param LocationDetails $details   What the merchant wrote about it.
	 * @param bool            $isDefault Whether it is the store's default location: true only for a store's first.
	 * @return int The id.
	 *
	 * @phpstan-impure
	 */
	public function insert( string $uuid, LocationDetails $details, bool $isDefault ): int;

	/**
	 * Writes what the merchant wrote about a location, every field of it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int             $locationId The location, locked.
	 * @param LocationDetails $details    The fields.
	 */
	public function updateDetails( int $locationId, LocationDetails $details ): void;

	/**
	 * Replaces a location's pickup hours: one row for each day of the week, a day not given closed.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int           $locationId The location, locked.
	 * @param PickupHours[] $hours      The days it is open, each day once.
	 *
	 * @phpstan-param list<PickupHours> $hours
	 */
	public function replaceHours( int $locationId, array $hours ): void;

	/**
	 * Deactivates a location, unless it is the store's default.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $locationId The location, locked.
	 * @return bool True when it is now inactive; false when it is the default, which stays active.
	 *
	 * @phpstan-impure
	 */
	public function deactivate( int $locationId ): bool;

	/**
	 * Activates a location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $locationId The location, locked.
	 */
	public function activate( int $locationId ): void;

	/**
	 * Makes an active location the store's default: the old default's marker is cleared and the new one's set, in the caller's transaction.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $locationId The location, locked, not the default yet.
	 * @return bool True when it is now the default; false when it is inactive, and the caller's rollback must put the old marker back.
	 *
	 * @phpstan-impure
	 */
	public function moveDefault( int $locationId ): bool;

	/**
	 * Deletes a location with its pickup hours and its markets, unless it is the store's default. The caller has made sure nothing else names it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When called outside a transaction.
	 *
	 * @param int $locationId The location, locked.
	 * @return bool True when it was deleted; false when it is the default, which stays.
	 *
	 * @phpstan-impure
	 */
	public function delete( int $locationId ): bool;
}
