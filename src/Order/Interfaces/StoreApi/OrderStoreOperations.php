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
use SEOCart\Order\Domain\FulfillmentStatus;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `order.get_status`, a shopper's read of one order, and `order.list_own`, a customer's list of their own orders, on the Store API.
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
 * The list is `GET seocart/store/v1/account/orders`: the orders of the customer the logged-in
 * actor is, newest first, a page at a time, granted by the same owner test alone. A key opens one
 * order and never a list, so the list takes none, and a visitor, or a login cookie sent without
 * its REST nonce, which WordPress serves as a visitor, is listed nothing. Each row names the order
 * by its uuid, number, dates, statuses and total: no address and no email.
 *
 * Declarations are data: building the definition calls no WordPress function.
 *
 * @since 0.1.0
 * @since 0.2.0 The list of a customer's own orders.
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
	 * The id of the list of a customer's own orders.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_OWN = 'order.list_own';

	/**
	 * The route of the list, relative to the Store API's namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_ROUTE = '/account/orders';

	/**
	 * The name of the resource the list answers with: a page of order summaries.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_RESOURCE = 'StoreOrderList';

	/**
	 * The most orders a page of the list holds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PER_PAGE = 20;

	/**
	 * How many orders a page holds when the request does not say.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DEFAULT_PER_PAGE = 10;

	/**
	 * The last page a request may ask for: far past any customer's orders, and small enough that the page's offset stays an integer.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PAGE = 1000000;

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
					new FieldSpec(
						name: 'transfer_days',
						type: FieldType::Integer,
						description: 'The extra days the shopper was told, when the order was placed, that bringing its items together adds to delivery; null when the order needed no transfer. It is what was told then, whatever the store says now.',
						label: static fn(): string => __( 'Extra days for a transfer', 'seocart' ),
						example: 3,
						nullable: true,
						minimum: 0
					),
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
	 * Builds the list of a customer's own orders.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function listOwn(): OperationDefinition {
		return new OperationDefinition(
			id: self::LIST_OWN,
			label: static fn(): string => __( 'List my orders', 'seocart' ),
			summary: 'Lists a page of the logged-in customer\'s own orders, newest first, declined attempts left out, each by its uuid, number, statuses and total; a visitor, and a key, are listed nothing.',
			input: array(
				new FieldSpec(
					name: 'page',
					type: FieldType::Integer,
					description: 'The page to list, from 1 to ' . self::MOST_PAGE . '.',
					label: static fn(): string => __( 'Page', 'seocart' ),
					example: 1,
					default_value: 1,
					minimum: 1,
					maximum: self::MOST_PAGE
				),
				new FieldSpec(
					name: 'per_page',
					type: FieldType::Integer,
					description: 'How many orders a page holds, from 1 to ' . self::MOST_PER_PAGE . '.',
					label: static fn(): string => __( 'Orders per page', 'seocart' ),
					example: self::DEFAULT_PER_PAGE,
					default_value: self::DEFAULT_PER_PAGE,
					minimum: 1,
					maximum: self::MOST_PER_PAGE
				),
			),
			output: new ResourceSchema(
				self::LIST_RESOURCE,
				array(
					FieldSpec::objectList(
						'orders',
						'The customer\'s orders on the page, newest first; none for an actor who is no customer.',
						static fn(): string => __( 'Orders', 'seocart' ),
						self::summary(),
						required: true,
						max_items: self::MOST_PER_PAGE
					),
					new FieldSpec(
						name: 'has_more',
						type: FieldType::Boolean,
						description: 'Whether at least one more order follows this page.',
						label: static fn(): string => __( 'More orders', 'seocart' ),
						example: false,
						required: true
					),
				)
			),
			capability: null,
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( OwnOrdersRead::class, 'list' ),
			rest: new RestBinding( self::LIST_ROUTE, store: true )
		);
	}

	/**
	 * Declares one order of the list, a StoreOrderSummary: what names it, and no address and no email.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function summary(): array {
		return array(
			self::uuid( 'The order\'s public identifier, which its status read takes.' ),
			new FieldSpec(
				name: 'order_number',
				type: FieldType::String,
				description: 'The number shown to people on the order and in its emails.',
				label: static fn(): string => __( 'Order number', 'seocart' ),
				example: '000042',
				required: true
			),
			new FieldSpec(
				name: 'placed_at',
				type: FieldType::String,
				description: 'When the order was placed, UTC, in ISO 8601, by the database clock.',
				label: static fn(): string => __( 'Placed', 'seocart' ),
				example: '2026-11-14T09:30:00Z',
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
				name: 'fulfillment_status',
				type: FieldType::String,
				description: 'How much of the order has been shipped or returned.',
				label: static fn(): string => __( 'Fulfillment status', 'seocart' ),
				example: FulfillmentStatus::Unfulfilled->value,
				required: true,
				allowed: array_map( static fn( FulfillmentStatus $status ): string => $status->value, FulfillmentStatus::cases() )
			),
			new FieldSpec(
				name: 'currency',
				type: FieldType::String,
				description: 'The ISO 4217 code of the currency the total is in.',
				label: static fn(): string => __( 'Currency', 'seocart' ),
				example: 'USD',
				required: true
			),
			self::amount( 'grand_total_minor', 'The order\'s total, in minor units.', static fn(): string => __( 'Total', 'seocart' ) ),
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
