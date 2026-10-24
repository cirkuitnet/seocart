<?php
/**
 * ReclaimedRows: what a reclaim of one item's hold rows gave back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The result of giving claimed hold rows back to their item: the rows, or why there were none.
 *
 * Owns one fact: the three ways a reclaim ends. The item, one variant at one location, was not
 * there, so nothing was locked or claimed; the item's `held` was lower than the rows claimed, so
 * nothing was given back and the projection is corrupt; or the rows listed were given back and
 * deleted, which may be none.
 *
 * @since 0.1.0
 */
final readonly class ReclaimedRows {

	/**
	 * The item's variant.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $variantId;

	/**
	 * The item's location.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public int $locationId;

	/**
	 * Whether the item exists; false means nothing was locked or claimed.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $itemFound;

	/**
	 * Whether `held` covered the claimed rows; false means nothing was given back.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $projectionHeld;

	/**
	 * The rows given back and deleted, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{id: int, hold_group: string, quantity: int, expires_at: string}>
	 */
	public array $rows;

	/**
	 * Records a result. Use the named constructors.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int   $variantId      The item's variant.
	 * @param int   $locationId     The item's location.
	 * @param bool  $itemFound      Whether it exists.
	 * @param bool  $projectionHeld Whether `held` covered the claimed rows.
	 * @param array $rows           The rows given back.
	 *
	 * @phpstan-param list<array{id: int, hold_group: string, quantity: int, expires_at: string}> $rows
	 */
	private function __construct( int $variantId, int $locationId, bool $itemFound, bool $projectionHeld, array $rows ) {
		$this->variantId      = $variantId;
		$this->locationId     = $locationId;
		$this->itemFound      = $itemFound;
		$this->projectionHeld = $projectionHeld;
		$this->rows           = $rows;
	}

	/**
	 * Records that the item does not exist.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return self The result.
	 */
	public static function itemMissing( int $variantId, int $locationId ): self {
		return new self( $variantId, $locationId, false, true, array() );
	}

	/**
	 * Records that `held` was lower than the rows claimed, so nothing was given back.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int $variantId  The item's variant.
	 * @param int $locationId The item's location.
	 * @return self The result.
	 */
	public static function corrupt( int $variantId, int $locationId ): self {
		return new self( $variantId, $locationId, true, false, array() );
	}

	/**
	 * Records the rows given back.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The location.
	 *
	 * @param int   $variantId  The item's variant.
	 * @param int   $locationId The item's location.
	 * @param array $rows       The rows, in id order; empty when none was claimed.
	 * @return self The result.
	 *
	 * @phpstan-param list<array{id: int, hold_group: string, quantity: int, expires_at: string}> $rows
	 */
	public static function of( int $variantId, int $locationId, array $rows ): self {
		return new self( $variantId, $locationId, true, true, $rows );
	}
}
