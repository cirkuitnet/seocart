<?php
/**
 * RoutingContext: what a caller knows about where an order may go
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

defined( 'ABSPATH' ) || exit;

/**
 * The market an order is sold in, the location its shopper collects it at, and the location it is already fulfilled from, as far as the caller knows them.
 *
 * Owns one fact: the facts about an order that routing takes from outside the inventory module.
 * Each is optional. With no market, every location that fulfils online orders serves the
 * shopper. A destination is the order's one fulfilment location, once the order has one: units
 * claimed for it afresh are brought there. none() knows nothing, and routes to the store's
 * default location.
 *
 * @since 0.2.0
 */
final readonly class RoutingContext {

	/**
	 * Records a context.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $marketId              Optional. The market the order is sold in. Default null, any.
	 * @param int|null $pickupLocationId      Optional. Where the shopper collects the order. Default null, none.
	 * @param int|null $destinationLocationId Optional. The location the order is fulfilled from. Default null, not known.
	 */
	public function __construct(
		public ?int $marketId = null,
		public ?int $pickupLocationId = null,
		public ?int $destinationLocationId = null
	) {
	}

	/**
	 * Returns the context of a caller that knows nothing about the order's locations.
	 *
	 * @since 0.2.0
	 *
	 * @return self No market, no pickup, no destination.
	 */
	public static function none(): self {
		return new self();
	}
}
