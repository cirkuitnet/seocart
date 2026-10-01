<?php
/**
 * CheckoutOperations: the declarations of the checkout's Store API operations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Interfaces\StoreApi;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RequestHeader;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CurrencyChangeLimit;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Application\ChangeCartCurrency;
use SEOCart\Checkout\Application\PlaceOrder;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Interfaces\TotalsFields;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the checkout's operations.
 *
 * Owns one fact: how a client tells the checkout what it needs before an order is placed, chooses
 * the currency it pays in, and places it.
 *
 * - `checkout.update_session`, `PUT seocart/store/v1/checkout`: replaces the checkout details of
 *   the request's cart: the billing and shipping addresses and the shipping and payment methods.
 *   A detail the request leaves out is cleared. The write rides the cart's version, like every
 *   cart write: it is refused with `cart.version_stale` when the cart has moved on, and it moves
 *   the version on, which makes the session's frozen quotes stale. The answer is the session, the
 *   cart's new version and its totals, which carry the shipping once a shipping address is set.
 *
 * - `checkout.place_order`, `POST seocart/store/v1/checkout`: places an order from the request's
 *   cart, at the version and for the grand total the client read, and has the payment authorized.
 *   The `Idempotency-Key` header is required, a new key for each attempt and the same one when it
 *   is retried: a retry of a placement that went through gets its first answer again, its access
 *   key included, and never a second order.
 *
 * - `checkout.change_currency`, `POST seocart/store/v1/cart/currency`: switches the request's cart
 *   to another currency the store sells in, at the version the client read. The switch moves the
 *   version on and drops the session's frozen quotes, and its answer is the cart, priced in the
 *   new currency. A cart placing an order refuses it.
 *
 * The operations are public, so they are routes of the Store API only, guarded by their request
 * policy. The session write and the currency switch are counted in the cart writes' rate limit: a
 * checkout write is a cart write, and a cart also counts its own switches; a placement has a limit
 * of its own, per client. The addresses are personal data: a
 * guest's answer carries each address as an object without its fields; the order's access key and
 * what the client sends for the gateway are secrets.
 *
 * Declarations are data: building a definition calls no WordPress function.
 *
 * @since 0.1.0
 */
final class CheckoutOperations {

	/**
	 * The id of the session write.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_SESSION = 'checkout.update_session';

	/**
	 * The id of the order placement: the scope its idempotency keys are claimed in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PLACE_ORDER = IdempotencyClaim::PLACE_ORDER_SCOPE;

	/**
	 * The id of the switch of the cart's currency.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CHANGE_CURRENCY = 'checkout.change_currency';

	/**
	 * The header a placement's idempotency key is sent in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

	/**
	 * How long a client should wait before it sends again a placement whose key another request is placing with, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const RETRY_AFTER_SECONDS = 2;

	/**
	 * What the rate limit of placements counts, per client.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PLACE_BUCKET = 'checkout.place';

	/**
	 * The most placements one client may send in a window: five an hour, whatever their carts.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PLACE_LIMIT = 5;

	/**
	 * The window placements are counted in: an hour.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PLACE_WINDOW = 3600;

	/**
	 * The route of the checkout, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE = '/checkout';

	/**
	 * The route of the cart's currency, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CURRENCY_ROUTE = '/cart/currency';

	/**
	 * The payment methods a shopper may choose.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public const PAYMENT_METHODS = array( 'stub' );

	/**
	 * Declares the session write.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateSession(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_SESSION,
			label: static fn(): string => __( 'Update the checkout', 'seocart' ),
			summary: 'Replaces the checkout details of the request\'s cart, the billing and shipping addresses and the shipping and payment methods, clearing each one the request leaves out; the write moves the cart\'s version on, and the answer carries the session, the new version and the totals, shipping included once a shipping address is set.',
			input: array(
				CartOperations::cartVersion( 1 ),
				self::address( 'billing_address', 'The address the payment is billed to; leave it out to clear it.', static fn(): string => __( 'Billing address', 'seocart' ), false ),
				self::address( 'shipping_address', 'Where the order ships; the totals charge shipping to it. Leave it out to clear it.', static fn(): string => __( 'Shipping address', 'seocart' ), false ),
				self::shippingMethodKey( false ),
				self::paymentMethodKey( false ),
			),
			output: new ResourceSchema(
				'CheckoutSession',
				array(
					FieldSpec::object(
						'checkout_session',
						'The checkout details the cart now has.',
						static fn(): string => __( 'Checkout', 'seocart' ),
						array(
							self::address( 'billing_address', 'The address the payment is billed to, or null when none was given.', static fn(): string => __( 'Billing address', 'seocart' ), true ),
							self::address( 'shipping_address', 'Where the order ships, or null when none was given.', static fn(): string => __( 'Shipping address', 'seocart' ), true ),
							self::shippingMethodKey( true ),
							self::paymentMethodKey( true ),
						),
						required: true
					),
					new FieldSpec(
						name: 'version',
						type: FieldType::Integer,
						description: 'The cart\'s version after the write. Send it with the next write, and discard an answer whose version is lower than one already seen.',
						label: static fn(): string => __( 'Version', 'seocart' ),
						example: 4,
						required: true,
						minimum: 1
					),
					TotalsFields::totals( 'totals', 'The cart\'s totals after the write, worked out for the shipping address and method now chosen.', static fn(): string => __( 'Totals', 'seocart' ) ),
				)
			),
			capability: null,
			resource_field: null,
			errors: array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CheckoutError::InvalidAddress, CheckoutError::InvalidMethodKey, PricingError::QuoteUnavailable, PricingError::NoShippingRate, PricingError::CurrencyNotEnabled ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( UpdateCheckoutSession::class, 'update' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Put, store: true ),
			public_write: StoreRequestPolicy::write( CartOperations::WRITE_BUCKET, CartOperations::WRITE_LIMIT, CartOperations::WRITE_WINDOW, true )
		);
	}

	/**
	 * Declares the order placement.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function placeOrder(): OperationDefinition {
		return new OperationDefinition(
			id: self::PLACE_ORDER,
			label: static fn(): string => __( 'Place the order', 'seocart' ),
			summary: 'Places an order from the request\'s cart, at the cart version and for the grand total the client read, and has its payment authorized; the Idempotency-Key header is required. A retry with the same key never places a second order: it is answered 200 with the placement as it stands now, the same order and order key, its outcome and statuses as the settlement left them, and a declined placement is answered so too, with the outcome declined, since refusals are not kept.',
			input: array(
				CartOperations::cartVersion( 1 ),
				new FieldSpec(
					name: 'grand_total_minor',
					type: FieldType::Integer,
					description: 'The grand total the shopper agreed to, in minor units of the cart\'s currency, as the cart\'s last answer gave it; a cart whose total is now another is refused with checkout.totals_changed.',
					label: static fn(): string => __( 'Total', 'seocart' ),
					example: 3080,
					required: true,
					minimum: 0,
					privacy: Privacy::Financial
				),
				new FieldSpec(
					name: 'currency',
					type: FieldType::String,
					description: 'The ISO 4217 code of the cart\'s currency, as the cart\'s last answer gave it.',
					label: static fn(): string => __( 'Currency', 'seocart' ),
					example: 'USD',
					required: true,
					max_length: 3
				),
				FieldSpec::object(
					'payment_data',
					'What the client sends the payment gateway, such as the token of the shopper\'s payment method.',
					static fn(): string => __( 'Payment', 'seocart' ),
					array(
						new FieldSpec(
							name: 'payment_token',
							type: FieldType::String,
							description: 'The gateway\'s token for the shopper\'s payment method, never a card number.',
							label: static fn(): string => __( 'Payment token', 'seocart' ),
							example: 'stub:approve',
							max_length: 191,
							privacy: Privacy::Secret
						),
					)
				),
				new FieldSpec(
					name: 'idempotency_key',
					type: FieldType::String,
					description: 'The attempt\'s idempotency key, sent in the Idempotency-Key header and nowhere else: a new key, such as a UUID, for each attempt, and the same key when it is retried.',
					label: static fn(): string => __( 'Idempotency key', 'seocart' ),
					example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
					max_length: IdempotencyClaim::MAX_KEY_LENGTH
				),
			),
			output: new ResourceSchema(
				'Placement',
				array(
					new FieldSpec(
						name: 'order_uuid',
						type: FieldType::Uuid,
						description: 'The order\'s public identifier, by which its status is read.',
						label: static fn(): string => __( 'Order', 'seocart' ),
						example: '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6b',
						required: true
					),
					new FieldSpec(
						name: 'order_number',
						type: FieldType::String,
						description: 'The number shown to people on the order and in its emails.',
						label: static fn(): string => __( 'Order number', 'seocart' ),
						example: '000042',
						required: true
					),
					// The key is the client's own, minted for it: this answer must carry it, so it is not a secret field, which no answer carries. Its name is the status read's secret input, so the logs drop it by name.
					new FieldSpec(
						name: 'order_key',
						type: FieldType::String,
						description: 'The order\'s access key, which reads its status without a login, as the status read\'s order_key. It is shown in this answer, and again to a retry of the same request only: to the holder of the cart token and of the idempotency key it was placed with, since the store keeps the key only as a hash and sealed with those two, which it never keeps. Send a random idempotency key, such as a UUID. A retry whose kept key cannot be opened is answered without it; the client must keep it.',
						label: static fn(): string => __( 'Order key', 'seocart' ),
						example: '0123456789abcdef0123456789abcdef'
					),
					new FieldSpec(
						name: 'cart_version',
						type: FieldType::Integer,
						description: 'The cart\'s version once the order was placed.',
						label: static fn(): string => __( 'Cart version', 'seocart' ),
						example: 4,
						required: true,
						minimum: 1
					),
					new FieldSpec(
						name: 'outcome',
						type: FieldType::String,
						description: 'What the placement came to: approved, requires_action when the shopper must act, processing while the gateway decides, or, given to a retry with the same key, any outcome the placement came to since, declined included; pending only while the payment is not yet known.',
						label: static fn(): string => __( 'Outcome', 'seocart' ),
						example: PlacementOutcome::Approved->value,
						required: true,
						allowed: array_map( static fn( PlacementOutcome $outcome ): string => $outcome->value, PlacementOutcome::cases() )
					),
					new FieldSpec(
						name: 'status',
						type: FieldType::String,
						description: 'The order\'s status once the placement settled.',
						label: static fn(): string => __( 'Status', 'seocart' ),
						example: OrderStatus::Processing->value,
						required: true,
						allowed: array_map( static fn( OrderStatus $status ): string => $status->value, OrderStatus::cases() )
					),
					new FieldSpec(
						name: 'payment_status',
						type: FieldType::String,
						description: 'How far the order is paid once the placement settled.',
						label: static fn(): string => __( 'Payment status', 'seocart' ),
						example: PaymentStatus::Authorized->value,
						required: true,
						allowed: array_map( static fn( PaymentStatus $status ): string => $status->value, PaymentStatus::cases() )
					),
				)
			),
			capability: null,
			resource_field: null,
			errors: array(
				CheckoutError::IdempotencyKeyMissing,
				CheckoutError::IdempotencyKeyReused,
				CheckoutError::PlacementInProgress,
				CartError::NotFound,
				CartError::NotOpen,
				CartError::VersionStale,
				CheckoutError::CartEmpty,
				CheckoutError::SessionIncomplete,
				CheckoutError::LineUnsellable,
				CheckoutError::TotalsChanged,
				InventoryError::Insufficient,
				PromotionError::LimitReached,
				CheckoutError::PaymentDeclined,
				CheckoutError::GatewayUnavailable,
				PricingError::QuoteUnavailable,
				PricingError::NoShippingRate,
				PricingError::CurrencyNotEnabled,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( PlaceOrder::class, 'place' ),
			rest: new RestBinding(
				self::ROUTE,
				WriteMethod::Post,
				store: true,
				headers: array( 'idempotency_key' => new RequestHeader( self::IDEMPOTENCY_HEADER, true ) ),
				retry_after: array( CheckoutError::PlacementInProgress->value => self::RETRY_AFTER_SECONDS )
			),
			public_write: StoreRequestPolicy::write( self::PLACE_BUCKET, self::PLACE_LIMIT, self::PLACE_WINDOW, true )
		);
	}

	/**
	 * Declares the switch of the cart's currency.
	 *
	 * The answer is the cart, declared once by the cart's read.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function changeCurrency(): OperationDefinition {
		return new OperationDefinition(
			id: self::CHANGE_CURRENCY,
			label: static fn(): string => __( 'Change the cart\'s currency', 'seocart' ),
			summary: 'Switches the request\'s cart to another currency the store sells in, at the cart version the client read: the switch moves the version on, drops the checkout\'s shipping and tax quotes, keeps the shipping method chosen, and answers the cart priced in the new currency; a switch to the currency the cart is in already is accepted like any other. A cart placing an order refuses it with cart.not_open, a cart switches at most ' . CurrencyChangeLimit::LIMIT . ' times in an hour, and a currency the store does not sell in gets checkout.currency_not_enabled, whatever the reason.',
			input: array(
				CartOperations::cartVersion( 1 ),
				new FieldSpec(
					name: 'currency',
					type: FieldType::String,
					description: 'The ISO 4217 code of the currency to switch to, in upper case, such as EUR: the store\'s base currency, or another it sells in.',
					label: static fn(): string => __( 'Currency', 'seocart' ),
					example: 'EUR',
					required: true,
					max_length: 3
				),
			),
			output: CartOperations::getCart()->output(),
			capability: null,
			resource_field: null,
			errors: array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CheckoutError::CurrencyNotEnabled, PricingError::QuoteUnavailable, PricingError::NoShippingRate, PricingError::CurrencyNotEnabled ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( ChangeCartCurrency::class, 'change' ),
			rest: new RestBinding( self::CURRENCY_ROUTE, WriteMethod::Post, store: true ),
			public_write: StoreRequestPolicy::write( CartOperations::WRITE_BUCKET, CartOperations::WRITE_LIMIT, CartOperations::WRITE_WINDOW, true )
		);
	}

	/**
	 * Returns an address field: an object of the address's fields, each personal data.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the form label.
	 * @param bool     $output      True for an answer's address, which is null when none was given;
	 *                              false for a request's, which is left out instead.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function address( string $name, string $description, \Closure $label, bool $output ): FieldSpec {
		$text = static fn( string $field, string $about, \Closure $fieldLabel, string $example, bool $required = false ): FieldSpec => new FieldSpec(
			name: $field,
			type: FieldType::String,
			description: $about,
			label: $fieldLabel,
			example: $example,
			required: $required,
			max_length: AddressDocument::FIELDS[ $field ],
			privacy: Privacy::Pii
		);

		return FieldSpec::object(
			$name,
			$description,
			$label,
			array(
				$text( 'country', 'The ISO 3166-1 alpha-2 country code, two upper-case letters.', static fn(): string => __( 'Country', 'seocart' ), 'GB', true ),
				$text( 'first_name', 'The given name.', static fn(): string => __( 'First name', 'seocart' ), 'Ada' ),
				$text( 'last_name', 'The family name.', static fn(): string => __( 'Last name', 'seocart' ), 'Lovelace' ),
				$text( 'company', 'The company name.', static fn(): string => __( 'Company', 'seocart' ), 'Analytical Engines Ltd' ),
				$text( 'line1', 'The first address line.', static fn(): string => __( 'Address line 1', 'seocart' ), '12 St James\'s Square' ),
				$text( 'line2', 'The second address line.', static fn(): string => __( 'Address line 2', 'seocart' ), 'Flat 3' ),
				$text( 'city', 'The city or town.', static fn(): string => __( 'City', 'seocart' ), 'London' ),
				$text( 'region', 'The state, province, county or other subdivision.', static fn(): string => __( 'Region', 'seocart' ), 'Greater London' ),
				$text( 'postcode', 'The postal code.', static fn(): string => __( 'Postcode', 'seocart' ), 'SW1Y 4JH' ),
				$text( 'phone', 'The phone number.', static fn(): string => __( 'Phone', 'seocart' ), '+44 20 7946 0000' ),
				$text( 'email', 'The e-mail address.', static fn(): string => __( 'Email address', 'seocart' ), 'ada@example.com' ),
				$text( 'tax_id', 'The tax identifier, such as a VAT number.', static fn(): string => __( 'Tax ID', 'seocart' ), 'GB000000000' ),
			),
			nullable: $output
		);
	}

	/**
	 * Returns the shipping method chosen.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $output True for an answer's, which is null when none was chosen.
	 * @return FieldSpec The field.
	 */
	private static function shippingMethodKey( bool $output ): FieldSpec {
		return new FieldSpec(
			name: 'shipping_method_key',
			type: FieldType::String,
			description: $output
				? 'The shipping method chosen, or null for the cheapest one quoted.'
				: 'The shipping method chosen, a key of lower-case letters, digits, dots, colons, hyphens and underscores; leave it out for the cheapest one quoted.',
			label: static fn(): string => __( 'Shipping method', 'seocart' ),
			example: 'flat',
			nullable: $output,
			max_length: CheckoutDetails::METHOD_KEY_MAX_LENGTH
		);
	}

	/**
	 * Returns the payment method chosen.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $output True for an answer's, which is null when none was chosen.
	 * @return FieldSpec The field.
	 */
	private static function paymentMethodKey( bool $output ): FieldSpec {
		return new FieldSpec(
			name: 'payment_method_key',
			type: FieldType::String,
			description: $output
				? 'The payment method chosen, or null when none was.'
				: 'The payment method chosen; leave it out to clear it.',
			label: static fn(): string => __( 'Payment method', 'seocart' ),
			example: 'stub',
			nullable: $output,
			allowed: $output ? array() : self::PAYMENT_METHODS
		);
	}
}
