<?php
/**
 * StockLines: the units a stock event names, per variant and per item
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Writes and checks the lines of a stock event that names several items: each variant once with all its units, and each item with its own.
 *
 * Owns one fact: how a hold's, an allocation's or a release's units are listed, since an event's
 * payload holds only flat lists. `variant_ids` and `quantities` name each variant once, ascending,
 * with its units at every location together, as they did before stock was kept per location;
 * `location_variant_ids`, `location_ids` and `location_quantities` name each item, a variant at a
 * location, ascending by variant then location, with its own units. The units of a variant's
 * items add up to the variant's. An event recorded before locations has no item lists.
 *
 * @since 0.2.0
 */
final class StockLines {

	/**
	 * Lists items' units the way an event names them.
	 *
	 * @since 0.2.0
	 *
	 * @param array $items Each item's variant, location and units, in any order; an item listed twice has its units added.
	 * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>, 4: list<int>} The variants and each one's
	 *         units, ascending; then each item's variant, location and units, ascending by variant then location.
	 *
	 * @phpstan-param list<array{0: int, 1: int, 2: int}> $items
	 */
	public static function of( array $items ): array {
		$units = array();

		foreach ( $items as list( $variantId, $locationId, $quantity ) ) {
			$units[ $variantId ][ $locationId ] = ( $units[ $variantId ][ $locationId ] ?? 0 ) + $quantity;
		}

		ksort( $units );

		$lines = array( array(), array(), array(), array(), array() );

		foreach ( $units as $variantId => $byLocation ) {
			ksort( $byLocation );

			$lines[0][] = $variantId;
			$lines[1][] = array_sum( $byLocation );

			foreach ( $byLocation as $locationId => $quantity ) {
				$lines[2][] = $variantId;
				$lines[3][] = $locationId;
				$lines[4][] = $quantity;
			}
		}

		return $lines;
	}

	/**
	 * Tells whether an event's lines agree: at least one variant, each once with its units, and either no item list or three of one length whose units add up to each variant's.
	 *
	 * @since 0.2.0
	 *
	 * @param int[] $variantIds         The variants.
	 * @param int[] $quantities         The units of each.
	 * @param int[] $locationVariantIds Each item's variant.
	 * @param int[] $locationIds        Each item's location.
	 * @param int[] $locationQuantities Each item's units.
	 * @return bool True when they agree.
	 */
	public static function agree( array $variantIds, array $quantities, array $locationVariantIds, array $locationIds, array $locationQuantities ): bool {
		if ( array() === $variantIds || count( $variantIds ) !== count( $quantities ) || count( array_unique( $variantIds ) ) !== count( $variantIds ) ) {
			return false;
		}

		if ( count( $locationIds ) !== count( $locationVariantIds ) || count( $locationQuantities ) !== count( $locationVariantIds ) ) {
			return false;
		}

		if ( array() === $locationVariantIds ) {
			return true;
		}

		$byItems = array();

		foreach ( $locationVariantIds as $index => $variantId ) {
			$byItems[ $variantId ] = ( $byItems[ $variantId ] ?? 0 ) + $locationQuantities[ $index ];
		}

		$byVariant = array_combine( $variantIds, $quantities );

		ksort( $byItems );
		ksort( $byVariant );

		return $byVariant === $byItems;
	}
}
