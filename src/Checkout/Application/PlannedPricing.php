<?php
/**
 * PlannedPricing: prices a checkout with its fulfilment plan, and asks once more when the plan found too few units because of holds that expired
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Domain\Routing\RoutingRefused;
use SEOCart\Pricing\Application\Calculation;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a checkout's calculation with its plan; when the plan is refused for too few units, reclaims the refused variants' expired holds and, when that gave any back, runs it once more.
 *
 * Owns one fact: what a checkout does when its plan finds too few units. A hold that expired
 * still counts its units as held until the sweep reclaims it, so the router does not offer them,
 * and a plan refused never reaches the claim that would have reclaimed them itself. So, once,
 * the refused variants' expired holds are reclaimed at every active location, each item in a
 * unit of work of its own, and the calculation is run again; when nothing was reclaimed the
 * refusal stands, and nothing is asked again. A calculation runs only outside any transaction,
 * so a refusal it raises is too, and each reclaim commits on its own. The session write and the
 * placement price through it; in a placement it is the one time the placement plans again.
 *
 * @since 0.2.0
 */
final class PlannedPricing {

	/**
	 * Reclaims some variants' expired holds at every active location; returns the hold rows reclaimed.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(list<int>): int
	 */
	private \Closure $reclaimExpired;

	/**
	 * Creates the pricing. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $reclaimExpired Reclaims some variants' expired holds at every active location, given their ids
	 *                                 (list<int>); returns the hold rows reclaimed (int).
	 *
	 * @phpstan-param \Closure(list<int>): int $reclaimExpired
	 */
	public function __construct( \Closure $reclaimExpired ) {
		$this->reclaimExpired = $reclaimExpired;
	}

	/**
	 * Runs a calculation with its plan, and once more after reclaiming expired holds when the plan found too few units and it may ask again.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException What the calculation raises: `stock.routing_refused` as it was raised when the refusal
	 *                        is not for too few units, it may not ask again or nothing was reclaimed; the second
	 *                        calculation's own refusals.
	 *
	 * @param \Closure $calculation Runs the calculation, which returns a Calculation.
	 * @param bool     $askAgain    Optional. Whether it may run the calculation once more; set to false when it
	 *                              did. Default true.
	 * @return Calculation The calculation.
	 *
	 * @phpstan-param \Closure(): Calculation $calculation
	 * @param-out bool $askAgain
	 */
	public function price( \Closure $calculation, bool &$askAgain = true ): Calculation {
		try {
			return $calculation();
		} catch ( CodedException $refused ) {
			if ( ! $askAgain || ! self::isShortOfUnits( $refused ) || 0 === ( $this->reclaimExpired )( self::variantIdsOf( $refused ) ) ) {
				throw $refused;
			}
		}

		$askAgain = false;

		return $calculation();
	}

	/**
	 * Tells whether a plan was refused because some variant's units are too few, which an expired hold may be holding.
	 *
	 * @since 0.2.0
	 *
	 * @param CodedException $refused What the calculation raised.
	 * @return bool True for `stock.routing_refused` with the reason `quantity`.
	 */
	private static function isShortOfUnits( CodedException $refused ): bool {
		return InventoryError::RoutingRefused === $refused->errorCode() && RoutingRefused::QUANTITY === ( $refused->details()['reason'] ?? null );
	}

	/**
	 * Returns the variants a refusal names.
	 *
	 * @since 0.2.0
	 *
	 * @param CodedException $refused The refusal.
	 * @return list<int> The variant ids.
	 */
	private static function variantIdsOf( CodedException $refused ): array {
		$variantIds = $refused->details()['variant_ids'] ?? array();

		return is_array( $variantIds ) ? array_values( array_map( 'intval', $variantIds ) ) : array();
	}
}
