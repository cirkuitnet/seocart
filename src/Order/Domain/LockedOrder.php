<?php
/**
 * LockedOrder: an order row as read under its lock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

use SEOCart\Support\Currency;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The facts of an order a transaction decides from, read with a locking read.
 *
 * Owns one fact: the order's statuses, its money and what settling its payment needs, as the
 * row stood when the transaction locked it. Because the read is a locking read, the values are
 * current until the transaction ends, under any isolation level, so a decision made from them is
 * not a check made before an act.
 *
 * @since 0.1.0
 */
final readonly class LockedOrder {

	/**
	 * Records the row.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $id             The internal id.
	 * @param string        $uuid           The public identifier.
	 * @param string        $orderNumber    The number shown to people.
	 * @param OrderChannel  $channel        Where the order came from.
	 * @param OrderStatus   $status         Its status.
	 * @param PaymentStatus $paymentStatus  Its payment status.
	 * @param Money         $grandTotal     The grand total.
	 * @param Money         $authorized     Authorized so far.
	 * @param Money         $paid           Captured so far.
	 * @param Money         $refunded       Refunded so far.
	 * @param Money         $due            Still to be paid.
	 * @param Money         $baseGrandTotal The grand total, in the base currency.
	 * @param Money         $baseAuthorized Authorized so far, in the base currency.
	 * @param Money         $basePaid       Captured so far, in the base currency.
	 * @param Money         $baseRefunded   Refunded so far, in the base currency.
	 * @param int|null      $customerId     The WordPress user it belongs to, or null for a guest order.
	 * @param string        $actorType      Who placed it: `user` in person, `system` for a process on a user's authority.
	 * @param int|null      $actorId        The WordPress user who placed it, or null for a visitor.
	 * @param string|null   $holdGroup      The stock hold placement took for it, or null when nothing was held.
	 */
	public function __construct(
		public int $id,
		public string $uuid,
		public string $orderNumber,
		public OrderChannel $channel,
		public OrderStatus $status,
		public PaymentStatus $paymentStatus,
		public Money $grandTotal,
		public Money $authorized,
		public Money $paid,
		public Money $refunded,
		public Money $due,
		public Money $baseGrandTotal,
		public Money $baseAuthorized,
		public Money $basePaid,
		public Money $baseRefunded,
		public ?int $customerId,
		public string $actorType,
		public ?int $actorId,
		public ?string $holdGroup
	) {
	}

	/**
	 * Returns the order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency of every amount the order owns.
	 */
	public function currency(): Currency {
		return $this->grandTotal->currency();
	}

	/**
	 * Returns the order's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @return Currency The currency of every base_ amount the order owns.
	 */
	public function baseCurrency(): Currency {
		return $this->baseGrandTotal->currency();
	}
}
