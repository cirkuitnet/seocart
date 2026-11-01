<?php
/**
 * FulfilmentRouter: the port a calculation asks where an order's units are held
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Application;

use SEOCart\Pricing\Domain\Quote\RoutingProposal;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Proposes where an order's units are held and the one location it is fulfilled from.
 *
 * Owns one fact: what the calculation needs of the stock module, as a port it declares. The
 * calculator asks it between the first phase and the quotes, for the first phase's lines, and
 * hands the proposal's destination to the shipping and tax quotes as their origin. The answer is
 * a proposal, never a claim: placement holds its lines later, and a claim may still refuse.
 *
 * @since 0.2.0
 */
interface FulfilmentRouter {

	/**
	 * Proposes a plan for the units of each variant.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException The stock module's refusals, unchanged: `stock.routing_refused` with
	 *                        `reason`, `variant_ids` and `available` when no plan the store's mode
	 *                        allows gives the order its units; `stock.no_fulfilling_location`;
	 *                        `stock.location_missing` or `stock.location_inactive` for a pickup
	 *                        location that is gone; `stock.item_missing`.
	 *
	 * @param array        $units The units of each variant, by variant id.
	 * @param RoutingScope $scope The market and the pickup location, as far as known.
	 * @return RoutingProposal The proposal.
	 *
	 * @phpstan-param array<int, int> $units
	 */
	public function propose( array $units, RoutingScope $scope ): RoutingProposal;
}
