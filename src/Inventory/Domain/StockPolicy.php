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
	 * Tells whether the variant may be sold beyond the units available: its stock is not counted, or backorders are allowed.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when no figure limits a sale.
	 */
	public function sellsBeyondStock(): bool {
		return ! $this->track || BackorderPolicy::Allow === $this->backorderPolicy;
	}
}
