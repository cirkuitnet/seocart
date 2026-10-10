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
 * The errors applying a gateway result, capturing or voiding a payment, or refunding an order can end in.
 *
 * Owns one fact: how a refused payment operation is reported. A capture or a void is refused,
 * wherever it can be, before the gateway is asked; one the gateway refused, or did not answer, is
 * reported `payment.operation_declined` or `payment.gateway_no_answer`, and asking again sends the
 * same request, which the gateway answers once. A refused result was refused in
 * the database, by the conditional update's WHERE clause, and its ledger row goes back with the
 * savepoint it was written in: a fact that cannot be reconciled is not recorded, and a gateway's
 * retry meets the same answer. A duplicate and a mismatch are not errors but outcomes, which the
 * transaction commits. A refund is refused, wherever it can be, before the gateway is asked; one
 * the gateway made that a cap then refused is recorded for a person and answered
 * `payment.unreconciled`; one asked for before, whose fate the gateway cannot account for, is
 * refused `payment.refund_unresolved` and not asked for again, and so is every other refund of
 * the same payment until a person settles its claim; and one worked out from figures another refund
 * moved before it was claimed is refused `payment.refund_retry`, to be asked for again. An
 * idempotency key sent before with another refund request is refused
 * `payment.refund_key_reused`, before anything of the order is read; and a refund past one of the
 * user's refund caps is refused `payment.refund_cap_exceeded`, whole, before the gateway is asked. A
 * claim a person settles must exist (`payment.refund_claim_not_found`) and still be open
 * (`payment.refund_claim_ended`), and the person's statement must be one it can be settled with
 * (`payment.refund_statement_incomplete`). A caller's programming error, such as a gateway call
 * inside a transaction, is a \LogicException, never a row here.
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
	 * A capture asks for more than the payment authorized. Nothing was asked of the gateway.
	 *
	 * @since 0.2.0
	 */
	case CaptureExceedsAuthorized = 'payment.capture_exceeds_authorized';

	/**
	 * The gateway refused the capture or the void asked of it. A refused void changes nothing: the authorization stands. A refused capture is recorded, and leaves the payment failed: what was authorized can no longer be captured.
	 *
	 * @since 0.2.0
	 */
	case OperationDeclined = 'payment.operation_declined';

	/**
	 * The gateway did not answer: nothing was recorded, and asking again sends the same request, which the gateway answers once.
	 *
	 * @since 0.2.0
	 */
	case GatewayNoAnswer = 'payment.gateway_no_answer';

	/**
	 * Only an authorized payment can be voided: one captured is given back by a refund, and one ended has nothing to release.
	 *
	 * @since 0.2.0
	 */
	case NotVoidable = 'payment.not_voidable';

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
	 * The gateway was asked for a refund of the payment before and cannot say whether it made it; until a person settles that refund's claim, the payment takes no other refund.
	 *
	 * The refund named is the one asked for before: this request's own, asked again, or another of
	 * the same payment, which this one waits for.
	 *
	 * @since 0.1.0
	 */
	case RefundUnresolved = 'payment.refund_unresolved';

	/**
	 * Another refund of the payment was recorded or declined after this one was worked out, or another request is refunding the same units, so this one was not asked for; asking again works it out anew.
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
	 * The idempotency key was sent before with another refund request: a key names one request for good.
	 *
	 * @since 0.2.0
	 */
	case RefundKeyReused = 'payment.refund_key_reused';

	/**
	 * A refund asked through the refund operation needs the Idempotency-Key header, or the command's --idempotency_key, of 1 to IdempotencyKey::MAX_LENGTH bytes: the key is missing, or longer.
	 *
	 * @since 0.2.0
	 */
	case RefundKeyMissing = 'payment.refund_key_missing';

	/**
	 * A refund asks for nothing, or names a line twice.
	 *
	 * @since 0.2.0
	 */
	case RefundRequestInvalid = 'payment.refund_request_invalid';

	/**
	 * A refund's note holds what reads as a card number, which is never kept.
	 *
	 * @since 0.2.0
	 */
	case RefundNoteRejected = 'payment.refund_note_rejected';

	/**
	 * The refund would take what the user may give back past one of their refund caps: of one order, or in any 24 hours. Nothing was asked of the gateway, and nothing was written.
	 *
	 * @since 0.2.0
	 */
	case RefundCapExceeded = 'payment.refund_cap_exceeded';

	/**
	 * A refund cap is an amount of the base currency in major units, such as 250.00, or empty for no cap.
	 *
	 * @since 0.2.0
	 */
	case RefundCapInvalid = 'payment.refund_cap_invalid';

	/**
	 * No refund claim has the uuid a person asked to settle.
	 *
	 * @since 0.2.0
	 */
	case RefundClaimNotFound = 'payment.refund_claim_not_found';

	/**
	 * The refund claim a person asked to settle has ended already: recorded, declined or left for a person, by the gateway's answer or by another settlement.
	 *
	 * @since 0.2.0
	 */
	case RefundClaimEnded = 'payment.refund_claim_ended';

	/**
	 * A person's statement about a refund cannot settle its claim: every statement says why; one that the refund was made also names the provider's refund and the amount, one that it was not names neither; and the provider's refund never holds a card number.
	 *
	 * @since 0.2.0
	 */
	case RefundStatementIncomplete = 'payment.refund_statement_incomplete';

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
				array( 'status' ),
				details: array( 'captured', 'currency' )
			),
			new ErrorDefinition(
				self::CaptureExceedsAuthorized,
				409,
				static fn(): string =>
					/* translators: %1$s: The amount authorized, in minor units. %2$s: The capture asked for, in minor units. */
					__( 'A capture of %2$s would take more than the %1$s authorized; nothing was asked of the payment gateway.', 'seocart' ),
				array( 'authorized', 'requested' )
			),
			new ErrorDefinition(
				self::OperationDeclined,
				402,
				static fn(): string =>
					/* translators: %1$s: The payment gateway's id, for example stripe. %2$s: The operation, for example capture. */
					__( 'The payment gateway %1$s refused the %2$s. A refused void changes nothing; a refused capture is recorded, and leaves the payment failed.', 'seocart' ),
				array( 'gateway_id', 'operation' )
			),
			new ErrorDefinition(
				self::GatewayNoAnswer,
				502,
				static fn(): string =>
					__( 'The payment gateway did not answer, and nothing was recorded. Asking again sends the same request, which the gateway carries out at most once.', 'seocart' )
			),
			new ErrorDefinition(
				self::NotVoidable,
				409,
				static fn(): string =>
					/* translators: %1$s: The payment's status, for example captured. */
					__( 'A payment that is %1$s cannot be voided; only an authorized payment can, and a captured one is given back by a refund.', 'seocart' ),
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
					__( 'The payment gateway was asked for the refund %1$s before and cannot say whether it gave the money back. Until a person settles that refund\'s claim, this payment takes no other refund.', 'seocart' ),
				array( 'refund_uuid' )
			),
			new ErrorDefinition(
				self::RefundRetry,
				409,
				static fn(): string => __( 'Another request is refunding the same units of this payment, or another refund of it was recorded or declined while this one was being worked out, so the payment gateway was not asked for it; ask for the refund again.', 'seocart' )
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
			new ErrorDefinition(
				self::RefundKeyReused,
				422,
				static fn(): string => __( 'This idempotency key was sent before with another refund request. Send a new key with a new request, and the same key only to retry the same request.', 'seocart' )
			),
			new ErrorDefinition(
				self::RefundKeyMissing,
				400,
				static fn(): string =>
					/* translators: %1$s: The longest key, in bytes, for example 64. */
					__( 'A refund needs an idempotency key of 1 to %1$s bytes: a new key, such as a UUID, for each new refund, and the same key to retry it.', 'seocart' ),
				array( 'max_bytes' )
			),
			new ErrorDefinition(
				self::RefundRequestInvalid,
				422,
				static fn(): string =>
					/* translators: %1$s: What is wrong with the request: nothing_asked or line_repeated. */
					__( 'The refund request is not one a refund can be made from (%1$s): it asks for units of a line, the shipping, or both, and names each line once.', 'seocart' ),
				array( 'problem' )
			),
			new ErrorDefinition(
				self::RefundNoteRejected,
				422,
				static fn(): string => __( 'The refund\'s note holds what reads as a card number, which the store never keeps; write the note without it.', 'seocart' )
			),
			new ErrorDefinition(
				self::RefundCapExceeded,
				403,
				static fn(): string =>
					/* translators: %1$s: Which cap, per_order or per_day. %2$s: The cap. %3$s: What was used of it. %4$s: The refund asked for. %5$s: The base currency of the four amounts, which are in its minor units. */
					__( 'A refund of %4$s would exceed your %1$s refund cap of %2$s, of which %3$s is used (amounts in minor units of %5$s). Ask a user with a higher cap to make it.', 'seocart' ),
				array( 'cap_kind', 'limit_minor', 'used_minor', 'requested_minor', 'currency' )
			),
			new ErrorDefinition(
				self::RefundCapInvalid,
				422,
				static fn(): string => __( 'A refund cap is an amount of the base currency, such as 250.00, with at most six decimals, or empty for no cap.', 'seocart' )
			),
			new ErrorDefinition(
				self::RefundClaimNotFound,
				404,
				static fn(): string =>
					/* translators: %1$s: The refund's identifier. */
					__( 'No refund %1$s was asked of the payment gateway, so there is no claim of it to settle.', 'seocart' ),
				array( 'refund_uuid' )
			),
			new ErrorDefinition(
				self::RefundClaimEnded,
				409,
				static fn(): string =>
					/* translators: %1$s: How the claim ended: recorded, declined or unreconciled. */
					__( 'The refund\'s claim has ended already (%1$s), so it cannot be settled again; nothing was changed.', 'seocart' ),
				array( 'state' )
			),
			new ErrorDefinition(
				self::RefundStatementIncomplete,
				422,
				static fn(): string =>
					/* translators: %1$s: What is wrong with the statement: incomplete, contradictory, card_number, note_too_long or already_recorded. %2$s: The longest note, in characters, for example 500. */
					__( 'The statement cannot settle the refund\'s claim (%1$s): every statement says why, in at most %2$s characters; one that the refund was made also names the payment provider\'s refund, in printable ASCII with no space, and the amount given back, and one that it was not names neither; the provider\'s refund never holds a card number, and is never one already recorded.', 'seocart' ),
				array( 'problem', 'max_length' )
			),
		);
	}
}
