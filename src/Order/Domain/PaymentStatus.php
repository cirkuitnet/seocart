<?php
/**
 * PaymentStatus: what an order's payments amount to
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The value of `orders.payment_status`.
 *
 * Owns one fact: the vocabulary of an order's payment status. The status is not a state machine
 * of its own: it is derived from the order's payment ledger by the payment module, and written
 * only with the ledger row that changes it.
 *
 * @since 0.1.0
 */
enum PaymentStatus: string {

	/**
	 * Nothing has been authorized or paid.
	 *
	 * @since 0.1.0
	 */
	case Unpaid = 'unpaid';

	/**
	 * A payment is waiting for the customer or the provider.
	 *
	 * @since 0.1.0
	 */
	case Pending = 'pending';

	/**
	 * The amount is authorized and not yet captured.
	 *
	 * @since 0.1.0
	 */
	case Authorized = 'authorized';

	/**
	 * Part of the amount is paid.
	 *
	 * @since 0.1.0
	 */
	case PartiallyPaid = 'partially_paid';

	/**
	 * The amount is paid.
	 *
	 * @since 0.1.0
	 */
	case Paid = 'paid';

	/**
	 * The amount was paid and part of it refunded.
	 *
	 * @since 0.1.0
	 */
	case PartiallyRefunded = 'partially_refunded';

	/**
	 * The amount was paid and all of it refunded.
	 *
	 * @since 0.1.0
	 */
	case Refunded = 'refunded';

	/**
	 * Every authorization was voided before capture.
	 *
	 * @since 0.1.0
	 */
	case Voided = 'voided';

	/**
	 * Every payment attempt failed.
	 *
	 * @since 0.1.0
	 */
	case Failed = 'failed';

	/**
	 * A payment is disputed with the provider.
	 *
	 * @since 0.1.0
	 */
	case Disputed = 'disputed';

	/**
	 * The order is to be paid on terms, later.
	 *
	 * @since 0.1.0
	 */
	case TermsPending = 'terms_pending';

	/**
	 * Returns the statuses in which the order's money was captured: what a paid order status requires.
	 *
	 * @since 0.1.0
	 *
	 * @return list<self> paid, partially_refunded and refunded.
	 */
	public static function settled(): array {
		return array( self::Paid, self::PartiallyRefunded, self::Refunded );
	}
}
