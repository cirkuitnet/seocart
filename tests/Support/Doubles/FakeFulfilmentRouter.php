<?php
/**
 * FakeFulfilmentRouter: a router that answers one scripted proposal and records what it was asked
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
 * Answers every request with the proposal it was given, or throws the failure it was given.
 *
 * @since 0.2.0
 */
final class FakeFulfilmentRouter implements FulfilmentRouter {

	/**
	 * The units and the scope of each request, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{units: array<int, int>, scope: RoutingScope}>
	 */
	public array $asked = array();

	/**
	 * Holds the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param RoutingProposal $proposal What every request is answered.
	 * @param \Throwable|null $failure  Optional. What a request throws instead. Default null.
	 */
	public function __construct( private RoutingProposal $proposal, private ?\Throwable $failure = null ) {
	}

	/**
	 * Records the request and answers it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \Throwable The scripted failure.
	 *
	 * @param array        $units The units of each variant, by variant id.
	 * @param RoutingScope $scope The scope.
	 * @return RoutingProposal The scripted proposal.
	 *
	 * @phpstan-param array<int, int> $units
	 */
	public function propose( array $units, RoutingScope $scope ): RoutingProposal {
		$this->asked[] = array(
			'units' => $units,
			'scope' => $scope,
		);

		if ( null !== $this->failure ) {
			throw $this->failure;
		}

		return $this->proposal;
	}

	/**
	 * Returns a proposal for one variant split over two locations, the second needing a transfer to the first.
	 *
	 * @since 0.2.0
	 *
	 * @param int $variantId The variant.
	 * @return RoutingProposal One unit at location 7 and one at location 9, fulfilled from 7 after a 3-day transfer.
	 */
	public static function transferOf( int $variantId ): RoutingProposal {
		return new RoutingProposal(
			array(
				array(
					'variant_id'  => $variantId,
					'location_id' => 9,
					'quantity'    => 1,
				),
				array(
					'variant_id'  => $variantId,
					'location_id' => 7,
					'quantity'    => 1,
				),
			),
			7,
			'00000000-0000-4000-8000-000000000007',
			true,
			false,
			'transfer',
			3
		);
	}
}
