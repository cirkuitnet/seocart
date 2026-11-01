<?php
/**
 * OneLocationRouter: a router for a store with one location, which plans every unit there
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Pricing\Application\FulfilmentRouter;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Pricing\Domain\Quote\RoutingProposal;

defined( 'ABSPATH' ) || exit;

/**
 * Plans every order whole at one location, with no transfer: what a one-location store's router answers, for tests that price a checkout without stock tables.
 *
 * @since 0.2.0
 */
final class OneLocationRouter implements FulfilmentRouter {

	/**
	 * The location every order is planned at.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LOCATION = 1;

	/**
	 * Plans the units whole at the one location.
	 *
	 * @since 0.2.0
	 *
	 * @param array        $units The units of each variant, by variant id.
	 * @param RoutingScope $scope The scope, which changes nothing here.
	 * @return RoutingProposal The plan.
	 *
	 * @phpstan-param array<int, int> $units
	 */
	public function propose( array $units, RoutingScope $scope ): RoutingProposal {
		$lines = array();

		foreach ( $units as $variantId => $quantity ) {
			$lines[] = array(
				'variant_id'  => $variantId,
				'location_id' => self::LOCATION,
				'quantity'    => $quantity,
			);
		}

		return new RoutingProposal( $lines, self::LOCATION, '00000000-0000-4000-8000-000000000001', false, false, 'whole_order', null );
	}
}
