<?php
/**
 * ClaimableOrder: what a claim of a guest's order is judged by
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * An order as a claim reads it, by its public identifier: who placed it, the address it was placed with, and its access facts.
 *
 * Owns one fact: what a signed-in user's claim of an order compares. The access facts are the
 * ones an access check compares (OrderAccess), so a claim's key test is the access check's own.
 *
 * @since 0.2.0
 */
final readonly class ClaimableOrder {

	/**
	 * Records the facts.
	 *
	 * @since 0.2.0
	 *
	 * @param int         $id      The order's internal id.
	 * @param int|null    $actorId The WordPress user who placed it, or null for a visitor.
	 * @param string      $email   The email address the order was placed with, as typed.
	 * @param OrderAccess $access  Its customer and its access key, with whether the key has expired.
	 */
	public function __construct(
		public int $id,
		public ?int $actorId,
		public string $email,
		public OrderAccess $access
	) {
	}
}
