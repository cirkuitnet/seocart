<?php
/**
 * HoldLine: one variant and the units a checkout asks to hold
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A refused line is a caller's programming error, reported to the developer, never HTML; this class may not call WordPress.

/**
 * A request to hold a number of units of one variant.
 *
 * Owns one fact: that a held quantity is at least one unit. A line of zero or fewer units is a
 * caller's bug, not a client's error, so it is refused here with an \InvalidArgumentException
 * before any statement runs, and every hold row therefore has a positive quantity.
 *
 * @since 0.1.0
 */
final readonly class HoldLine {

	/**
	 * The variant.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantId;

	/**
	 * The units to hold, 1 or more.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $quantity;

	/**
	 * Records a line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the variant id or the quantity is below 1.
	 *
	 * @param int $variantId The variant, 1 or more.
	 * @param int $quantity  The units to hold, 1 or more.
	 */
	public function __construct( int $variantId, int $quantity ) {
		if ( $variantId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold line needs a variant id of 1 or more; %d was given.', $variantId ) );
		}

		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold line holds at least one unit; %d was asked for variant %d.', $quantity, $variantId ) );
		}

		$this->variantId = $variantId;
		$this->quantity  = $quantity;
	}
}
