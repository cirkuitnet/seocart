<?php
/**
 * Receipt: a webhook receipt as recording a delivery found it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Domain\Webhook;

defined( 'ABSPATH' ) || exit;

/**
 * The receipt of a provider's event: its row, and what was decided about it, if anything yet.
 *
 * Owns one fact: whether a delivery has anything left to do. A receipt with no result is
 * undecided, written by this delivery or by an earlier one that never finished, and the delivery
 * processes the event; one with a result was decided before, and the delivery is answered from it.
 *
 * @since 0.2.0
 */
final readonly class Receipt {

	/**
	 * Records the receipt.
	 *
	 * @since 0.2.0
	 *
	 * @param int                $id     The receipt's row.
	 * @param ReceiptResult|null $result What was decided about the event; null while undecided.
	 */
	public function __construct(
		public int $id,
		public ?ReceiptResult $result
	) {
	}

	/**
	 * Tells whether the event was decided before.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the receipt holds a result.
	 */
	public function isSettled(): bool {
		return null !== $this->result;
	}
}
