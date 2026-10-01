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
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Interfaces\StoreApi\CartOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Checkout\Application\UpdateCheckoutSession;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Interfaces\TotalsFields;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the checkout's operations.
 *
 * Owns one fact: how a client tells the checkout what it needs before an order is placed.
 *
 * - `checkout.update_session`, `PUT seocart/store/v1/checkout`: replaces the checkout details of
 *   the request's cart: the billing and shipping addresses and the shipping and payment methods.
 *   A detail the request leaves out is cleared. The write rides the cart's version, like every
 *   cart write: it is refused with `cart.version_stale` when the cart has moved on, and it moves
 *   the version on, which makes the session's frozen quotes stale. The answer is the session, the
 *   cart's new version and its totals, which carry the shipping once a shipping address is set.
 *
 * The operation is public, so it is a route of the Store API only, guarded by its request policy
 * and counted in the cart writes' rate limit: a checkout write is a cart write. The addresses are
 * personal data: a guest's answer carries each address as an object without its fields.
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
	 * The route of the checkout, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE = '/checkout';

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
