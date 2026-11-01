<?php
/**
 * RoutingScope: asks a calculation to plan where the order's units are held
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

defined( 'ABSPATH' ) || exit;

/**
 * What a calculation is told about where the order may be fulfilled: the market it is sold in and the location it is collected at.
 *
 * Owns one fact: that the caller wants a fulfilment plan quoted with the totals. A request that
 * carries a scope has the router asked, once it also knows where the order goes (a destination,
 * or a pickup location); a request without one never asks it, so a cart read costs no routing.
 * The checkout asks with the scope checkout() returns: no market and no pickup location are known
 * to it yet.
 *
 * @since 0.2.0
 */
final readonly class RoutingScope {

	/**
	 * Holds the scope.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $marketId         Optional. The market the order is sold in. Default null, none known.
	 * @param int|null $pickupLocationId Optional. The location the shopper collects the order at. Default
	 *                                   null, the order ships.
	 */
	public function __construct(
		public ?int $marketId = null,
		public ?int $pickupLocationId = null
	) {
	}

	/**
	 * Returns the scope a checkout asks with: the session write and the placement.
	 *
	 * @since 0.2.0
	 *
	 * @return self A scope with no market and no pickup location.
	 */
	public static function checkout(): self {
		return new self();
	}
}
