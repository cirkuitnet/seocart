<?php
/**
 * Availability: the figure of each variant a shopper may be shown
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\StockRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Says how many units of each variant are for sale to a market's shoppers: a figure a hold can honour and the store's mode can fulfil.
 *
 * Owns one fact: the figure. It counts the active locations that fulfil the market's online
 * orders, each location's units at no fewer than zero. Under `split` and `transfer` an order may
 * take units from several of them, so the figure is their sum; under `refuse` an order ships from
 * one, so it is the most any one has. A variant whose stock is not counted has no limit, and its
 * figure is null (StockPolicy::sellsBeyondStock(), the rule the router reads too); a variant whose
 * stock is counted is sold from its units only, whatever its backorder policy, since a hold claims
 * only units that are there. A variant with no stock policy has nothing for sale. One read, never
 * cached, and never a decision: a hold decides with its own update.
 *
 * A placement routes its lines and holds each unit where it is, so every unit the figure counts
 * is one a placement can hold, at the location it is at.
 *
 * @since 0.2.0
 */
final class Availability {

	/**
	 * The locations.
	 *
	 * @since 0.2.0
	 *
	 * @var LocationDirectory
	 */
	private LocationDirectory $locations;

	/**
	 * The stock reads.
	 *
	 * @since 0.2.0
	 *
	 * @var StockRepository
	 */
	private StockRepository $stock;

	/**
	 * Returns the store's choice for orders no one location can ship whole.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): FulfilmentMode
	 */
	private \Closure $mode;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param LocationDirectory $locations The locations.
	 * @param StockRepository   $stock     The stock reads.
	 * @param callable          $mode      Returns the store's choice for orders no one location can ship whole (FulfilmentMode).
	 *
	 * @phpstan-param callable(): FulfilmentMode $mode
	 */
	public function __construct( LocationDirectory $locations, StockRepository $stock, callable $mode ) {
		$this->locations = $locations;
		$this->stock     = $stock;
		$this->mode      = \Closure::fromCallable( $mode );
	}

	/**
	 * Returns the units of each variant for sale to a market's shoppers.
	 *
	 * @since 0.2.0
	 *
	 * @param int[]    $variantIds The variants.
	 * @param int|null $marketId   The market, or null for any.
	 * @return array<int, int|null> Each variant's figure, by variant id: null for no limit.
	 */
	public function forSale( array $variantIds, ?int $marketId ): array {
		if ( array() === $variantIds ) {
			return array();
		}

		$largestOnly = FulfilmentMode::Refuse === ( $this->mode )();
		$figures     = $this->stock->saleFigures( array_values( $variantIds ), array_map( static fn( Location $location ): int => $location->id, $this->locations->fulfilling( $marketId ) ) );
		$forSale     = array();

		foreach ( $variantIds as $variantId ) {
			$figure = $figures[ $variantId ] ?? null;

			if ( null === $figure ) {
				$forSale[ $variantId ] = 0;
			} elseif ( $figure['policy']->sellsBeyondStock() ) {
				$forSale[ $variantId ] = null;
			} else {
				$forSale[ $variantId ] = $largestOnly ? $figure['largest'] : $figure['total'];
			}
		}

		return $forSale;
	}
}
