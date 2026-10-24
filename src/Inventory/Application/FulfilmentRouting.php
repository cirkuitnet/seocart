<?php
/**
 * FulfilmentRouting: reads what the router needs, then lets it plan an order
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Inventory\Domain\Location;
use SEOCart\Inventory\Domain\Routing\Candidate;
use SEOCart\Inventory\Domain\Routing\FulfilmentMode;
use SEOCart\Inventory\Domain\Routing\Router;
use SEOCart\Inventory\Domain\Routing\RoutingPlan;
use SEOCart\Inventory\Domain\Routing\RoutingRefused;
use SEOCart\Inventory\Domain\Routing\RoutingRequest;
use SEOCart\Inventory\Domain\StockRepository;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Proposes where an order's units are held: the active locations from the directory, what each has of the order in one read, then the pure router.
 *
 * Owns one fact: the I/O around routing. Every read happens before the router runs, and the plan
 * decides nothing final: each of its lines is claimed later by one conditional update, which may
 * still refuse. The availability read is a plain read: run inside a unit of work at READ
 * COMMITTED, as the settlement's claim of a variant whose hold is gone runs it, it sees the rows
 * committed when it is sent, and the claims that follow re-decide under each row's lock what it
 * saw.
 *
 * @since 0.2.0
 */
final class FulfilmentRouting {

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
	 * The rules.
	 *
	 * @since 0.2.0
	 *
	 * @var Router
	 */
	private Router $router;

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
		$this->router    = new Router();
	}

	/**
	 * Plans an order's units under the store's mode, or under another the caller names.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.no_fulfilling_location` when no active location fulfils online orders and the
	 *                        context names no pickup or destination; `stock.location_missing` or `stock.location_inactive`
	 *                        when its pickup location or its destination does not exist or is deactivated, since units are
	 *                        brought only to an active location; `stock.item_missing` when a variant has no stock policy;
	 *                        `stock.routing_refused` when no plan the mode allows gives the order its units.
	 * @throws \InvalidArgumentException When there is no line, or a variant id or a quantity is below 1.
	 *
	 * @param array               $lines   The units of each variant, by variant id.
	 * @param RoutingContext      $context The market, the pickup location and the destination, as far as known.
	 * @param FulfilmentMode|null $mode    Optional. The mode to plan under. Default null, the store's.
	 * @return RoutingPlan The plan.
	 *
	 * @phpstan-param array<int, int> $lines
	 */
	public function propose( array $lines, RoutingContext $context, ?FulfilmentMode $mode = null ): RoutingPlan {
		$active     = $this->locations->active();
		$fulfilling = array_map( static fn( Location $location ): int => $location->id, $this->locations->fulfilling( $context->marketId ) );

		if ( null !== $context->pickupLocationId ) {
			$this->requireActive( $context->pickupLocationId );
		} elseif ( array() === $fulfilling && null === $context->destinationLocationId ) {
			CodedException::raise( InventoryError::NoFulfillingLocation );
		}

		if ( null !== $context->destinationLocationId ) {
			$this->requireActive( $context->destinationLocationId );
		}

		$figures = $this->stock->availability( array_keys( $lines ), array_map( static fn( Location $location ): int => $location->id, $active ) );

		foreach ( array_keys( $lines ) as $variantId ) {
			if ( ! isset( $figures['policies'][ $variantId ] ) ) {
				CodedException::raise( InventoryError::ItemMissing, array( 'variant_id' => $variantId ) );
			}
		}

		$unlimited = array();

		foreach ( $figures['policies'] as $variantId => $policy ) {
			$unlimited[ $variantId ] = $policy->sellsBeyondStock();
		}

		$candidates = array();

		foreach ( $active as $location ) {
			$candidates[] = new Candidate( $location->id, $location->priority, in_array( $location->id, $fulfilling, true ), $figures['available'][ $location->id ] ?? array(), $unlimited );
		}

		try {
			return $this->router->plan( new RoutingRequest( $lines, $candidates, $mode ?? ( $this->mode )(), $context->pickupLocationId, $context->destinationLocationId ) );
		} catch ( RoutingRefused $refused ) {
			CodedException::raise(
				InventoryError::RoutingRefused,
				array(),
				array(
					'reason'      => $refused->reason,
					'variant_ids' => $refused->variantIds,
					'available'   => $refused->available,
				)
			);
		}
	}

	/**
	 * Refuses a pickup or a destination at a location that does not exist or is deactivated.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `stock.location_missing` or `stock.location_inactive`.
	 *
	 * @param int $locationId The pickup location, or the destination.
	 */
	private function requireActive( int $locationId ): void {
		$location = $this->locations->find( $locationId );

		if ( null === $location ) {
			CodedException::raise( InventoryError::LocationMissing, array( 'location_id' => $locationId ) );
		}

		if ( ! $location->active ) {
			CodedException::raise( InventoryError::LocationInactive, array( 'location_id' => $locationId ) );
		}
	}
}
