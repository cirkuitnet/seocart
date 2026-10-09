<?php
/**
 * PaymentOperations: the payment operations a merchant's client calls
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RequestHeader;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\OrderOperations;
use SEOCart\Payment\Domain\Refund\RefundReason;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `payment.refund_order`, a merchant's refund of units of an order's lines and of what is left of its shipping; and `payment.settle_refund_claim`, a person's settlement of a refund the gateway cannot account for.
 *
 * Owns one fact: how a refund is offered to clients. One declaration serves the REST route
 * `POST seocart/v1/orders/{order_uuid}/refunds`, the ability `seocart/refund-order` and the
 * command `wp seocart order refund <order_uuid>`; each is compiled from it and none restates it.
 * The lines are a list of objects, which the command takes as JSON. The reasons a client may give
 * are RefundReason::merchant(), read here and written down nowhere else.
 *
 * A refund gives money back, so it is destructive, and it is never exposed to agents. It is
 * idempotent by the `Idempotency-Key` it requires: the same key with the same request names the
 * same refund for good, so a retry after a lost answer is answered with the refund it made.
 *
 * A settlement ends a refund's claim on a person's say-so: route
 * `POST seocart/v1/refund-claims/{refund_uuid}/settlement`, ability `seocart/settle-refund-claim`,
 * command `wp seocart refund settle <refund_uuid>`. It overrides what the plugin knows of the
 * money, so it needs `seocart_override_money_state`, is destructive, and is never exposed to
 * agents. It needs no idempotency key: the claim's state is one, as a claim that has ended is
 * refused.
 *
 * Declarations are data: building a definition reads the refund reasons and nothing else.
 *
 * @since 0.2.0
 */
final class PaymentOperations {

	/**
	 * The id of the refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_ORDER = 'payment.refund_order';

	/**
	 * The REST route of the refund, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_ROUTE = '/orders/{order_uuid}/refunds';

	/**
	 * The ability slug of the refund, below `seocart/`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_ABILITY = 'refund-order';

	/**
	 * The capability a refund requires.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_CAPABILITY = 'seocart_refund_orders';

	/**
	 * The longest note a refund keeps, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const NOTE_MAX_LENGTH = 500;

	/**
	 * The most lines one refund names.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_LINES = 200;

	/**
	 * The id of the settlement of a refund claim.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE_REFUND_CLAIM = 'payment.settle_refund_claim';

	/**
	 * The REST route of the settlement, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE_ROUTE = '/refund-claims/{refund_uuid}/settlement';

	/**
	 * The ability slug of the settlement, below `seocart/`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE_ABILITY = 'settle-refund-claim';

	/**
	 * The capability a settlement requires: overriding what the plugin knows of the money.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE_CAPABILITY = OrderOperations::MONEY_OVERRIDE_CAPABILITY;

	/**
	 * The longest provider's refund a settlement names, in characters: what the ledger holds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PROVIDER_REFUND_MAX_LENGTH = 191;

	/**
	 * Builds the refund.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function refundOrder(): OperationDefinition {
		return new OperationDefinition(
			id: self::REFUND_ORDER,
			label: static fn(): string => __( 'Refund an order', 'seocart' ),
			summary: 'Gives back units of an order\'s lines, and what is left of its shipping when asked, through the gateway its payment was taken by, at the order\'s own rate, and answers the refund; the Idempotency-Key header is required, and a retry with the same key and the same request is answered with the same refund, never a second one.',
			input: array(
				new FieldSpec(
					name: 'order_uuid',
					type: FieldType::Uuid,
					description: 'The public identifier of the order to refund.',
					label: static fn(): string => __( 'Order', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
					required: true
				),
				FieldSpec::objectList(
					'lines',
					'The units of each line to give back, each line at most once; may be empty only when the shipping is asked for.',
					static fn(): string => __( 'Lines', 'seocart' ),
					array( self::lineUuid(), self::quantity(), self::restock() ),
					max_items: self::MAX_LINES
				),
				new FieldSpec(
					name: 'shipping',
					type: FieldType::Boolean,
					description: 'Whether to give back what is left of the order\'s shipping; when absent, it is not given back.',
					label: static fn(): string => __( 'Refund the shipping', 'seocart' ),
					example: true
				),
				new FieldSpec(
					name: 'reason_code',
					type: FieldType::String,
					description: 'Why the order is refunded.',
					label: static fn(): string => __( 'Reason', 'seocart' ),
					example: RefundReason::CustomerReturn->value,
					required: true,
					allowed: array_map( static fn( RefundReason $reason ): string => $reason->value, RefundReason::merchant() )
				),
				self::note(),
				IdempotencyKey::field(),
			),
			output: self::refund(),
			capability: self::REFUND_CAPABILITY,
			resource_field: null,
			errors: array(
				AuthorizationError::Denied,
				OrderError::NotFound,
				PaymentError::RefundKeyMissing,
				PaymentError::RefundKeyReused,
				PaymentError::RefundRequestInvalid,
				PaymentError::RefundNoteRejected,
				PaymentError::RefundNotRefundable,
				PaymentError::GatewayUnavailable,
				PaymentError::OperationUnsupported,
				PaymentError::Unreconciled,
				PaymentError::RefundUnresolved,
				PaymentError::RefundLineNotFound,
				PaymentError::RefundLineExhausted,
				PaymentError::RefundExceedsCaptured,
				PaymentError::RefundNothingLeft,
				PaymentError::RefundCapExceeded,
				PaymentError::RefundRetry,
				PaymentError::RefundDeclined,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( RefundService::class, 'refundOrder' ),
			rest: new RestBinding(
				self::REFUND_ROUTE,
				WriteMethod::Post,
				headers: array( IdempotencyKey::FIELD => new RequestHeader( IdempotencyKey::HEADER, true ) )
			),
			ability: self::REFUND_ABILITY,
			cli: new CliBinding( array( 'order', 'refund' ), array( 'order_uuid' ) ),
			agent_exposed: false
		);
	}

	/**
	 * Builds the settlement of a refund claim.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function settleRefundClaim(): OperationDefinition {
		return new OperationDefinition(
			id: self::SETTLE_REFUND_CLAIM,
			label: static fn(): string => __( 'Settle a refund claim', 'seocart' ),
			summary: 'Ends the claim of a refund the payment gateway could not account for, on a person\'s say-so: the gateway is asked once more, and a refund it made or declined is recorded as it says; only when it cannot say does the statement decide, a refund stated made being recorded with the provider\'s refund and the amount it names and the order flagged for a person, and one stated not made ending declined with nothing recorded. A claim that has ended is refused, so a retry is safe.',
			input: array(
				new FieldSpec(
					name: 'refund_uuid',
					type: FieldType::Uuid,
					description: 'The public identifier of the refund whose claim is settled.',
					label: static fn(): string => __( 'Refund', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c',
					required: true
				),
				new FieldSpec(
					name: 'statement',
					type: FieldType::String,
					description: 'What the person states: refunded, when the payment provider gave the money back, or not_refunded, when it did not. The gateway\'s own answer, when it has one, decides instead.',
					label: static fn(): string => __( 'Statement', 'seocart' ),
					example: ClaimStatement::REFUNDED,
					required: true,
					allowed: array( ClaimStatement::REFUNDED, ClaimStatement::NOT_REFUNDED )
				),
				new FieldSpec(
					name: 'provider_refund_id',
					type: FieldType::String,
					description: 'The payment provider\'s refund, as its dashboard names it: required with refunded, refused with not_refunded.',
					label: static fn(): string => __( 'Provider refund', 'seocart' ),
					example: 're_3Q2ExampleRefund',
					max_length: self::PROVIDER_REFUND_MAX_LENGTH
				),
				new FieldSpec(
					name: 'amount_minor',
					type: FieldType::Integer,
					description: 'What the provider gave back, in minor units of the order\'s currency: required with refunded, refused with not_refunded.',
					label: static fn(): string => __( 'Amount', 'seocart' ),
					example: 2468,
					minimum: 1,
					privacy: Privacy::Financial
				),
				new FieldSpec(
					name: 'note',
					type: FieldType::String,
					description: 'Why the person states it; kept with the claim, and refused when it holds a card number.',
					label: static fn(): string => __( 'Note', 'seocart' ),
					example: 'The provider\'s dashboard shows the refund, made the day the request timed out.',
					required: true,
					max_length: self::NOTE_MAX_LENGTH,
					privacy: Privacy::Pii
				),
			),
			output: self::settlement(),
			capability: self::SETTLE_CAPABILITY,
			resource_field: null,
			errors: array(
				AuthorizationError::Denied,
				PaymentError::RefundNoteRejected,
				PaymentError::RefundStatementIncomplete,
				PaymentError::RefundClaimNotFound,
				PaymentError::RefundClaimEnded,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( RefundService::class, 'settleRefundClaim' ),
			rest: new RestBinding( self::SETTLE_ROUTE, WriteMethod::Post ),
			ability: self::SETTLE_ABILITY,
			cli: new CliBinding( array( 'refund', 'settle' ), array( 'refund_uuid' ) ),
			agent_exposed: false
		);
	}

	/**
	 * Returns the refund a refund answers with: the money it gave back, and what was asked.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function refund(): ResourceSchema {
		return new ResourceSchema(
			'Refund',
			array(
				new FieldSpec(
					name: 'refund_uuid',
					type: FieldType::Uuid,
					description: 'The refund\'s public identifier, which the gateway received as its idempotency key.',
					label: static fn(): string => __( 'Refund', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c',
					required: true
				),
				new FieldSpec(
					name: 'order_uuid',
					type: FieldType::Uuid,
					description: 'The order refunded.',
					label: static fn(): string => __( 'Order', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
					required: true
				),
				self::amount( 'total_minor', 'What the refund gave back, tax included, in minor units of the order\'s currency.', static fn(): string => __( 'Total', 'seocart' ), 2468 ),
				self::amount( 'tax_minor', 'The tax the refund gave back, in minor units of the order\'s currency.', static fn(): string => __( 'Tax', 'seocart' ), 411 ),
				self::amount( 'shipping_minor', 'The shipping the refund gave back, before tax, in minor units of the order\'s currency.', static fn(): string => __( 'Shipping', 'seocart' ), 499 ),
				self::currency( 'currency', 'The order\'s currency, ISO 4217.', static fn(): string => __( 'Currency', 'seocart' ), 'EUR' ),
				self::amount( 'base_total_minor', 'The total in minor units of the base currency, at the order\'s own rate.', static fn(): string => __( 'Total in the base currency', 'seocart' ), 2705 ),
				self::currency( 'base_currency', 'The store\'s base currency when the order was placed, ISO 4217.', static fn(): string => __( 'Base currency', 'seocart' ), 'USD' ),
				new FieldSpec(
					name: 'reason_code',
					type: FieldType::String,
					description: 'Why the order was refunded.',
					label: static fn(): string => __( 'Reason', 'seocart' ),
					example: RefundReason::CustomerReturn->value,
					required: true
				),
				self::note(),
				FieldSpec::objectList(
					'lines',
					'The units of each line the refund was asked for, in the order of the lines\' identifiers.',
					static fn(): string => __( 'Lines', 'seocart' ),
					array( self::lineUuid(), self::quantity(), self::restock() ),
					required: true
				),
			)
		);
	}

	/**
	 * Returns what a settlement answers with: how the claim ended, what decided it, what the gateway said, and whether the order holds money a person must reconcile.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function settlement(): ResourceSchema {
		return new ResourceSchema(
			'RefundClaimSettlement',
			array(
				new FieldSpec(
					name: 'refund_uuid',
					type: FieldType::Uuid,
					description: 'The refund whose claim was settled.',
					label: static fn(): string => __( 'Refund', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c',
					required: true
				),
				new FieldSpec(
					name: 'order_uuid',
					type: FieldType::Uuid,
					description: 'The order refunded.',
					label: static fn(): string => __( 'Order', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
					required: true
				),
				new FieldSpec(
					name: 'state',
					type: FieldType::String,
					description: 'How the claim ended: recorded, the refund recorded; declined, no money given back, so the same refund asked again is a new one; or unreconciled, money left for a person to reconcile.',
					label: static fn(): string => __( 'State', 'seocart' ),
					example: 'recorded',
					required: true
				),
				new FieldSpec(
					name: 'decided_by',
					type: FieldType::String,
					description: 'What decided how the claim ended: gateway, when the payment gateway made or declined the refund, or statement, when it could not say and the person\'s statement decided.',
					label: static fn(): string => __( 'Decided by', 'seocart' ),
					example: SettledClaim::BY_STATEMENT,
					required: true
				),
				new FieldSpec(
					name: 'gateway_reading',
					type: FieldType::String,
					description: 'What the payment gateway said when it was asked once more: approved, declined, not_found, cannot_say, or unavailable, with why after a colon when known, such as unavailable:not_registered.',
					label: static fn(): string => __( 'Gateway reading', 'seocart' ),
					example: SettledClaim::NOT_FOUND,
					required: true
				),
				new FieldSpec(
					name: 'has_unreconciled_money',
					type: FieldType::Boolean,
					description: 'Whether the order holds money a person must reconcile now: true after a refund recorded on a person\'s statement, until a person clears it.',
					label: static fn(): string => __( 'Unreconciled money', 'seocart' ),
					example: true,
					required: true
				),
			)
		);
	}

	/**
	 * Returns a line's identifier in a refund.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function lineUuid(): FieldSpec {
		return new FieldSpec(
			name: 'line_uuid',
			type: FieldType::Uuid,
			description: 'The public identifier of the order line.',
			label: static fn(): string => __( 'Line', 'seocart' ),
			example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6d',
			required: true
		);
	}

	/**
	 * Returns the units of a line in a refund.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function quantity(): FieldSpec {
		return new FieldSpec(
			name: 'quantity',
			type: FieldType::Integer,
			description: 'How many of the line\'s units to give back.',
			label: static fn(): string => __( 'Quantity', 'seocart' ),
			example: 1,
			required: true,
			minimum: 1
		);
	}

	/**
	 * Returns whether a line's units go back into stock.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function restock(): FieldSpec {
		return new FieldSpec(
			name: 'restock',
			type: FieldType::Boolean,
			description: 'Whether the units go back into stock: recorded with the refund, not yet acted on; when absent, they do not.',
			label: static fn(): string => __( 'Restock', 'seocart' ),
			example: false
		);
	}

	/**
	 * Returns a refund's note: personal data, kept with the refund.
	 *
	 * @since 0.2.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function note(): FieldSpec {
		return new FieldSpec(
			name: 'note',
			type: FieldType::String,
			description: 'What the person who refunds writes about the refund; kept with it, and refused when it holds a card number.',
			label: static fn(): string => __( 'Note', 'seocart' ),
			example: 'The box arrived crushed.',
			max_length: self::NOTE_MAX_LENGTH,
			privacy: Privacy::Pii
		);
	}

	/**
	 * Returns an amount in minor units.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The field's name.
	 * @param string   $description What it is.
	 * @param \Closure $label       Its label, translated when called.
	 * @param int      $example     An example.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function amount( string $name, string $description, \Closure $label, int $example ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::Integer, description: $description, label: $label, example: $example, required: true, privacy: Privacy::Financial );
	}

	/**
	 * Returns a currency code.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The field's name.
	 * @param string   $description What it is.
	 * @param \Closure $label       Its label, translated when called.
	 * @param string   $example     An example.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function currency( string $name, string $description, \Closure $label, string $example ): FieldSpec {
		return new FieldSpec( name: $name, type: FieldType::String, description: $description, label: $label, example: $example, required: true, max_length: 3 );
	}
}
