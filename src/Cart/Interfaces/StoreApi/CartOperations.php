<?php
/**
 * CartOperations: the Store API operations of the cart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Interfaces\StoreApi;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Pricing\Application\UnpricedLine;
use SEOCart\Pricing\Domain\PricingError;
use SEOCart\Pricing\Interfaces\TotalsFields;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the cart's operations: read the cart, add lines to it, change one of its lines.
 *
 * Owns one fact: how a client reads and changes its cart. Each is public, so each is a route of
 * the Store API only, never an ability or a command, and every write is guarded by the Store
 * API's request policy.
 *
 * - `cart.get_cart`, `GET seocart/store/v1/cart`: the cart the request's token names, or an empty
 *   cart at version 0 when there is none. It never creates a cart and never sets a cookie.
 * - `cart.add_lines`, `POST seocart/store/v1/cart/lines`: adds one to fifty lines at once. The
 *   write that finds no cart starts one, and its answer carries the new cart's token.
 * - `cart.update_line`, `PATCH seocart/store/v1/cart/lines/{line_identity}`: sets a line's
 *   quantity; 0 removes the line.
 *
 * Every write carries the cart version it was based on, and a write based on an older version is
 * refused with `cart.version_stale`, whose details carry the cart's current totals, so a client can
 * tell an answer it has overtaken and repaint. Every answer is the cart: its version, its lines, the
 * totals the calculation works out for them, and the lines it could not price.
 *
 * Declarations are data: building a definition calls no WordPress function.
 *
 * @since 0.1.0
 */
final class CartOperations {

	/**
	 * The id of the read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GET_CART = 'cart.get_cart';

	/**
	 * The id of the write that adds lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADD_LINES = 'cart.add_lines';

	/**
	 * The id of the write that changes one line.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UPDATE_LINE = 'cart.update_line';

	/**
	 * The route of the cart, relative to the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CART_ROUTE = '/cart';

	/**
	 * The route of the cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINES_ROUTE = '/cart/lines';

	/**
	 * The route of one line, named by its identity.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const LINE_ROUTE = '/cart/lines/{line_identity}';

	/**
	 * What the rate limit of the cart's writes counts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const WRITE_BUCKET = 'cart.write';

	/**
	 * The most cart writes one client may send in a window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WRITE_LIMIT = 60;

	/**
	 * The window of the cart writes' limit, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const WRITE_WINDOW = 60;

	/**
	 * The name of the cart resource every answer is.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RESOURCE = 'Cart';

	/**
	 * Declares the read.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getCart(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_CART,
			label: static fn(): string => __( 'Get the cart', 'seocart' ),
			summary: 'Returns the cart the request\'s token names, or an empty cart at version 0 when there is none; it never creates a cart and never sets a cookie.',
			input: array(),
			output: self::cart(),
			capability: null,
			resource_field: null,
			errors: self::calculationErrors(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( CartService::class, 'getCart' ),
			rest: new RestBinding( self::CART_ROUTE, store: true )
		);
	}

	/**
	 * Declares the write that adds lines.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function addLines(): OperationDefinition {
		return new OperationDefinition(
			id: self::ADD_LINES,
			label: static fn(): string => __( 'Add lines to the cart', 'seocart' ),
			summary: 'Adds one to fifty lines to the cart, a line the cart holds already gaining the units, and starts a cart, whose token the answer carries, when the request names none.',
			input: array(
				FieldSpec::objectList(
					'lines',
					'The lines to add: a variant and how many of it. Lines of one variant are added up.',
					static fn(): string => __( 'Lines', 'seocart' ),
					array( self::variantId(), self::quantity( 1 ) ),
					required: true,
					min_items: 1,
					max_items: Cart::MAX_LINES
				),
				self::cartVersion( 0 ),
			),
			output: self::cart(),
			capability: null,
			resource_field: null,
			errors: array_merge( array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CartError::TooManyLines, CartError::CreationLimited ), self::calculationErrors() ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( CartService::class, 'addLines' ),
			rest: new RestBinding( self::LINES_ROUTE, WriteMethod::Post, store: true ),
			public_write: self::write( false )
		);
	}

	/**
	 * Declares the write that changes one line.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateLine(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_LINE,
			label: static fn(): string => __( 'Change a line of the cart', 'seocart' ),
			summary: 'Sets the quantity of one line of the cart, named by its identity; a quantity of 0 removes the line.',
			input: array(
				self::lineIdentity(),
				self::quantity( 0 ),
				self::cartVersion( 1 ),
			),
			output: self::cart(),
			capability: null,
			resource_field: null,
			errors: array_merge( array( CartError::NotFound, CartError::VersionStale, CartError::NotOpen, CartError::LineNotFound ), self::calculationErrors() ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( CartService::class, 'updateLine' ),
			rest: new RestBinding( self::LINE_ROUTE, WriteMethod::Patch, store: true ),
			public_write: self::write( true )
		);
	}

	/**
	 * Returns the codes the calculation of an answer's totals can end in.
	 *
	 * @since 0.1.0
	 *
	 * @return list<PricingError> The codes.
	 */
	private static function calculationErrors(): array {
		return array( PricingError::QuoteUnavailable, PricingError::NoShippingRate, PricingError::CurrencyNotEnabled );
	}

	/**
	 * Returns the cart resource every answer is: the version, the lines, the totals and the unpriced lines.
	 *
	 * @since 0.1.0
	 *
	 * @return ResourceSchema The schema.
	 */
	private static function cart(): ResourceSchema {
		return new ResourceSchema(
			self::RESOURCE,
			array(
				new FieldSpec(
					name: 'version',
					type: FieldType::Integer,
					description: 'The cart\'s version: 0 when there is no cart, then one more after every accepted write. Send it with the next write, and discard an answer whose version is lower than one already seen.',
					label: static fn(): string => __( 'Version', 'seocart' ),
					example: 3,
					required: true,
					minimum: 0
				),
				FieldSpec::objectList(
					'lines',
					'The cart\'s lines, in the order they were added.',
					static fn(): string => __( 'Lines', 'seocart' ),
					array( self::lineIdentity(), self::variantId(), self::quantity( 1 ) ),
					required: true,
					max_items: Cart::MAX_LINES
				),
				TotalsFields::totals( 'totals', 'The totals of the lines, worked out when the answer was built; zero for an empty cart.', static fn(): string => __( 'Totals', 'seocart' ) ),
				FieldSpec::objectList(
					'unpriced_lines',
					'The lines the totals leave out, because they have no price in the cart\'s currency or their variant is gone.',
					static fn(): string => __( 'Unpriced lines', 'seocart' ),
					array(
						self::lineIdentity(),
						self::variantId(),
						new FieldSpec(
							name: 'reason',
							type: FieldType::String,
							description: 'Why the line could not be priced.',
							label: static fn(): string => __( 'Reason', 'seocart' ),
							example: UnpricedLine::NO_PRICE_IN_CURRENCY,
							required: true,
							allowed: array( UnpricedLine::NO_PRICE_IN_CURRENCY, UnpricedLine::UNKNOWN_VARIANT )
						),
					),
					required: true,
					max_items: Cart::MAX_LINES
				),
			)
		);
	}

	/**
	 * Returns the identity of a line.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function lineIdentity(): FieldSpec {
		return new FieldSpec(
			name: 'line_identity',
			type: FieldType::String,
			description: 'The line\'s identity: 64 hexadecimal characters that stay the same for as long as the line is in the cart.',
			label: static fn(): string => __( 'Line', 'seocart' ),
			example: '6b86b273ff34fce19d6b804eff5a3f5747ada4eaa22f1d49c01e52ddb7875b4b',
			required: true,
			max_length: 64
		);
	}

	/**
	 * Returns the variant of a line.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function variantId(): FieldSpec {
		return new FieldSpec(
			name: 'variant_id',
			type: FieldType::Integer,
			description: 'The variant the line holds.',
			label: static fn(): string => __( 'Variant', 'seocart' ),
			example: 42,
			required: true,
			minimum: 1
		);
	}

	/**
	 * Returns the units of a line.
	 *
	 * @since 0.1.0
	 *
	 * @param int $minimum The fewest units: 1 for a line, 0 for a change that may remove it.
	 * @return FieldSpec The field.
	 */
	private static function quantity( int $minimum ): FieldSpec {
		return new FieldSpec(
			name: 'quantity',
			type: FieldType::Integer,
			description: 0 === $minimum
				? 'How many units the line holds after the change; 0 removes the line.'
				: 'How many units of the variant: a line holds at most ' . CartLine::MAX_QUANTITY . ', and units added beyond fill it to that.',
			label: static fn(): string => __( 'Quantity', 'seocart' ),
			example: 2,
			required: true,
			minimum: $minimum,
			maximum: CartLine::MAX_QUANTITY
		);
	}

	/**
	 * Returns the version a write was based on.
	 *
	 * @since 0.1.0
	 *
	 * @param int $minimum 0 for a write that may start a cart, which is then optional; 1 for a write to a cart that must exist.
	 * @return FieldSpec The field.
	 */
	private static function cartVersion( int $minimum ): FieldSpec {
		return new FieldSpec(
			name: 'cart_version',
			type: FieldType::Integer,
			description: 0 === $minimum
				? 'The version of the cart the write is based on: the version of the last answer, or 0 when the client has no cart.'
				: 'The version of the cart the write is based on: the version of the last answer.',
			label: static fn(): string => __( 'Cart version', 'seocart' ),
			example: 3,
			required: 0 !== $minimum,
			default_value: 0 === $minimum ? 0 : null,
			minimum: $minimum
		);
	}

	/**
	 * Declares a cart write's request policy: the rate limit and whether the cart must exist.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $requires_cart Whether the write changes a cart that must already exist.
	 * @return PublicWrite The declaration.
	 */
	private static function write( bool $requires_cart ): PublicWrite {
		return StoreRequestPolicy::write( self::WRITE_BUCKET, self::WRITE_LIMIT, self::WRITE_WINDOW, $requires_cart );
	}
}
