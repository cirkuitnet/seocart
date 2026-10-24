<?php
/**
 * Location: a place the store keeps stock and fulfils orders from
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never HTML.

/**
 * One location as routing and the stock checks see it: its identity, whether it is in use, what it does, and where it stands in the order locations are tried.
 *
 * Owns one fact: what decides whether stock at a location can serve an order. A deactivated
 * location keeps its stock rows, but orders are no longer routed to it and its units count as
 * available nowhere. A location that does not fulfil online orders may still send its units to
 * one that does. Locations are tried in ascending priority, ties by id. The address, the phone
 * and the pickup details are the location's record, read where they are shown, not here.
 *
 * @since 0.2.0
 */
final readonly class Location {

	/**
	 * Records a location.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is below 1: a caller's bug.
	 *
	 * @param int    $id            The id stock rows name.
	 * @param string $uuid          The public identifier.
	 * @param string $name          The name the merchant gave it.
	 * @param bool   $active        Whether orders may be routed to it and its units count as available.
	 * @param bool   $fulfilsOnline Whether it ships online orders.
	 * @param bool   $offersPickup  Whether shoppers may collect orders there.
	 * @param int    $priority      Where it stands in the order locations are tried: the lowest first.
	 * @param bool   $isDefault     Whether it is the store's default location.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $name,
		public bool $active,
		public bool $fulfilsOnline,
		public bool $offersPickup,
		public int $priority,
		public bool $isDefault
	) {
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A location id is 1 or more; %d was given.', $id ) );
		}
	}
}
