<?php
/**
 * StockTarget: the on-hand count a merchant sets for a variant at a location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- An InvalidArgumentException names a value built by code, for the developer; it is never rendered.

/**
 * One stock cell to set: a variant's units on hand at a location, and the count the merchant read there.
 *
 * Owns one fact: what a cell asks. The count is set only while the item still has the count it
 * was read at, so a cell never undoes a change it did not see: the difference between the two is
 * the change it makes, and none when they are equal.
 *
 * @since 0.2.0
 */
final readonly class StockTarget {

	/**
	 * Holds a checked cell.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When an id is below 1 or a count is negative.
	 *
	 * @param int $variantId      The variant.
	 * @param int $locationId     The location.
	 * @param int $onHand         The units on hand to set, 0 or more.
	 * @param int $expectedOnHand The units on hand the cell was read at, 0 or more.
	 */
	public function __construct(
		public int $variantId,
		public int $locationId,
		public int $onHand,
		public int $expectedOnHand
	) {
		if ( $variantId < 1 || $locationId < 1 || $onHand < 0 || $expectedOnHand < 0 ) {
			throw new \InvalidArgumentException( sprintf( 'A stock cell names a variant and a location, 1 or more, and counts of 0 or more; not %d at %d, %d from %d.', $variantId, $locationId, $onHand, $expectedOnHand ) );
		}
	}

	/**
	 * Returns the change of on_hand the cell makes.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count to set less the count read; 0 when they are equal.
	 */
	public function delta(): int {
		return $this->onHand - $this->expectedOnHand;
	}
}
