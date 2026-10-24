<?php
/**
 * RoutingPlan: where an order's units are held, and where the order ends
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

use SEOCart\Inventory\Domain\HoldLine;

defined( 'ABSPATH' ) || exit;

/**
 * The router's answer: the units of each variant to hold at each location, the one location the order is fulfilled from, and whether units must travel there.
 *
 * Owns one fact: a plan, as a hold claims it and an order records it. Its lines name where the
 * units physically are, one per variant and location, ascending by variant then location, so a
 * hold claims each row where its units are and never more than a row has. The destination is the
 * order's one fulfilment location: the location it ships from or is collected at. A split plan
 * ships from several; a plan that requires a transfer ships once, from the destination, after
 * the units held elsewhere are brought there.
 *
 * @since 0.2.0
 */
final readonly class RoutingPlan {

	/**
	 * The units to hold, one line per variant and location, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @var list<HoldLine>
	 */
	public array $lines;

	/**
	 * Records a plan.
	 *
	 * @since 0.2.0
	 *
	 * @param HoldLine[]    $lines                 The units to hold, each with its location.
	 * @param int           $destinationLocationId The one location the order is fulfilled from.
	 * @param bool          $requiresTransfer      Whether units held elsewhere must be brought to it before the order ships.
	 * @param bool          $split                 Whether the order ships from more than one location.
	 * @param RoutingReason $reason                The rule that made the plan.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	public function __construct(
		array $lines,
		public int $destinationLocationId,
		public bool $requiresTransfer,
		public bool $split,
		public RoutingReason $reason
	) {
		$this->lines = $lines;
	}

	/**
	 * Returns the locations the plan holds units at, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @return list<int> The location ids.
	 */
	public function locations(): array {
		$locations = array_values( array_unique( array_map( static fn( HoldLine $line ): int => (int) $line->locationId, $this->lines ) ) );

		sort( $locations );

		return $locations;
	}
}
