<?php
/**
 * Candidate: one active location as the router weighs it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never HTML.

/**
 * An active location, with what it has of each variant of an order, read before the router runs.
 *
 * Owns one fact: what one location can give an order. A variant the location has no row of has
 * nothing there; a negative figure, left by an adjustment below what is promised, counts as
 * nothing. A variant that may be sold beyond what is available, because its stock is not counted
 * or it may be backordered, can be given anywhere in any quantity.
 *
 * @since 0.2.0
 */
final readonly class Candidate {

	/**
	 * Units available of each variant, by variant id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, int>
	 */
	public array $available;

	/**
	 * The variants that may be sold beyond what is available, by variant id.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, bool>
	 */
	public array $unlimited;

	/**
	 * Records a candidate.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is below 1: a caller's bug.
	 *
	 * @param int   $id            The location.
	 * @param int   $priority      Its routing priority: the lowest is tried first.
	 * @param bool  $fulfilsOnline Whether it ships online orders; one that does not may still send units to one that does.
	 * @param array $available     Units available of each variant, by variant id.
	 * @param array $unlimited     The variants that may be sold beyond what is available, by variant id: untracked, or backorder allowed.
	 *
	 * @phpstan-param array<int, int>  $available
	 * @phpstan-param array<int, bool> $unlimited
	 */
	public function __construct(
		public int $id,
		public int $priority,
		public bool $fulfilsOnline,
		array $available,
		array $unlimited = array()
	) {
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( sprintf( 'A candidate is a location of id 1 or more; %d was given.', $id ) );
		}

		$this->available = $available;
		$this->unlimited = $unlimited;
	}

	/**
	 * Returns the units of a variant the location can give, never below 0.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return int The units.
	 */
	public function has( int $variantId ): int {
		return max( 0, $this->available[ $variantId ] ?? 0 );
	}

	/**
	 * Tells whether the location can give a line whole.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @param int $quantity  The units.
	 * @return bool True when it has that many, or the variant may be sold beyond what is available.
	 */
	public function covers( int $variantId, int $quantity ): bool {
		return $this->isUnlimited( $variantId ) || $this->has( $variantId ) >= $quantity;
	}

	/**
	 * Tells whether a variant may be sold beyond what is available.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return bool True when untracked or backorder allowed.
	 */
	public function isUnlimited( int $variantId ): bool {
		return $this->unlimited[ $variantId ] ?? false;
	}
}
