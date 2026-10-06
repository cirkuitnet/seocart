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
use SEOCart\Payment\Domain\Refund\RefundReason;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `payment.refund_order`: a merchant's refund of units of an order's lines, and of what is left of its shipping.
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
 * Declarations are data: building the definition reads the refund reasons and nothing else.
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
