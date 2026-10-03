<?php
/**
 * OrderStoreOperations: the Store API's operations on orders
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Interfaces\StoreApi;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RequestHeader;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\AmountBasis;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `order.get_status`: a shopper's read of one order, on the Store API.
 *
 * Owns one fact: how an order is offered to a storefront. The route is
 * `GET seocart/store/v1/orders/{uuid}`: an order is named by its uuid, never by its internal id or
 * its number. The read is public, so anyone may send it, and the service answers only whoever
 * OrderAccessPolicy lets see the order: its customer, or whoever presents its access key in the
 * header KEY_HEADER. The key is a header input, declared once as the secret `order_key`: the
 * route reads it from that header and nowhere else, so a key in a URL, which would land in
 * web-server, proxy, browser-history and analytics logs that the plugin's redaction cannot reach,
 * is refused as a bad request before the service runs, and says nothing about the order. Every
 * other refusal is `order.not_found`, so an answer never tells an order that exists from one that
 * is not the caller's.
 *
 * The answer carries the order's number, its statuses, its totals and its lines as the order
 * recorded them when it was sold, and no address and no email: a status page needs neither, so no
 * field of the resource is personal data. The key is a secret input, which no answer carries and
 * the logs redact; its name, `order_key`, is the order's alone, so the redaction reaches nothing
 * else a log line carries.
 *
 * Declarations are data: building the definition calls no WordPress function.
 *
 * @since 0.1.0
 */
final class OrderStoreOperations {

	/**
	 * The id of the status read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GET_STATUS = 'order.get_status';

	/**
	 * The route of the status read, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STATUS_ROUTE = '/orders/{uuid}';

	/**
	 * The request header that carries the access key, the only place the read accepts it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY_HEADER = 'X-SEOCart-Order-Key';

	/**
	 * The input that carries the access key, read from the header KEY_HEADER.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const KEY = 'order_key';

	/**
	 * The name of the resource the status read answers with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RESOURCE = 'StoreOrder';

	/**
	 * Builds the status read.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getStatus(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_STATUS,
			label: static fn(): string => __( 'Get an order\'s status', 'seocart' ),
			summary: 'Returns an order\'s number, statuses, totals and lines to its customer, or to whoever presents its access key in the X-SEOCart-Order-Key header; it carries no address and no email, and every refusal is order.not_found.',
			input: array(
				self::uuid( 'The order\'s public identifier, from its confirmation.' ),
				new FieldSpec(
					name: self::KEY,
					type: FieldType::String,
					description: 'The order\'s access key, sent in the X-SEOCart-Order-Key header and nowhere else: a key in the URL is refused.',
					label: static fn(): string => __( 'Access key', 'seocart' ),
					example: '0123456789abcdef0123456789abcdef',
					max_length: 64,
					privacy: Privacy::Secret
				),
			),
			output: new ResourceSchema(
				self::RESOURCE,
				array(
					self::uuid( 'The order\'s public identifier.' ),
					new FieldSpec(
						name: 'order_number',
						type: FieldType::String,
						description: 'The number shown to people on the order and in its emails; a request never names an order by it.',
						label: static fn(): string => __( 'Order number', 'seocart' ),
						example: '000042',
						required: true
					),
					new FieldSpec(
						name: 'status',
						type: FieldType::String,
						description: 'Where the order is in its life, from pending payment to completed.',
						label: static fn(): string => __( 'Status', 'seocart' ),
						example: OrderStatus::Processing->value,
						required: true,
						allowed: array_map( static fn( OrderStatus $status ): string => $status->value, OrderStatus::cases() )
					),
					new FieldSpec(
						name: 'payment_status',
						type: FieldType::String,
						description: 'How far the order has been paid, or refunded.',
						label: static fn(): string => __( 'Payment status', 'seocart' ),
						example: PaymentStatus::Paid->value,
						required: true,
						allowed: array_map( static fn( PaymentStatus $status ): string => $status->value, PaymentStatus::cases() )
					),
					new FieldSpec(
						name: 'currency',
						type: FieldType::String,
						description: 'The ISO 4217 code of the currency every amount is in.',
						label: static fn(): string => __( 'Currency', 'seocart' ),
						example: 'USD',
						required: true
					),
					self::amount( 'subtotal_minor', 'The lines before discounts, in minor units.', static fn(): string => __( 'Subtotal', 'seocart' ) ),
					self::amount( 'discount_total_minor', 'Every discount, in minor units.', static fn(): string => __( 'Discounts', 'seocart' ) ),
					self::amount( 'shipping_total_minor', 'Shipping, in minor units.', static fn(): string => __( 'Shipping', 'seocart' ) ),
					self::amount( 'fee_total_minor', 'Every fee, in minor units.', static fn(): string => __( 'Fees', 'seocart' ) ),
					self::amount( 'tax_total_minor', 'Every tax, in minor units.', static fn(): string => __( 'Tax', 'seocart' ) ),
					self::amount( 'grand_total_minor', 'The order\'s total, in minor units.', static fn(): string => __( 'Total', 'seocart' ) ),
					self::amount( 'paid_minor', 'What has been captured so far, in minor units.', static fn(): string => __( 'Paid', 'seocart' ) ),
					self::amount( 'refunded_minor', 'What has been refunded so far, in minor units.', static fn(): string => __( 'Refunded', 'seocart' ) ),
					self::amount( 'due_minor', 'What is still to be paid, in minor units.', static fn(): string => __( 'Due', 'seocart' ) ),
					self::lines(),
				)
			),
			capability: null,
			resource_field: null,
			errors: array( OrderError::NotFound ),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( OrderStatusRead::class, 'read' ),
			rest: new RestBinding( self::STATUS_ROUTE, store: true, headers: array( self::KEY => new RequestHeader( self::KEY_HEADER ) ) )
		);
	}

	/**
	 * Declares the order's lines, each as the order recorded it when it was sold: never read from the catalog.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function lines(): FieldSpec {
		return FieldSpec::objectList(
			'lines',
			'The order\'s lines, in their order.',
			static fn(): string => __( 'Lines', 'seocart' ),
			array(
				new FieldSpec(
					name: 'line_uuid',
					type: FieldType::Uuid,
					description: 'The line\'s public identifier.',
					label: static fn(): string => __( 'Line', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6c',
					required: true
				),
				self::text( 'title', 'The product\'s name as it was sold.', static fn(): string => __( 'Product', 'seocart' ), 'Tee' ),
				self::text( 'variant_label', 'The variant as it was sold, such as a size; empty for a product without variants.', static fn(): string => __( 'Variant', 'seocart' ), 'Medium' ),
				self::text( 'sku', 'The SKU as it was sold.', static fn(): string => __( 'SKU', 'seocart' ), 'TEE-M' ),
				new FieldSpec(
					name: 'quantity',
					type: FieldType::Integer,
					description: 'The units sold.',
					label: static fn(): string => __( 'Quantity', 'seocart' ),
					example: 2,
					required: true
				),
				self::amount( 'unit_price_minor', 'The price of one unit as authored, in minor units.', static fn(): string => __( 'Unit price', 'seocart' ) ),
				new FieldSpec(
					name: 'unit_amount_basis',
					type: FieldType::String,
					description: 'Whether the unit price was authored net or gross of tax.',
					label: static fn(): string => __( 'Unit price basis', 'seocart' ),
					example: AmountBasis::Net->value,
					required: true,
					allowed: array_map( static fn( AmountBasis $basis ): string => $basis->value, AmountBasis::cases() )
				),
				self::amount( 'line_subtotal_minor', 'The unit price times the quantity, before discounts, in minor units.', static fn(): string => __( 'Line subtotal', 'seocart' ) ),
				self::amount( 'line_discount_minor', 'The discounts given on the line, in minor units.', static fn(): string => __( 'Line discount', 'seocart' ) ),
				self::amount( 'line_tax_minor', 'The tax on the line after discounts, in minor units.', static fn(): string => __( 'Line tax', 'seocart' ) ),
				self::amount( 'line_total_minor', 'The line\'s total as the order shows it, in minor units.', static fn(): string => __( 'Line total', 'seocart' ) ),
			),
			required: true,
			min_items: 1
		);
	}

	/**
	 * Declares the order's uuid, the same field in the input and in the output.
	 *
	 * @since 0.1.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field.
	 */
	private static function uuid( string $description ): FieldSpec {
		return new FieldSpec(
			name: 'uuid',
			type: FieldType::Uuid,
			description: $description,
			label: static fn(): string => __( 'Order', 'seocart' ),
			example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
			required: true
		);
	}

	/**
	 * Declares one of a line's texts: a required string, as the order recorded it.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param string   $example     A value the field accepts.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function text( string $name, string $description, \Closure $label, string $example ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: $description,
			label: $label,
			example: $example,
			required: true
		);
	}

	/**
	 * Declares one of the order's amounts: a required integer of minor units in the order's currency.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function amount( string $name, string $description, \Closure $label ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: 1299,
			required: true,
			privacy: Privacy::Financial
		);
	}
}
