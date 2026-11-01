<?php
/**
 * InventoryFulfilmentRouter: the stock module's router, as the calculation's port
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Infrastructure\Routing;

use SEOCart\Inventory\Application\FulfilmentRouting;
use SEOCart\Inventory\Application\LocationDirectory;
use SEOCart\Inventory\Application\RoutingContext;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Pricing\Application\FulfilmentRouter;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Pricing\Domain\Quote\RoutingProposal;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message names a value built by code, for the developer; it is never rendered.

/**
 * Answers the calculation's FulfilmentRouter with the stock module's own router, under the store's mode.
 *
 * Owns one fact: how a stock plan becomes a quoted proposal. This is the one pricing file that
 * imports the stock module: the port and the proposal it answers import nothing of it. The plan's
 * lines become the proposal's, the destination's public identifier is found in the cached
 * location directory, and the days a transfer adds are read from the store's setting only when
 * the plan needs one. The stock module's refusals pass through unchanged.
 *
 * @since 0.2.0
 */
final class InventoryFulfilmentRouter implements FulfilmentRouter {

	/**
	 * Creates the adapter. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param FulfilmentRouting $routing          The stock module's router.
	 * @param LocationDirectory $locations        The locations, cached for the request.
	 * @param \Closure          $transferLeadDays Returns the extra days a transfer adds, as the store tells shoppers.
	 *
	 * @phpstan-param \Closure(): int $transferLeadDays
	 */
	public function __construct(
		private FulfilmentRouting $routing,
		private LocationDirectory $locations,
		private \Closure $transferLeadDays
	) {
	}

	/**
	 * Proposes a plan for the units of each variant, under the store's mode.
	 *
	 * Reads the active locations (cached) and the units each has of the variants (one read); the
	 * lead-days setting only when the plan needs a transfer. The destination's identifier is looked
	 * up in the cached list again, after the plan: a location deleted by another request in between
	 * is gone from it, and the router is then asked once more, from the list as it is now.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the plan's destination is not in the directory twice in a row, which only a
	 *                         deletion between each plan and its lookup could do.
	 *
	 * @param array        $units The units of each variant, by variant id.
	 * @param RoutingScope $scope The market and the pickup location, as far as known.
	 * @return RoutingProposal The proposal.
	 *
	 * @phpstan-param array<int, int> $units
	 */
	public function propose( array $units, RoutingScope $scope ): RoutingProposal {
		$context     = new RoutingContext( $scope->marketId, $scope->pickupLocationId, null );
		$plan        = $this->routing->propose( $units, $context );
		$destination = $this->locations->find( $plan->destinationLocationId );

		if ( null === $destination ) {
			$plan        = $this->routing->propose( $units, $context );
			$destination = $this->locations->find( $plan->destinationLocationId )
				?? throw new \LogicException( sprintf( 'The router planned twice for a location the location directory then did not have, %d the second time.', $plan->destinationLocationId ) );
		}

		return new RoutingProposal(
			array_map(
				static fn( HoldLine $line ): array => array(
					'variant_id'  => $line->variantId,
					'location_id' => (int) $line->locationId,
					'quantity'    => $line->quantity,
				),
				$plan->lines
			),
			$plan->destinationLocationId,
			$destination->uuid,
			$plan->requiresTransfer,
			$plan->split,
			$plan->reason->value,
			$plan->requiresTransfer ? ( $this->transferLeadDays )() : null
		);
	}
}
