<?php
/**
 * CheckoutSettingsOperations: the declarations of the staff operations over the checkout's fields and order limits
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Interfaces\Rest;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Checkout\Application\CheckoutSettingsService;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\Fields\BuiltInFields;
use SEOCart\Checkout\Interfaces\StoreApi\CheckoutOperations;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Settings\SettingsError;
use SEOCart\Platform\Settings\SettingsOperations;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the four operations staff use to choose the checkout's fields and its order limits.
 *
 * Owns one fact: how the store's checkout choices are read and changed. Each requires
 * `seocart_manage_settings`, as every store setting does, and is served by a REST route of
 * `seocart/v1`, an ability and a WP-CLI command:
 *
 * - `checkout.get_fields`, `GET /checkout/fields`: every declared field, hidden ones too, with its
 *   presence in this store, the presences it allows, who declared it and whether it is locked.
 * - `checkout.update_fields`, `PATCH /checkout/fields`: sets the presence of the fields named,
 *   keeping every other, and answers every field.
 * - `checkout.get_order_limits`, `GET /checkout/order-limits`: the smallest and largest order the
 *   store takes, per currency.
 * - `checkout.update_order_limits`, `PUT /checkout/order-limits`: replaces them.
 *
 * They are not the generic settings operations: an extension's field cannot be an exposed setting,
 * and one choice changed by two mechanisms would be the same knowledge written twice.
 *
 * Declarations are data: building a definition calls no WordPress function.
 *
 * @since 0.2.0
 */
final class CheckoutSettingsOperations {

	/**
	 * The id of the fields' read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GET_FIELDS = 'checkout.get_fields';

	/**
	 * The id of the fields' change.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_FIELDS = 'checkout.update_fields';

	/**
	 * The id of the order limits' read.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GET_ORDER_LIMITS = 'checkout.get_order_limits';

	/**
	 * The id of the order limits' change.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_ORDER_LIMITS = 'checkout.update_order_limits';

	/**
	 * The route of the fields, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const FIELDS_ROUTE = '/checkout/fields';

	/**
	 * The route of the order limits, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIMITS_ROUTE = '/checkout/order-limits';

	/**
	 * The capability all four require: the store settings'.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPABILITY = SettingsOperations::CAPABILITY;

	/**
	 * Builds the fields' read.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getFields(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_FIELDS,
			label: static fn(): string => __( 'Read the checkout fields', 'seocart' ),
			summary: 'Returns every checkout field the store has, hidden ones included, in the order the form shows them, each with its presence in this store, the presences it allows, who declared it and whether it is locked.',
			input: array(),
			output: self::fieldsResource(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array( SettingsError::StoredValueInvalid, AuthorizationError::Denied ),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( CheckoutSettingsService::class, 'getFields' ),
			rest: new RestBinding( self::FIELDS_ROUTE ),
			ability: 'get-checkout-fields',
			cli: new CliBinding( array( 'checkout', 'fields' ) )
		);
	}

	/**
	 * Builds the fields' change.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateFields(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_FIELDS,
			label: static fn(): string => __( 'Change the checkout fields', 'seocart' ),
			summary: 'Sets whether the checkout hides, offers or requires each field named, among the presences the field allows, keeps every other field as it is, and returns every checkout field; a change made at the same moment by someone else is kept.',
			input: array(
				FieldSpec::objectList(
					'fields',
					'The fields to change, each by its key with its new presence.',
					static fn(): string => __( 'Fields', 'seocart' ),
					array(
						self::key( 'The field\'s key, as the field list names it.' ),
						self::presence( 'The field\'s new presence: one of the presences the field allows.' ),
					),
					required: true,
					min_items: 1,
					max_items: 100
				),
			),
			output: self::fieldsResource(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array( CheckoutError::FieldUnknown, CheckoutError::PresenceNotAllowed, SettingsError::VersionConflict, SettingsError::StoredValueInvalid, AuthorizationError::Denied ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( CheckoutSettingsService::class, 'updateFields' ),
			rest: new RestBinding( self::FIELDS_ROUTE, WriteMethod::Patch ),
			ability: 'update-checkout-fields',
			cli: new CliBinding( array( 'checkout', 'update-fields' ) )
		);
	}

	/**
	 * Builds the order limits' read.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getOrderLimits(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_ORDER_LIMITS,
			label: static fn(): string => __( 'Read the order limits', 'seocart' ),
			summary: 'Returns the smallest and the largest order the store takes in each currency that has a limit, in that currency\'s minor units; a currency left out has none.',
			input: array(),
			output: self::limitsResource(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array( SettingsError::StoredValueInvalid, AuthorizationError::Denied ),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( CheckoutSettingsService::class, 'getOrderLimits' ),
			rest: new RestBinding( self::LIMITS_ROUTE ),
			ability: 'get-order-limits',
			cli: new CliBinding( array( 'checkout', 'order-limits' ) )
		);
	}

	/**
	 * Builds the order limits' change.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateOrderLimits(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_ORDER_LIMITS,
			label: static fn(): string => __( 'Change the order limits', 'seocart' ),
			summary: 'Replaces the store\'s order limits with the ones given, at most one per currency, and returns them; a currency left out, or given without either bound, has no limit. Each bound is compared with the goods after their discounts, without shipping or fees, with tax when every price was entered with tax.',
			input: array( self::limits( true ) ),
			output: self::limitsResource(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array( CheckoutError::InvalidOrderLimits, SettingsError::VersionConflict, SettingsError::StoredValueInvalid, AuthorizationError::Denied ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( CheckoutSettingsService::class, 'updateOrderLimits' ),
			rest: new RestBinding( self::LIMITS_ROUTE, WriteMethod::Put ),
			ability: 'update-order-limits',
			cli: new CliBinding( array( 'checkout', 'update-order-limits' ) )
		);
	}

	/**
	 * Declares the answer of the fields' read and change: every field.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function fieldsResource(): ResourceSchema {
		return new ResourceSchema(
			'CheckoutFields',
			array(
				FieldSpec::objectList(
					'fields',
					'Every checkout field the store has, hidden ones included, in the order the form shows them.',
					static fn(): string => __( 'Fields', 'seocart' ),
					array_merge(
						CheckoutOperations::fieldIdentity(),
						array(
							self::presence( 'The field\'s presence in this store: hidden, optional or required.' ),
							FieldSpec::objectList(
								'presences',
								'The presences the store may choose for the field, the default first; a locked field allows one.',
								static fn(): string => __( 'Presences allowed', 'seocart' ),
								array( self::presence( 'A presence the field allows.' ) ),
								required: true,
								min_items: 1,
								max_items: count( Presence::cases() )
							),
							new FieldSpec(
								name: 'source',
								type: FieldType::String,
								description: 'Who declared the field: core for SEOCart\'s own fields, or the id of the extension that registered it.',
								label: static fn(): string => __( 'Source', 'seocart' ),
								example: BuiltInFields::SOURCE,
								required: true
							),
							new FieldSpec(
								name: 'locked',
								type: FieldType::Boolean,
								description: 'Whether the field allows one presence alone, so the store cannot change it.',
								label: static fn(): string => __( 'Locked', 'seocart' ),
								example: false,
								required: true
							),
						)
					),
					required: true
				),
			)
		);
	}

	/**
	 * Declares the answer of the order limits' read and change: every limit.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function limitsResource(): ResourceSchema {
		return new ResourceSchema( 'OrderLimits', array( self::limits( false ) ) );
	}

	/**
	 * Declares the list of order limits, as the change takes it and both answer it.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $input True for the change's input; false for an answer.
	 * @return FieldSpec The field.
	 */
	private static function limits( bool $input ): FieldSpec {
		return FieldSpec::objectList(
			'limits',
			$input
				? 'The order limits, at most one per currency; every currency left out has none.'
				: 'The order limits, one per currency that has one, by currency code.',
			static fn(): string => __( 'Order limits', 'seocart' ),
			array(
				CheckoutOperations::limitCurrency(),
				self::bound( 'min_subtotal_minor', 'The smallest order the store takes in the currency, in its minor units, compared with the goods after their discounts; null for none.', static fn(): string => __( 'Minimum order', 'seocart' ), 2500, $input ),
				self::bound( 'max_subtotal_minor', 'The largest order the store takes in the currency, in its minor units, compared with the goods after their discounts; null for none.', static fn(): string => __( 'Maximum order', 'seocart' ), 500000, $input ),
			),
			required: true,
			max_items: 200
		);
	}

	/**
	 * Declares one bound of an order limit: a whole number of minor units from 1, or null.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label.
	 * @param int      $example     An example.
	 * @param bool     $input       True for the change's input, where it may be left out.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function bound( string $name, string $description, \Closure $label, int $example, bool $input ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: $example,
			required: ! $input,
			nullable: true,
			minimum: 1
		);
	}

	/**
	 * Declares a field's key, as the change names a field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field.
	 */
	private static function key( string $description ): FieldSpec {
		return new FieldSpec( name: 'key', type: FieldType::String, description: $description, label: static fn(): string => __( 'Key', 'seocart' ), example: 'company', required: true, max_length: CheckoutField::KEY_MAX_LENGTH );
	}

	/**
	 * Declares a presence.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field.
	 */
	private static function presence( string $description ): FieldSpec {
		$presences = array_column( Presence::cases(), 'value' );

		return new FieldSpec( name: 'presence', type: FieldType::String, description: $description, label: static fn(): string => __( 'Presence', 'seocart' ), example: $presences[0], required: true, allowed: $presences );
	}
}
