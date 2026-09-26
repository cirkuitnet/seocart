<?php
/**
 * StatusMetadata: what being in an order status means
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The facts the order status registry declares about one status.
 *
 * Owns one fact: what the rest of the store may conclude from an order's status. Pure data,
 * built by OrderStatusRegistry from its table.
 *
 * @since 0.1.0
 */
final readonly class StatusMetadata {

	/**
	 * Records the facts.
	 *
	 * @since 0.1.0
	 *
	 * @param bool   $isPaid               Whether the status claims the order is paid: entering it requires a settled payment status.
	 * @param bool   $isFinal              Whether the order's lifecycle normally ends in it.
	 * @param bool   $holdsAllocation      Whether the order's stock stays allocated to it while it is in the status.
	 * @param bool   $postsStockLedger     Whether entering the status posts the order's allocations to the stock ledger.
	 * @param bool   $releasesFulfilment   Whether the order may be fulfilled while it is in the status.
	 * @param string $customerVisibleLabel The key of the label a customer sees for the status.
	 */
	public function __construct(
		public bool $isPaid,
		public bool $isFinal,
		public bool $holdsAllocation,
		public bool $postsStockLedger,
		public bool $releasesFulfilment,
		public string $customerVisibleLabel
	) {
	}
}
