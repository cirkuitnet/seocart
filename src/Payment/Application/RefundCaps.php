<?php
/**
 * RefundCaps: the most a user may give back, of one order and in any 24 hours
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Payment\Domain\Refund\RefundAllocation;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The caps on one user's refunds, in the base currency: of one order, all of its refunds together, and in any 24 hours; null for no cap.
 *
 * Owns one fact: whether a refund's base share fits a cap, given what was used of it. A refund
 * that does not fit is refused whole, before the gateway is asked: no smaller refund is made.
 *
 * @since 0.2.0
 */
final readonly class RefundCaps {

	/**
	 * Records the caps.
	 *
	 * @since 0.2.0
	 *
	 * @param Money|null $perOrder The most of one order, all of its refunds together; null for no cap.
	 * @param Money|null $perDay   The most in any 24 hours; null for no cap.
	 */
	public function __construct(
		public ?Money $perOrder,
		public ?Money $perDay
	) {
	}

	/**
	 * Returns no cap at all.
	 *
	 * @since 0.2.0
	 *
	 * @return self The caps.
	 */
	public static function none(): self {
		return new self( null, null );
	}

	/**
	 * Refuses a refund that would take an order's refunds past the cap of one order.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_cap_exceeded`, naming the cap, what was used and what is asked.
	 *
	 * @param Money $refunded What the order's refunds gave back, in the base currency.
	 * @param Money $share    This refund's base share.
	 */
	public function requirePerOrder( Money $refunded, Money $share ): void {
		self::requireWithin( 'per_order', $this->perOrder, $refunded, $share );
	}

	/**
	 * Refuses a refund that would take what the user asked in the last 24 hours past the cap of a day.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_cap_exceeded`, naming the cap, what was used and what is asked.
	 *
	 * @param Money $asked What the user asked of the gateway in the last 24 hours, in the base currency.
	 * @param Money $share This refund's base share.
	 */
	public function requirePerDay( Money $asked, Money $share ): void {
		self::requireWithin( 'per_day', $this->perDay, $asked, $share );
	}

	/**
	 * Refuses a share that does not fit what is left of a cap.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_cap_exceeded`.
	 *
	 * @param string     $kind  `per_order` or `per_day`.
	 * @param Money|null $limit The cap; null for none.
	 * @param Money      $used  What was used of it.
	 * @param Money      $share What this refund asks.
	 */
	private static function requireWithin( string $kind, ?Money $limit, Money $used, Money $share ): void {
		if ( null === $limit || RefundAllocation::fits( $limit, $used, $share ) ) {
			return;
		}

		CodedException::raise(
			PaymentError::RefundCapExceeded,
			array(
				'cap_kind'        => $kind,
				'limit_minor'     => $limit->minorUnits(),
				'used_minor'      => $used->minorUnits(),
				'requested_minor' => $share->minorUnits(),
				'currency'        => $limit->currency()->code(),
			)
		);
	}
}
