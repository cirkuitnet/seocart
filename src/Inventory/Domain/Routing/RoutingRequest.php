<?php
/**
 * RoutingRequest: everything the router decides from, read before it runs
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages report a caller's programming error to the developer; they are never HTML.

/**
 * The lines of an order, the active locations with what each has of them, the store's mode, and the location the order must end at, when it must.
 *
 * Owns one fact: what a plan depends on. The router reads nothing else, so the same request
 * always gives the same plan. The candidates are every active location: those that fulfil online
 * orders may ship, and in `transfer` mode any of them may send units. A pickup location makes the
 * order the shopper's to collect there; a destination, such as the location an order was already
 * routed to, makes it the place the units are brought to.
 *
 * @since 0.2.0
 */
final readonly class RoutingRequest {

	/**
	 * The units of each variant, by variant id, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, int>
	 */
	public array $lines;

	/**
	 * The active locations.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Candidate>
	 */
	public array $candidates;

	/**
	 * Records a request.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When there is no line, a variant id or a quantity is below 1, or a candidate is not one: a caller's bug.
	 *
	 * @param array          $lines                 The units of each variant, by variant id.
	 * @param array          $candidates            The active locations.
	 * @param FulfilmentMode $mode                  What happens when no one location can ship the whole order.
	 * @param int|null       $pickupLocationId      Optional. Where the shopper collects the order. Default null, none.
	 * @param int|null       $destinationLocationId Optional. Where the order must end. Default null, as the mode chooses.
	 *
	 * @phpstan-param array<int, int>      $lines
	 * @phpstan-param list<Candidate>      $candidates
	 */
	public function __construct(
		array $lines,
		array $candidates,
		public FulfilmentMode $mode,
		public ?int $pickupLocationId = null,
		public ?int $destinationLocationId = null
	) {
		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'A routing request has at least one line.' );
		}

		foreach ( $lines as $variantId => $quantity ) {
			if ( $variantId < 1 || $quantity < 1 ) {
				throw new \InvalidArgumentException( sprintf( 'A routing line is 1 or more units of a variant of id 1 or more; %d of variant %d is not one.', $quantity, $variantId ) );
			}
		}

		foreach ( $candidates as $candidate ) {
			// @phpstan-ignore instanceof.alwaysTrue (A caller builds the list by hand; a stray value must be refused, not routed to.)
			if ( ! $candidate instanceof Candidate ) {
				throw new \InvalidArgumentException( 'A routing candidate is a Candidate.' );
			}
		}

		ksort( $lines );

		$this->lines      = $lines;
		$this->candidates = $candidates;
	}
}
