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
use SEOCart\Inventory\Domain\LocationRepository;

/**
 * A LocationRepository with one location: the default, active and fulfilling online orders, of the id FakeStockRepository keeps its stock at.
 *
 * @since 0.2.0
 */
final class FakeLocationRepository implements LocationRepository {

	/**
	 * Lists the one location.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Location> The default location.
	 */
	public function all(): array {
		return array( new Location( FakeStockRepository::LOCATION, '00000000-0000-7000-8000-00000000000a', 'Store', true, true, false, 0, true ) );
	}
}
