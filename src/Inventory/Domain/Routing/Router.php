<?php
/**
 * Router: decides where an order's units are held, from figures read before it runs
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain\Routing;

use SEOCart\Inventory\Domain\HoldLine;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never HTML.

/**
 * Plans an order's units, location by location: pure, with no I/O, so the same request always gives the same plan.
 *
 * Owns one fact: the routing rules. Locations are tried in ascending priority, ties by id,
 * whatever order they are given in.
 *
 * 1. A pickup order ends at its pickup location, whatever the priorities. A line the location
 *    cannot give is refused, with the location's own figure; in `transfer` mode its missing units
 *    are claimed at the other active locations instead, and brought there.
 * 2. Otherwise the first location that fulfils online orders and can give every line takes the
 *    whole order, in every mode.
 * 3. When none can, `split` takes each line from the fulfilling locations in turn, as much as
 *    each has, and the order is fulfilled from the first that gave anything; a line they cannot
 *    give between them is refused with the sum they have.
 * 4. `transfer` ends the order at the highest-priority fulfilling location, always, or at the
 *    destination the request names: each line is taken from it first, then from every other
 *    active location, fulfilling or not, in turn; a line they cannot give between them is refused
 *    with the sum over the active locations.
 * 5. `refuse` refuses: `quantity` for the first line no one fulfilling location can give, with
 *    the most any one has; otherwise `combination`, naming the lines the location that can give
 *    the most of the order cannot.
 *
 * A variant that may be sold beyond what is available, because its stock is not counted or it may
 * be backordered, is never short: what is not available of it goes to the order's destination.
 * A request that names a destination is planned with the units brought to it, so a variant
 * claimed afresh for an order already routed ends where the order does.
 *
 * @since 0.2.0
 */
final class Router {

	/**
	 * Plans a request.
	 *
	 * @since 0.2.0
	 *
	 * @throws RoutingRefused            When no plan the mode allows gives the order its units.
	 * @throws \InvalidArgumentException When a plan in `transfer` mode has no destination: no location fulfils online orders and none is named.
	 *
	 * @param RoutingRequest $request The lines, the candidates and the mode.
	 * @return RoutingPlan The plan.
	 */
	public function plan( RoutingRequest $request ): RoutingPlan {
		$candidates = self::ordered( $request->candidates );

		if ( null !== $request->pickupLocationId ) {
			return self::pickup( $request, $candidates );
		}

		$fulfilling = array_values( array_filter( $candidates, static fn( Candidate $candidate ): bool => $candidate->fulfilsOnline ) );

		if ( FulfilmentMode::Transfer !== $request->mode || null === $request->destinationLocationId ) {
			foreach ( $fulfilling as $candidate ) {
				if ( self::coversAll( $candidate, $request->lines ) ) {
					return self::plannedAt( $request->lines, $candidate->id, RoutingReason::WholeOrder );
				}
			}
		}

		return match ( $request->mode ) {
			FulfilmentMode::Split    => self::split( $request->lines, $fulfilling ),
			FulfilmentMode::Transfer => self::transfer( $request->lines, $candidates, $request->destinationLocationId ?? self::highestPriority( $fulfilling ), RoutingReason::Transfer ),
			FulfilmentMode::Refuse   => throw self::refusal( $request->lines, $fulfilling ),
		};
	}

	/**
	 * Plans a pickup order at its location.
	 *
	 * @since 0.2.0
	 *
	 * @throws RoutingRefused When a line is short and the mode does not bring units there.
	 *
	 * @param RoutingRequest $request    The request, with its pickup location.
	 * @param Candidate[]    $candidates The candidates, in order.
	 * @return RoutingPlan The plan.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function pickup( RoutingRequest $request, array $candidates ): RoutingPlan {
		$location = (int) $request->pickupLocationId;

		if ( FulfilmentMode::Transfer === $request->mode ) {
			return self::transfer( $request->lines, $candidates, $location, RoutingReason::Pickup );
		}

		$at = self::find( $candidates, $location );

		foreach ( $request->lines as $variantId => $quantity ) {
			if ( null === $at || ! $at->covers( $variantId, $quantity ) ) {
				throw RoutingRefused::quantity( $variantId, $quantity, null === $at ? 0 : $at->has( $variantId ) );
			}
		}

		return self::plannedAt( $request->lines, $location, RoutingReason::Pickup );
	}

	/**
	 * Takes each line from the fulfilling locations in turn, as much as each has.
	 *
	 * @since 0.2.0
	 *
	 * @throws RoutingRefused When a line is more than the fulfilling locations have between them.
	 *
	 * @param array<int, int> $lines      The units of each variant.
	 * @param Candidate[]     $fulfilling The fulfilling candidates, in order.
	 * @return RoutingPlan The plan.
	 *
	 * @phpstan-param list<Candidate> $fulfilling
	 */
	private static function split( array $lines, array $fulfilling ): RoutingPlan {
		$units       = array();
		$unlimited   = array();
		$destination = null;

		foreach ( $lines as $variantId => $quantity ) {
			$remaining = self::take( $units, $variantId, $quantity, $fulfilling );

			if ( 0 === $remaining ) {
				continue;
			}

			if ( ! self::isUnlimited( $fulfilling, $variantId ) ) {
				throw RoutingRefused::quantity( $variantId, $quantity, self::sum( $fulfilling, $variantId ) );
			}

			$unlimited[ $variantId ] = $remaining;
		}

		// The order is fulfilled from the first location that gave anything, and what may be sold beyond what is available goes there.
		foreach ( $fulfilling as $candidate ) {
			foreach ( $units as $byLocation ) {
				if ( isset( $byLocation[ $candidate->id ] ) ) {
					$destination = $candidate->id;

					break 2;
				}
			}
		}

		$destination ??= self::highestPriority( $fulfilling );

		foreach ( $unlimited as $variantId => $remaining ) {
			$units[ $variantId ][ $destination ] = ( $units[ $variantId ][ $destination ] ?? 0 ) + $remaining;
		}

		$plan = self::lines( $units );

		return new RoutingPlan( $plan, $destination, false, count( self::locationsOf( $plan ) ) > 1, RoutingReason::Split );
	}

	/**
	 * Takes each line from its destination first, then from every other active location in turn, and brings the units there.
	 *
	 * @since 0.2.0
	 *
	 * @throws RoutingRefused When a line is more than the active locations have between them.
	 *
	 * @param array<int, int> $lines       The units of each variant.
	 * @param Candidate[]     $candidates  Every active candidate, in order.
	 * @param int             $destination The location the order ends at.
	 * @param RoutingReason   $reason      The rule that made the plan.
	 * @return RoutingPlan The plan.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function transfer( array $lines, array $candidates, int $destination, RoutingReason $reason ): RoutingPlan {
		$at      = self::find( $candidates, $destination );
		$sources = array_merge( null === $at ? array() : array( $at ), array_values( array_filter( $candidates, static fn( Candidate $candidate ): bool => $candidate->id !== $destination ) ) );
		$units   = array();

		foreach ( $lines as $variantId => $quantity ) {
			$remaining = self::take( $units, $variantId, $quantity, $sources );

			if ( 0 === $remaining ) {
				continue;
			}

			if ( ! self::isUnlimited( $candidates, $variantId ) ) {
				throw RoutingRefused::quantity( $variantId, $quantity, self::sum( $candidates, $variantId ) );
			}

			$units[ $variantId ][ $destination ] = ( $units[ $variantId ][ $destination ] ?? 0 ) + $remaining;
		}

		$plan = self::lines( $units );

		return new RoutingPlan( $plan, $destination, array( $destination ) !== self::locationsOf( $plan ), false, $reason );
	}

	/**
	 * Builds the refusal of an order no one fulfilling location can ship whole.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $lines      The units of each variant.
	 * @param Candidate[]     $fulfilling The fulfilling candidates, in order.
	 * @return RoutingRefused The refusal: `quantity` for the first line no one location can give, else `combination`.
	 *
	 * @phpstan-param list<Candidate> $fulfilling
	 */
	private static function refusal( array $lines, array $fulfilling ): RoutingRefused {
		foreach ( $lines as $variantId => $quantity ) {
			$largest = 0;
			$covered = false;

			foreach ( $fulfilling as $candidate ) {
				$largest = max( $largest, $candidate->has( $variantId ) );
				$covered = $covered || $candidate->covers( $variantId, $quantity );
			}

			if ( ! $covered ) {
				return RoutingRefused::quantity( $variantId, $quantity, $largest );
			}
		}

		$missing = null;

		// The location that can give the most of the order, the first on a tie, names the lines that cannot travel with the rest.
		foreach ( $fulfilling as $candidate ) {
			$short = array();

			foreach ( $lines as $variantId => $quantity ) {
				if ( ! $candidate->covers( $variantId, $quantity ) ) {
					$short[] = $variantId;
				}
			}

			if ( null === $missing || count( $short ) < count( $missing ) ) {
				$missing = $short;
			}
		}

		return RoutingRefused::combination( $missing ?? array_keys( $lines ) );
	}

	/**
	 * Takes as much of a line as each source has, in turn, and adds it to the plan's units.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<int, int>> $units     The units planned so far, by variant and location; the line's are added.
	 * @param int                         $variantId The variant.
	 * @param int                         $quantity  The units the line needs.
	 * @param Candidate[]                 $sources   The locations to take from, in turn.
	 * @return int The units no source had.
	 *
	 * @phpstan-param list<Candidate> $sources
	 */
	private static function take( array &$units, int $variantId, int $quantity, array $sources ): int {
		$remaining = $quantity;

		foreach ( $sources as $source ) {
			$taken = min( $remaining, $source->has( $variantId ) );

			if ( $taken > 0 ) {
				$units[ $variantId ][ $source->id ] = ( $units[ $variantId ][ $source->id ] ?? 0 ) + $taken;
				$remaining                         -= $taken;
			}

			if ( 0 === $remaining ) {
				break;
			}
		}

		return $remaining;
	}

	/**
	 * Builds the plan that holds every line at one location.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, int> $lines    The units of each variant.
	 * @param int             $location The location.
	 * @param RoutingReason   $reason   The rule that made the plan.
	 * @return RoutingPlan The plan.
	 */
	private static function plannedAt( array $lines, int $location, RoutingReason $reason ): RoutingPlan {
		$plan = array();

		foreach ( $lines as $variantId => $quantity ) {
			$plan[] = new HoldLine( $variantId, $quantity, $location );
		}

		return new RoutingPlan( $plan, $location, false, false, $reason );
	}

	/**
	 * Turns the units planned by variant and location into hold lines, ascending by variant then location.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, array<int, int>> $units The units, by variant and location.
	 * @return list<HoldLine> The lines.
	 */
	private static function lines( array $units ): array {
		$lines = array();

		ksort( $units );

		foreach ( $units as $variantId => $byLocation ) {
			ksort( $byLocation );

			foreach ( $byLocation as $locationId => $quantity ) {
				$lines[] = new HoldLine( $variantId, $quantity, $locationId );
			}
		}

		return $lines;
	}

	/**
	 * Returns the locations hold lines name, ascending.
	 *
	 * @since 0.2.0
	 *
	 * @param HoldLine[] $lines The lines.
	 * @return list<int> The locations.
	 *
	 * @phpstan-param list<HoldLine> $lines
	 */
	private static function locationsOf( array $lines ): array {
		$locations = array_values( array_unique( array_map( static fn( HoldLine $line ): int => (int) $line->locationId, $lines ) ) );

		sort( $locations );

		return $locations;
	}

	/**
	 * Tells whether a candidate can give every line whole.
	 *
	 * @since 0.2.0
	 *
	 * @param Candidate       $candidate The candidate.
	 * @param array<int, int> $lines     The units of each variant.
	 * @return bool True when it can.
	 */
	private static function coversAll( Candidate $candidate, array $lines ): bool {
		foreach ( $lines as $variantId => $quantity ) {
			if ( ! $candidate->covers( $variantId, $quantity ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a variant may be sold beyond what is available: its policy is the same at every location.
	 *
	 * @since 0.2.0
	 *
	 * @param Candidate[] $candidates The candidates.
	 * @param int         $variantId  The variant.
	 * @return bool True when any candidate says so.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function isUnlimited( array $candidates, int $variantId ): bool {
		foreach ( $candidates as $candidate ) {
			if ( $candidate->isUnlimited( $variantId ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds up what candidates have of a variant.
	 *
	 * @since 0.2.0
	 *
	 * @param Candidate[] $candidates The candidates.
	 * @param int         $variantId  The variant.
	 * @return int The units, never below 0 at any one location.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function sum( array $candidates, int $variantId ): int {
		return array_sum( array_map( static fn( Candidate $candidate ): int => $candidate->has( $variantId ), $candidates ) );
	}

	/**
	 * Returns the first fulfilling candidate's id: the highest-priority location that ships online orders.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When there is none.
	 *
	 * @param Candidate[] $fulfilling The fulfilling candidates, in order.
	 * @return int The location.
	 *
	 * @phpstan-param list<Candidate> $fulfilling
	 */
	private static function highestPriority( array $fulfilling ): int {
		if ( ! isset( $fulfilling[0] ) ) {
			throw new \InvalidArgumentException( 'An order is routed to a location that fulfils online orders, a pickup location or a named destination; the request has none.' );
		}

		return $fulfilling[0]->id;
	}

	/**
	 * Finds a candidate by its location.
	 *
	 * @since 0.2.0
	 *
	 * @param Candidate[] $candidates The candidates.
	 * @param int         $location   The location.
	 * @return Candidate|null The candidate, or null when the location is not one.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function find( array $candidates, int $location ): ?Candidate {
		foreach ( $candidates as $candidate ) {
			if ( $candidate->id === $location ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Orders candidates by ascending priority, ties by id.
	 *
	 * @since 0.2.0
	 *
	 * @param Candidate[] $candidates The candidates, in any order.
	 * @return list<Candidate> The candidates, in the order they are tried.
	 *
	 * @phpstan-param list<Candidate> $candidates
	 */
	private static function ordered( array $candidates ): array {
		usort( $candidates, static fn( Candidate $a, Candidate $b ): int => array( $a->priority, $a->id ) <=> array( $b->priority, $b->id ) );

		return $candidates;
	}
}
