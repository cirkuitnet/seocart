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
 * The errors applying a gateway result, capturing a payment or refunding an order can end in.
 *
 * Owns one fact: how a refused payment operation is reported. A refused result was refused in
 * the database, by the conditional update's WHERE clause, and its ledger row goes back with the
 * savepoint it was written in: a fact that cannot be reconciled is not recorded, and a gateway's
 * retry meets the same answer. A duplicate and a mismatch are not errors but outcomes, which the
 * transaction commits. A refund is refused, wherever it can be, before the gateway is asked; one
 * the gateway made that a cap then refused is recorded for a person and answered
 * `payment.unreconciled`; one asked for before, whose fate the gateway cannot account for, is
 * refused `payment.refund_unresolved` and not asked for again, and so is every other refund of
 * the same payment until a person settles it; and one worked out from figures another refund
 * moved before it was claimed is refused `payment.refund_retry`, to be asked for again. A
 * caller's programming error, such as a gateway call inside a transaction, is a \LogicException,
 * never a row here.
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
	 * The order has no captured payment to give money back from.
	 *
	 * @since 0.1.0
	 */
	case RefundNotRefundable = 'payment.refund_not_refundable';

	/**
	 * The order has no line with the uuid a refund names.
	 *
	 * @since 0.1.0
	 */
	case RefundLineNotFound = 'payment.refund_line_not_found';

	/**
	 * A refund asks for more units of a line than are left to return.
	 *
	 * @since 0.1.0
	 */
	case RefundLineExhausted = 'payment.refund_line_exhausted';

	/**
	 * Nothing of what a refund asks for is left to give back.
	 *
	 * @since 0.1.0
	 */
	case RefundNothingLeft = 'payment.refund_nothing_left';

	/**
	 * The gateway declined to give the money back.
	 *
	 * @since 0.1.0
	 */
	case RefundDeclined = 'payment.refund_declined';

	/**
	 * The gateway was asked for a refund of the payment before and cannot say whether it made it; nothing in the plugin settles such a refund yet, and until a person does, the payment takes no other refund.
	 *
	 * The refund named is the one asked for before: this request's own, asked again, or another of
	 * the same payment, which this one waits for.
	 *
	 * @since 0.1.0
	 */
	case RefundUnresolved = 'payment.refund_unresolved';

	/**
	 * Another refund of the payment was recorded or declined after this one was worked out, so this one was not asked for; asking again works it out anew.
	 *
	 * @since 0.1.0
	 */
	case RefundRetry = 'payment.refund_retry';

	/**
	 * The payment's gateway cannot be used now: it is not installed, or its credentials for the payment's mode are missing or do not open. Nothing was sent to it, and nothing was written.
	 *
	 * @since 0.2.0
	 */
	case GatewayUnavailable = 'payment.gateway_unavailable';

	/**
	 * The payment's gateway does not declare the operation asked of it for the payment's currency and the account's country. Nothing was sent to it, and nothing was written.
	 *
	 * @since 0.2.0
	 */
	case OperationUnsupported = 'payment.operation_unsupported';

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
				static fn(): string => __( 'The payment has a result that could not be recorded against its order; a person must reconcile it before anything else is done with the payment.', 'seocart' )
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
			new ErrorDefinition(
				self::RefundNotRefundable,
				409,
				static fn(): string =>
					/* translators: %1$s: The order's identifier. */
					__( 'The order %1$s has no captured payment to refund.', 'seocart' ),
				array( 'order_uuid' )
			),
			new ErrorDefinition(
				self::RefundLineNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: The order line's identifier. */
					__( 'The order has no line %1$s.', 'seocart' ),
				array( 'line_uuid' )
			),
			new ErrorDefinition(
				self::RefundLineExhausted,
				409,
				static fn(): string =>
					/* translators: %1$s: The order line's identifier. %2$s: How many of its units can still be refunded. */
					__( 'Only %2$s units of line %1$s are left to refund.', 'seocart' ),
				array( 'line_uuid', 'returnable' )
			),
			new ErrorDefinition(
				self::RefundNothingLeft,
				409,
				static fn(): string => __( 'Nothing of what the refund asks for is left to give back.', 'seocart' )
			),
			new ErrorDefinition(
				self::RefundDeclined,
				402,
				static fn(): string => __( 'The payment gateway declined the refund; no money was given back.', 'seocart' )
			),
			new ErrorDefinition(
				self::RefundUnresolved,
				409,
				static fn(): string =>
					/* translators: %1$s: The refund's identifier. */
					__( 'The payment gateway was asked for the refund %1$s before and cannot say whether it gave the money back. Nothing in the plugin settles such a refund yet, and until a person does, this payment takes no other refund.', 'seocart' ),
				array( 'refund_uuid' )
			),
			new ErrorDefinition(
				self::RefundRetry,
				409,
				static fn(): string => __( 'Another refund of this payment was recorded or declined while this one was being worked out, so the payment gateway was not asked for it; ask for the refund again.', 'seocart' )
			),
			new ErrorDefinition(
				self::GatewayUnavailable,
				503,
				static fn(): string =>
					/* translators: %1$s: The payment gateway's id, for example stripe. %2$s: Why it cannot be used, for example not_registered. */
					__( 'The payment gateway %1$s cannot be used now (%2$s); nothing was sent to it.', 'seocart' ),
				array( 'gateway_id', 'reason' )
			),
			new ErrorDefinition(
				self::OperationUnsupported,
				409,
				static fn(): string =>
					/* translators: %1$s: The payment gateway's id, for example stripe. %2$s: The operation, for example partial_refund. */
					__( 'The payment gateway %1$s does not support %2$s for this payment; nothing was sent to it.', 'seocart' ),
				array( 'gateway_id', 'operation' )
			),
		);
	}
}
