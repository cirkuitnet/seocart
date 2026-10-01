<?php
/**
 * RefundLineRequest: the units of one order line a refund asks to give back
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Refund;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a caller's programming error to the developer; it is never HTML.

/**
 * One line of a refund request: the order line, how many of its units, and whether they go back into stock.
 *
 * Owns one fact: what a merchant asks of one line. The money is never asked for: it is the
 * share of the line's stored figures those units stand for, allocated by the refund.
 *
 * @since 0.1.0
 */
final readonly class RefundLineRequest {

	/**
	 * Records the line.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When fewer than one unit is asked for.
	 *
	 * @param string $lineUuid The order line's public identifier.
	 * @param int    $quantity How many of its units to refund, 1 or more.
	 * @param bool   $restock  Optional. Whether the units go back into stock: recorded, and not yet acted on. Default false.
	 */
	public function __construct(
		public string $lineUuid,
		public int $quantity,
		public bool $restock = false
	) {
		if ( $quantity < 1 ) {
			throw new \InvalidArgumentException( 'A refund returns one unit of a line or more.' );
		}
	}
}
