<?php
/**
 * TransferLine: the units of one variant a transfer moves, and how many arrived
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * One line of a transfer: a variant, the units sent, the units received and lost so far, and the allocation it carries.
 *
 * Owns one fact: what a line says about its units. Once the transfer is sent, the units neither
 * received nor lost are on the way: they are in no location's stock, and this is the only record
 * of them. A line that carries an allocation moves an order's units: what arrives becomes that
 * order's allocation at the destination, and the line cannot be edited.
 *
 * @since 0.2.0
 */
final readonly class TransferLine {

	/**
	 * Records a line.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $id               The line's id.
	 * @param int      $variantId        The variant whose units move.
	 * @param int      $quantity         The units sent, or to send.
	 * @param int      $receivedQuantity The units received at the destination so far.
	 * @param int      $lostQuantity     The units written off as lost so far.
	 * @param int|null $allocationId     The order's allocation the units travel with, or null for units no order waits for.
	 */
	public function __construct(
		public int $id,
		public int $variantId,
		public int $quantity,
		public int $receivedQuantity,
		public int $lostQuantity,
		public ?int $allocationId
	) {
	}

	/**
	 * Returns the units neither received nor lost yet: once the transfer is sent, the units on the way.
	 *
	 * @since 0.2.0
	 *
	 * @return int The units.
	 */
	public function remaining(): int {
		return $this->quantity - $this->receivedQuantity - $this->lostQuantity;
	}

	/**
	 * Tells whether the line carries, or carried until it was received, an order's allocation.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when it names an allocation.
	 */
	public function isAllocated(): bool {
		return null !== $this->allocationId;
	}
}
