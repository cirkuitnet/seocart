<?php
/**
 * PaymentError: the error catalog of the payment module
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * The errors applying a gateway result or capturing a payment can end in.
 *
 * Owns one fact: how a refused payment operation is reported. A refused result was refused in
 * the database, by the conditional update's WHERE clause, and its ledger row goes back with the
 * savepoint it was written in: a fact that cannot be reconciled is not recorded, and a gateway's
 * retry meets the same answer. A duplicate and a mismatch are not errors but outcomes, which the
 * transaction commits. A caller's programming error, such as a gateway call inside a
 * transaction, is a \LogicException, never a row here.
 *
 * @since 0.1.0
 */
enum PaymentError: string implements ErrorCode {

	/**
	 * No payment intent has the uuid.
	 *
	 * @since 0.1.0
	 */
	case IntentNotFound = 'payment.intent_not_found';

	/**
	 * Only an authorized payment can be captured.
	 *
	 * @since 0.1.0
	 */
	case NotCapturable = 'payment.not_capturable';

	/**
	 * The payment has a gateway result that did not match its order, which a person must reconcile first.
	 *
	 * @since 0.1.0
	 */
	case Unreconciled = 'payment.unreconciled';

	/**
	 * The intent's state does not accept the result's operation.
	 *
	 * @since 0.1.0
	 */
	case UnexpectedResult = 'payment.unexpected_result';

	/**
	 * A refund would give back more than was captured.
	 *
	 * @since 0.1.0
	 */
	case RefundExceedsCaptured = 'payment.refund_exceeds_captured';

	/**
	 * The order's payment amounts refused a change under a lock the payment held: they changed where nothing may change them.
	 *
	 * @since 0.1.0
	 */
	case ProjectionConflict = 'payment.projection_conflict';

	/**
	 * Returns the catalog's rows.
	 *
	 * @since 0.1.0
	 *
	 * @return list<ErrorDefinition> One row per case.
	 */
	public static function definitions(): array {
		return array(
			new ErrorDefinition(
				self::IntentNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: The payment's identifier. */
					__( 'The payment %1$s was not found.', 'seocart' ),
				array( 'intent_uuid' )
			),
			new ErrorDefinition(
				self::NotCapturable,
				409,
				static fn(): string =>
					/* translators: %1$s: The payment's status, for example created. */
					__( 'A payment that is %1$s cannot be captured; only an authorized payment can.', 'seocart' ),
				array( 'status' )
			),
			new ErrorDefinition(
				self::Unreconciled,
				409,
				static fn(): string => __( 'The payment has a result that does not match its order; a person must reconcile it before it is captured.', 'seocart' )
			),
			new ErrorDefinition(
				self::UnexpectedResult,
				409,
				static fn(): string =>
					/* translators: %1$s: The payment's status. %2$s: The operation the result reports, for example capture. */
					__( 'A payment that is %1$s cannot take a result of %2$s.', 'seocart' ),
				array( 'intent_status', 'operation' )
			),
			new ErrorDefinition(
				self::RefundExceedsCaptured,
				409,
				static fn(): string =>
					/* translators: %1$s: The amount captured, in minor units. %2$s: The amount refunded so far, in minor units. %3$s: The refund asked for, in minor units. */
					__( 'A refund of %3$s would exceed what is left of %1$s captured, of which %2$s is already refunded.', 'seocart' ),
				array( 'captured', 'refunded', 'requested' )
			),
			new ErrorDefinition(
				self::ProjectionConflict,
				500,
				static fn(): string =>
					/* translators: %1$s: The order's internal id. */
					__( 'The payment amounts of order %1$s could not be recorded.', 'seocart' ),
				array( 'order_id' ),
				true
			),
		);
	}
}
