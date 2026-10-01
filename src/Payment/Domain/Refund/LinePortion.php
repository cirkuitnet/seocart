<?php
/**
 * LinePortion: what a refund returns of one order line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

use SEOCart\Order\Domain\RefundableLine;

defined( 'ABSPATH' ) || exit;

/**
 * Some units of an order line, the share of its stored amount they return, and the shares of its tax components: a `refund_lines` row and its components.
 *
 * Owns one fact: what one line of a refund returns, as RefundAllocation allocated it.
 *
 * @since 0.1.0
 */
final readonly class LinePortion {

	/**
	 * Records the portion.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundableLine     $line       The order line, as read when the refund was worked out.
	 * @param int                $quantity   How many of its units the refund returns.
	 * @param bool               $restock    Whether the units go back into stock.
	 * @param Share              $share      What the units return: their net, tax and gross.
	 * @param ComponentPortion[] $components What they return of each of the line's tax components.
	 *
	 * @phpstan-param list<ComponentPortion> $components
	 */
	public function __construct(
		public RefundableLine $line,
		public int $quantity,
		public bool $restock,
		public Share $share,
		public array $components
	) {
	}
}
