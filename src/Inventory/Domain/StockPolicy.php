<?php
/**
 * StockPolicy: what is true of a variant's stock wherever its units are
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A variant's stock policy, as its one `stock_policies` row records it.
 *
 * Owns one fact: what a variant's stock rows have in common. Whether stock is counted, what is
 * allowed once it runs out and the low-stock threshold are the variant's, at every location
 * alike, so they are stored once. A variant takes part in a hold only when its policy says its
 * stock is counted; a variant with no policy row has no stock item at all.
 *
 * @since 0.2.0
 */
final readonly class StockPolicy {

	/**
	 * Records a policy.
	 *
	 * @since 0.2.0
	 *
	 * @param int             $variantId         The variant.
	 * @param bool            $track             Whether stock is counted; an untracked variant is always available.
	 * @param BackorderPolicy $backorderPolicy   What is allowed once the units run out.
	 * @param int|null        $lowStockThreshold The units available at or below which the variant counts as low, or null for none.
	 */
	public function __construct(
		public int $variantId,
		public bool $track,
		public BackorderPolicy $backorderPolicy,
		public ?int $lowStockThreshold
	) {
	}

	/**
	 * Tells whether the variant may be sold beyond the units available: only when its stock is not counted.
	 *
	 * The one rule the router and the figure for sale both read, so neither plans or shows a unit
	 * that a hold's claim refuses. A variant whose stock is counted is claimed only where units are
	 * available, whatever its backorder policy: so it is sold only from units, and the policy
	 * `allow` changes nothing yet. A claim that reads the backorder policy is what will let such a
	 * variant be sold beyond its units; this answer changes with it, and the router, the figure for
	 * sale and the stock list follow.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when no figure limits a sale: the variant's stock is not counted.
	 */
	public function sellsBeyondStock(): bool {
		return ! $this->track;
	}
}
