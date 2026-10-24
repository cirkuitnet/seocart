<?php
/**
 * Allocation: one order line's claim on the units of one variant, at a location
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never HTML.

/**
 * One order line's claim on the units of one variant, as a `stock_allocations` row records it.
 *
 * Owns one fact: what an allocation names. Its units count in the `allocated` of the item at its
 * location until they are posted or cancelled; while it is open the variant cannot be deleted. An
 * order asks for its lines without a location, since a line held at two locations is allocated at
 * both; each allocation made names the location its units are at.
 *
 * @since 0.1.0
 */
final readonly class Allocation {

	/**
	 * Checks and holds the allocation.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \InvalidArgumentException When an id or the quantity is below 1: a caller's bug.
	 *
	 * @param int      $orderLineId The order line the units are promised to.
	 * @param int      $variantId   The variant.
	 * @param int      $quantity    The units, 1 or more.
	 * @param int|null $locationId  Optional. The location the units are at. Default null: for a line still to be allocated.
	 */
	public function __construct(
		public int $orderLineId,
		public int $variantId,
		public int $quantity,
		public ?int $locationId = null
	) {
		if ( $orderLineId < 1 || $variantId < 1 || $quantity < 1 || ( null !== $locationId && $locationId < 1 ) ) {
			throw new \InvalidArgumentException( sprintf( 'An allocation promises 1 or more units of a variant to an order line, at a location of id 1 or more; line %d, variant %d and %d units is not one.', $orderLineId, $variantId, $quantity ) );
		}
	}
}
