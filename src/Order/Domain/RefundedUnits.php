<?php
/**
 * RefundedUnits: the units of one order line a refund returns
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * How many units of a line a refund returns, and how many the line had refunded when the refund was worked out.
 *
 * Owns one fact: what the conditional update of `order_lines.refunded_quantity` requires. The
 * refund's shares were allocated from what the line had left at that read, so the update lands
 * only while the line still has that many refunded, and never past the units sold.
 *
 * @since 0.1.0
 */
final readonly class RefundedUnits {

	/**
	 * Records the units.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When fewer than one unit is returned, or the units before are negative.
	 *
	 * @param int $lineId         The order line's internal id.
	 * @param int $quantity       How many units the refund returns, 1 or more.
	 * @param int $refundedBefore How many the line had refunded when the refund was worked out.
	 */
	public function __construct(
		public int $lineId,
		public int $quantity,
		public int $refundedBefore
	) {
		if ( $quantity < 1 || $refundedBefore < 0 ) {
			throw new \InvalidArgumentException( 'A refund returns one unit of a line or more, after none or more.' );
		}
	}
}
