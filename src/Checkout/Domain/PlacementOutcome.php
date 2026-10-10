<?php
/**
 * PlacementOutcome: where an order placement stands once a gateway result was applied to it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The outcomes of settling a placement, which are also their wire values.
 *
 * Owns one fact: what the shopper's client is told a placement came to. An order is `pending`
 * from the moment it is placed until a gateway result is applied to it.
 *
 * @since 0.1.0
 */
enum PlacementOutcome: string {

	/**
	 * The order is placed, and nothing is known of its payment yet.
	 *
	 * @since 0.1.0
	 */
	case Pending = 'pending';

	/**
	 * The payment was authorized: the order is accepted, its units allocated, its cart finished.
	 *
	 * @since 0.1.0
	 */
	case Approved = 'approved';

	/**
	 * The payment was declined: the order failed, every reservation was released, its cart is open again.
	 *
	 * @since 0.1.0
	 */
	case Declined = 'declined';

	/**
	 * The shopper must act, for example confirm with their bank, before the gateway decides.
	 *
	 * @since 0.1.0
	 */
	case RequiresAction = 'requires_action';

	/**
	 * The gateway has not decided yet.
	 *
	 * @since 0.1.0
	 */
	case Processing = 'processing';

	/**
	 * The gateway approved an amount or a currency the order does not have: the order is on hold for a person, its units allocated.
	 *
	 * @since 0.1.0
	 */
	case AmountMismatch = 'amount_mismatch';

	/**
	 * The payment was authorized, but the order's units were gone: the order is on hold for a person.
	 *
	 * @since 0.1.0
	 */
	case StockUnavailable = 'stock_unavailable';

	/**
	 * The gateway approved the payment after the placement had ended without it: the money is kept for a person, the order flagged, and nothing the placement gave back is taken again.
	 *
	 * @since 0.1.0
	 */
	case LateApproval = 'late_approval';

	/**
	 * The shopper's time to act ran out and the payment was voided at the gateway: the order is cancelled, every reservation released, its cart open again; or, when the payment had been approved meanwhile, the order is on hold for a person, with what it holds.
	 *
	 * @since 0.2.0
	 */
	case Voided = 'voided';

	/**
	 * The result had been applied before, so nothing changed.
	 *
	 * @since 0.1.0
	 */
	case Duplicate = 'duplicate';
}
