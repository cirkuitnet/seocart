<?php
/**
 * LocationRecord: a location as its record is shown and edited
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One location's whole record: its identity, whether it is active and the default, what the merchant wrote about it, and its pickup hours.
 *
 * Owns one fact: what the location operations read and answer. Routing reads the smaller
 * Location, which the object cache keeps; a record is read from the tables each time.
 *
 * @since 0.2.0
 */
final readonly class LocationRecord {

	/**
	 * Records a location.
	 *
	 * @since 0.2.0
	 *
	 * @param int             $id        The id stock rows name.
	 * @param string          $uuid      The public identifier.
	 * @param bool            $active    Whether orders may be routed to it.
	 * @param bool            $isDefault Whether it is the store's default location.
	 * @param LocationDetails $details   What the merchant wrote about it.
	 * @param PickupHours[]   $hours     The days it is open for pickup, by day; a day not listed is closed.
	 *
	 * @phpstan-param list<PickupHours> $hours
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public bool $active,
		public bool $isDefault,
		public LocationDetails $details,
		public array $hours
	) {
	}
}
