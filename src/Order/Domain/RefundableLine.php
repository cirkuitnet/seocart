<?php
/**
 * RefundableLine: an order line, as a refund reads it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One line of an order: its units, how many were refunded so far, and its stored amount after discounts.
 *
 * Owns one fact: what a refund of some of a line's units starts from. The refunded quantity is
 * the one the line held when it was read; the update that adds a refund's units to it requires
 * that value still, so a refund whose shares were allocated from an older read cannot land.
 *
 * @since 0.1.0
 */
final readonly class RefundableLine {

	/**
	 * Records the line.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $id               The line's internal id.
	 * @param string       $lineUuid         Its public identifier.
	 * @param int          $quantity         Units sold.
	 * @param int          $refundedQuantity Units refunded so far.
	 * @param StoredAmount $stored           Its amount after discounts, as stored, with its unit price's basis.
	 */
	public function __construct(
		public int $id,
		public string $lineUuid,
		public int $quantity,
		public int $refundedQuantity,
		public StoredAmount $stored
	) {
	}
}
