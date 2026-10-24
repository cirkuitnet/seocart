<?php
/**
 * HoldLine: one line of a stock hold, at its location
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
 * A request to hold a number of units of one variant, at one location or at the store's default one.
 *
 * Owns one fact: that a held quantity is at least one unit, at a real location. A line of zero or
 * fewer units, or at a location id below 1, is a caller's bug, not a client's error, so it is
 * refused here with an \InvalidArgumentException before any statement runs, and every hold row
 * therefore has a positive quantity. A line that names no location is held at the store's default
 * location, which is where a store with one location keeps all of its stock; a held line always
 * names the location it was held at.
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
	 * The location whose units are held, or null for the store's default location.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	public ?int $locationId;

	/**
	 * Records a line.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @throws \InvalidArgumentException When the variant id, the quantity or the location id is below 1.
	 *
	 * @param int      $variantId  The variant, 1 or more.
	 * @param int      $quantity   The units to hold, 1 or more.
	 * @param int|null $locationId Optional. The location, 1 or more. Default null, the store's default location.
	 */
	public function __construct( int $variantId, int $quantity, ?int $locationId = null ) {
		if ( $variantId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold line needs a variant id of 1 or more; %d was given.', $variantId ) );
		}

		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold line holds at least one unit; %d was asked for variant %d.', $quantity, $variantId ) );
		}

		if ( null !== $locationId && $locationId < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A hold line\'s location id is 1 or more, or null for the default location; %d was given.', $locationId ) );
		}

		$this->variantId  = $variantId;
		$this->quantity   = $quantity;
		$this->locationId = $locationId;
	}
}
