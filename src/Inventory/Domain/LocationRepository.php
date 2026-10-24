<?php
/**
 * LocationRepository: the port through which the inventory module reads its locations
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The list of the store's locations, which routing and the default location are read from.
 *
 * Owns one fact: where the inventory module's services learn which locations there are. The list
 * is small and read on every routing, so it may be served from the object cache; every write to a
 * location invalidates it. A read that a stock change relies on, such as whether the location an
 * adjustment names exists, is a locking read of the stock repository's and never served from a
 * cache.
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
}
