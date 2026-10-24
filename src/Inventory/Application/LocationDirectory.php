<?php
/**
 * LocationDirectory: which locations serve which purpose
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\LocationRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Answers which locations fulfil a market's orders, which are active, which offer pickup, and which is the default, from one list of the store's locations.
 *
 * Owns one fact: what each purpose requires of a location. A location serves a purpose only while
 * it is active. Until the store has markets, a market is any market: every active location that
 * fulfils online orders serves every shopper, and every active location that offers pickup is
 * offered. The list is read once, from the repository, which keeps it in the object cache; every
 * answer is in the order locations are tried, ascending priority, ties by id. A directory is for
 * routing and display; a stock change that relies on a location existing asks the repository
 * under a lock.
 *
 * @since 0.2.0
 */
final class LocationDirectory {

	/**
	 * The locations.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationRepository
	 */
	private LocationRepository $locations;

	/**
	 * Creates the directory. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param LocationRepository $locations The locations.
	 */
	public function __construct( LocationRepository $locations ) {
		$this->locations = $locations;
	}

	/**
	 * Lists the active locations: those orders may be routed to and whose units count as available.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Location> The locations, in the order they are tried.
	 */
	public function active(): array {
		return array_values( array_filter( $this->locations->all(), static fn( Location $location ): bool => $location->active ) );
	}

	/**
	 * Lists the active locations that ship a market's online orders.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $marketId The market, or null for any. Until the store has markets, every market is any.
	 * @return list<Location> The locations, in the order they are tried.
	 */
	public function fulfilling( ?int $marketId ): array {
		unset( $marketId );

		return array_values( array_filter( $this->active(), static fn( Location $location ): bool => $location->fulfilsOnline ) );
	}

	/**
	 * Lists the active locations a market's shoppers may collect orders at.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $marketId The market, or null for any. Until the store has markets, every market is any.
	 * @return list<Location> The locations, in the order they are tried.
	 */
	public function pickup( ?int $marketId ): array {
		unset( $marketId );

		return array_values( array_filter( $this->active(), static fn( Location $location ): bool => $location->offersPickup ) );
	}

	/**
	 * Finds a location, active or not.
	 *
	 * @since 0.2.0
	 *
	 * @param int $locationId The location.
	 * @return Location|null The location, or null when the store has none of that id.
	 */
	public function find( int $locationId ): ?Location {
		foreach ( $this->locations->all() as $location ) {
			if ( $location->id === $locationId ) {
				return $location;
			}
		}

		return null;
	}

	/**
	 * Returns the store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @return Location|null The location, or null when the store has none, which doctor reports.
	 */
	public function default(): ?Location {
		foreach ( $this->locations->all() as $location ) {
			if ( $location->isDefault ) {
				return $location;
			}
		}

		return null;
	}
}
