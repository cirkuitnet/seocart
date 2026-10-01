<?php
/**
 * Allocation: the units of one variant an order line is promised
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
 * Owns one fact: what an allocation names. Its units count in the item's `allocated` until they
 * are posted or cancelled; while it is open the variant cannot be deleted.
 *
 * @since 0.1.0
 */
final readonly class Allocation {

	/**
	 * Checks and holds the allocation.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When an id or the quantity is below 1: a caller's bug.
	 *
	 * @param int $orderLineId The order line the units are promised to.
	 * @param int $variantId   The variant.
	 * @param int $quantity    The units, 1 or more.
	 */
	public function __construct(
		public int $orderLineId,
		public int $variantId,
		public int $quantity
	) {
		if ( $orderLineId < 1 || $variantId < 1 || $quantity < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'An allocation promises 1 or more units of a variant to an order line; line %d, variant %d and %d units is not one.', $orderLineId, $variantId, $quantity ) );
		}
	}
}
