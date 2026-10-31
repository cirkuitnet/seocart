<?php
/**
 * LocationOperations: the operations that list and change the store's locations, and list their stock
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Inventory\Domain\BackorderPolicy;
use SEOCart\Inventory\Domain\LocationDetails;
use SEOCart\Inventory\Domain\PickupHours;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the six location operations: the list of locations, their creation, change, deactivation and deletion, and the stock list.
 *
 * Owns one fact: how the store's locations and their stock are offered to clients. Each
 * declaration serves a REST route under `seocart/v1`, an ability and a `wp seocart` command, all
 * compiled from it. A location is named by the uuid in the URL on REST and by the input on the
 * other two surfaces.
 *
 * A location's record is the store's configuration, so its changes require
 * `seocart_manage_settings`; the two reads require `seocart_manage_inventory`, the capability of
 * whoever edits stock, so the product editor's stock grid can list the locations and their units.
 * The phone is personal data: it is shown only to a user who may see personal data, and an
 * operation that carries it, in its input or its output, is never exposed to agents, nor are the
 * destructive two. The address is public, because it appears on documents shoppers receive. An
 * update changes the fields it is given and keeps the others, as PATCH means.
 *
 * Declarations are data: building one reads constants and nothing else.
 *
 * @since 0.2.0
 */
final class LocationOperations {

	/**
	 * The id of the list of locations.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_LOCATIONS = 'inventory.list_locations';

	/**
	 * The id of the creation of a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE_LOCATION = 'inventory.create_location';

	/**
	 * The id of the change of a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_LOCATION = 'inventory.update_location';

	/**
	 * The id of the deactivation of a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEACTIVATE_LOCATION = 'inventory.deactivate_location';

	/**
	 * The id of the deletion of a location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELETE_LOCATION = 'inventory.delete_location';

	/**
	 * The id of the stock list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_STOCK = 'inventory.list_stock';

	/**
	 * The REST route of the locations, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ROUTE = '/locations';

	/**
	 * The REST route of one location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LOCATION_ROUTE = '/locations/{location_uuid}';

	/**
	 * The REST route of a location's deactivation.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DEACTIVATION_ROUTE = '/locations/{location_uuid}/deactivation';

	/**
	 * The REST route of the stock list.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const STOCK_ROUTE = '/stock-items';

	/**
	 * The capability a change of a location's record requires: the store's settings.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CAPABILITY = 'seocart_manage_settings';

	/**
	 * The most variants one request of the stock list may name: a page of the stock grid.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_LISTED_VARIANTS = 200;

	/**
	 * The name of the resource that is one location.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESOURCE = 'Location';

	/**
	 * A uuid the location fields show as their example.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXAMPLE_UUID = '2f6c1a52-8d3b-4a7e-9c41-5b0d7e93a6f8';

	/**
	 * Builds the list of locations.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function listLocations(): OperationDefinition {
		return new OperationDefinition(
			id: self::LIST_LOCATIONS,
			label: static fn(): string => __( 'List locations', 'seocart' ),
			summary: 'Lists every location of the store, active or not, in the order orders are routed to them, each with its record, its units on hand and its open allocations.',
			input: array(),
			output: new ResourceSchema(
				'LocationList',
				array(
					FieldSpec::objectList(
						'locations',
						'The locations, in the order orders are routed to them: ascending priority, ties by the order they were created in.',
						static fn(): string => __( 'Locations', 'seocart' ),
						array_merge(
							self::locationFields(),
							array(
								self::count( 'units_on_hand', 'The units on hand at the location, every variant together.', static fn(): string => __( 'Units on hand', 'seocart' ), 140 ),
								self::count( 'open_allocations', 'How many order lines have units allocated at the location and not yet shipped.', static fn(): string => __( 'Open allocations', 'seocart' ), 3 ),
							)
						),
						required: true
					),
				)
			),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array( AuthorizationError::Denied ),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( Locations::class, 'listLocations' ),
			rest: new RestBinding( self::ROUTE ),
			ability: 'list-locations',
			cli: new CliBinding( array( 'location', 'list' ) )
		);
	}

	/**
	 * Builds the creation of a location.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function createLocation(): OperationDefinition {
		return new OperationDefinition(
			id: self::CREATE_LOCATION,
			label: static fn(): string => __( 'Add location', 'seocart' ),
			summary: 'Creates an active location with its name and, optionally, its address, phone, what it does, its priority and its pickup hours; the store\'s first location becomes its default.',
			input: array_merge( array( self::name( true ) ), self::detailInputs() ),
			output: self::location(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::LocationInvalid,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: false ),
			service: array( Locations::class, 'createLocation' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Post ),
			ability: 'create-location',
			cli: new CliBinding( array( 'location', 'create' ) )
		);
	}

	/**
	 * Builds the change of a location.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateLocation(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_LOCATION,
			label: static fn(): string => __( 'Edit location', 'seocart' ),
			summary: 'Changes the fields of a location the request gives and keeps the others: it can also deactivate or activate the location, make it the store\'s default, or replace its pickup hours.',
			input: array_merge(
				array(
					self::locationUuid( 'The location to change.' ),
					self::name( false ),
					self::flag( 'active', 'Whether orders may be routed to the location. Turning it off deactivates it, as the deactivation does; the default location stays active.', static fn(): string => __( 'Active', 'seocart' ), false ),
					self::flag( 'is_default', 'True makes the location the store\'s default, the one an adjustment or an order that names no location uses, in place of the location that is the default now; only an active location can become it, and the default stops being it only when another location becomes it.', static fn(): string => __( 'Default location', 'seocart' ), false ),
				),
				self::detailInputs()
			),
			output: self::location(),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::LocationMissing,
				InventoryError::LocationInvalid,
				InventoryError::LocationInactive,
				InventoryError::LocationIsDefault,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( Locations::class, 'updateLocation' ),
			rest: new RestBinding( self::LOCATION_ROUTE, WriteMethod::Patch ),
			ability: 'update-location',
			cli: new CliBinding( array( 'location', 'update' ), array( 'location_uuid' ) )
		);
	}

	/**
	 * Builds the deactivation of a location.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function deactivateLocation(): OperationDefinition {
		return new OperationDefinition(
			id: self::DEACTIVATE_LOCATION,
			label: static fn(): string => __( 'Deactivate location', 'seocart' ),
			summary: 'Deactivates a location: from now on no order is routed to it and its units are for sale nowhere, while its stock stays where it is, or its available units move to another location as one transfer; the default location cannot be deactivated.',
			input: array(
				self::locationUuid( 'The location to deactivate.' ),
				new FieldSpec(
					name: 'move_stock_to',
					type: FieldType::Uuid,
					description: 'An active location to move the deactivated location\'s available units to, as one transfer worked in the background; units promised to orders or held by checkouts stay. Moving stock also needs the capability that manages inventory. Repeated with the same location, the deactivation answers the same transfer. Turning the location on again ends the move, the units moved so far staying where they arrived.',
					label: static fn(): string => __( 'Move stock to', 'seocart' ),
					example: self::EXAMPLE_UUID
				),
			),
			output: new ResourceSchema(
				'LocationDeactivation',
				array_merge(
					self::locationFields(),
					array(
						new FieldSpec(
							name: 'move_transfer_uuid',
							type: FieldType::Uuid,
							description: 'The transfer that moves the location\'s available units, or null when no move was asked for.',
							label: static fn(): string => __( 'Stock move', 'seocart' ),
							example: self::EXAMPLE_UUID,
							required: true,
							nullable: true
						),
					)
				)
			),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::LocationMissing,
				InventoryError::LocationIsDefault,
				InventoryError::LocationInactive,
				InventoryError::TransferSameLocation,
				InventoryError::TransferState,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( Locations::class, 'deactivateLocation' ),
			rest: new RestBinding( self::DEACTIVATION_ROUTE, WriteMethod::Post ),
			ability: 'deactivate-location',
			cli: new CliBinding( array( 'location', 'deactivate' ), array( 'location_uuid' ) )
		);
	}

	/**
	 * Builds the deletion of a location.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function deleteLocation(): OperationDefinition {
		return new OperationDefinition(
			id: self::DELETE_LOCATION,
			label: static fn(): string => __( 'Delete location', 'seocart' ),
			summary: 'Deletes a location nothing refers to yet, with its stock items that count nothing; a location with stock history, stock, holds, allocations or transfers is kept, and so is the default location.',
			input: array( self::locationUuid( 'The location to delete.' ) ),
			output: new ResourceSchema(
				'DeletedLocation',
				array(
					self::locationUuid( 'The location deleted.' ),
					self::count( 'stock_items_removed', 'How many of the location\'s stock items, each holding no units, were deleted with it.', static fn(): string => __( 'Stock items removed', 'seocart' ), 4 ),
				)
			),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::LocationMissing,
				InventoryError::LocationInUse,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: true ),
			service: array( Locations::class, 'deleteLocation' ),
			rest: new RestBinding( self::LOCATION_ROUTE, WriteMethod::Delete ),
			ability: 'delete-location',
			cli: new CliBinding( array( 'location', 'delete' ), array( 'location_uuid' ) )
		);
	}

	/**
	 * Builds the stock list.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function listStock(): OperationDefinition {
		return new OperationDefinition(
			id: self::LIST_STOCK,
			label: static fn(): string => __( 'List stock', 'seocart' ),
			summary: 'Lists the stock of up to ' . self::MOST_LISTED_VARIANTS . ' variants: each one\'s stock policy, its units at every active location and at any inactive one that still has a stock item, and its units for sale.',
			input: array(
				new FieldSpec(
					name: 'variant_ids',
					type: FieldType::String,
					description: 'The variants, by id: from 1 to ' . self::MOST_LISTED_VARIANTS . ' whole numbers above 0, separated by commas, such as 12,15,20. A variant with no stock is left out of the answer.',
					label: static fn(): string => __( 'Variants', 'seocart' ),
					example: '12,15,20',
					required: true,
					max_length: self::MOST_LISTED_VARIANTS * 22
				),
			),
			output: new ResourceSchema(
				'StockList',
				array(
					FieldSpec::objectList(
						'variants',
						'The variants that have stock, by ascending id.',
						static fn(): string => __( 'Variants', 'seocart' ),
						array(
							self::count( 'variant_id', 'The variant.', static fn(): string => __( 'Variant', 'seocart' ), 12 ),
							self::flag( 'track', 'Whether the variant\'s stock is counted; a variant whose stock is not counted is always for sale.', static fn(): string => __( 'Track stock', 'seocart' ), true ),
							new FieldSpec(
								name: 'backorder_policy',
								type: FieldType::String,
								description: 'What a sale may do once the variant\'s units run out: no, notify or allow.',
								label: static fn(): string => __( 'When out of stock', 'seocart' ),
								example: BackorderPolicy::No->value,
								required: true,
								allowed: array_map( static fn( BackorderPolicy $policy ): string => $policy->value, BackorderPolicy::cases() )
							),
							new FieldSpec(
								name: 'low_stock_threshold',
								type: FieldType::Integer,
								description: 'The units available at or below which the variant counts as low on stock, or null for none.',
								label: static fn(): string => __( 'Low stock threshold', 'seocart' ),
								example: 5,
								required: true,
								nullable: true
							),
							new FieldSpec(
								name: 'for_sale',
								type: FieldType::Integer,
								description: 'The units shoppers may buy now, as the storefront counts them over the locations that ship online orders; null when nothing limits a sale, because the stock is not counted or backorders are allowed.',
								label: static fn(): string => __( 'For sale', 'seocart' ),
								example: 12,
								required: true,
								nullable: true
							),
							FieldSpec::objectList(
								'locations',
								'The variant\'s units at each active location, zeros where it has no stock item yet, and at each inactive location that still has one, in the order orders are routed to them.',
								static fn(): string => __( 'Locations', 'seocart' ),
								array(
									self::locationUuid( 'The location.' ),
									self::count( 'on_hand', 'The units physically in stock there.', static fn(): string => __( 'On hand', 'seocart' ), 17 ),
									self::count( 'allocated', 'The units there promised to accepted orders.', static fn(): string => __( 'Allocated', 'seocart' ), 2 ),
									self::count( 'held', 'The units there held by checkouts, expired holds included until they are reclaimed.', static fn(): string => __( 'Held', 'seocart' ), 3 ),
									self::count( 'available', 'The units there that may still be promised: on hand, less allocated, less held. Negative when fewer units were counted than are promised or held.', static fn(): string => __( 'Available', 'seocart' ), 12 ),
								),
								required: true
							),
						),
						required: true
					),
				)
			),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::VariantIdsInvalid,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( StockService::class, 'listStock' ),
			rest: new RestBinding( self::STOCK_ROUTE ),
			ability: 'list-stock',
			cli: new CliBinding( array( 'stock', 'list' ) ),
			agent_exposed: true
		);
	}

	/**
	 * Declares one location as an answer: the resource every change of a location returns.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function location(): ResourceSchema {
		return new ResourceSchema( self::RESOURCE, self::locationFields() );
	}

	/**
	 * Declares a location's fields as an answer carries them.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function locationFields(): array {
		return array(
			self::locationUuid( 'The location\'s public identifier.' ),
			self::name( true ),
			self::address( true ),
			self::phone( true ),
			self::flag( 'active', 'Whether orders may be routed to the location; an inactive location keeps its stock, and its units are for sale nowhere.', static fn(): string => __( 'Active', 'seocart' ), true ),
			self::flag( 'is_default', 'Whether the location is the store\'s default: the one an adjustment or an order that names no location uses.', static fn(): string => __( 'Default location', 'seocart' ), true ),
			self::fulfilsOnline( true ),
			self::offersPickup( true ),
			self::pickupReadyIn( true ),
			self::pickupInstructions( true ),
			self::priority( true ),
			self::hours( true ),
		);
	}

	/**
	 * Declares the fields a creation and a change both take, after the name.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields, all optional.
	 */
	private static function detailInputs(): array {
		return array(
			self::address( false ),
			self::phone( false ),
			self::fulfilsOnline( false ),
			self::offersPickup( false ),
			self::pickupReadyIn( false ),
			self::pickupInstructions( false ),
			self::priority( false ),
			self::hours( false ),
		);
	}

	/**
	 * Declares the location's public identifier: the route's parameter, and an answer's field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field, required.
	 */
	private static function locationUuid( string $description ): FieldSpec {
		return new FieldSpec(
			name: 'location_uuid',
			type: FieldType::Uuid,
			description: $description,
			label: static fn(): string => __( 'Location', 'seocart' ),
			example: self::EXAMPLE_UUID,
			required: true
		);
	}

	/**
	 * Declares the location's name.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $required True for a creation's and an answer's, false for a change's.
	 * @return FieldSpec The field.
	 */
	private static function name( bool $required ): FieldSpec {
		return new FieldSpec(
			name: 'name',
			type: FieldType::String,
			description: 'The name the merchant gives the location, such as Main warehouse; not blank.',
			label: static fn(): string => __( 'Name', 'seocart' ),
			example: 'Main warehouse',
			required: $required,
			max_length: 100
		);
	}

	/**
	 * Declares the location's address: the postal part of an address, which appears on documents shoppers receive.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's, which is null until an address is entered.
	 * @return FieldSpec The field.
	 */
	private static function address( bool $output ): FieldSpec {
		$line = static fn( string $name, string $about, \Closure $label, string $example, int $length ): FieldSpec => new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: $about . ' It appears on customer-facing documents.',
			label: $label,
			example: $example,
			required: $output,
			max_length: $length
		);

		return FieldSpec::object(
			'address',
			$output
				? 'Where the location is, or null until an address is entered. It is public: it appears on customer-facing documents, such as pickup confirmations, packing slips and shipping labels.'
				: 'Where the location is; given, it replaces the whole address. It is public: it appears on customer-facing documents, such as pickup confirmations, packing slips and shipping labels.',
			static fn(): string => __( 'Address', 'seocart' ),
			array(
				new FieldSpec(
					name: 'country',
					type: FieldType::String,
					description: 'The ISO 3166-1 alpha-2 country code, two capital letters, such as GB. It appears on customer-facing documents.',
					label: static fn(): string => __( 'Country', 'seocart' ),
					example: 'GB',
					required: true,
					max_length: 2
				),
				$line( 'line1', 'The first address line.', static fn(): string => __( 'Address line 1', 'seocart' ), '12 Market Street', 255 ),
				$line( 'line2', 'The second address line, empty when there is none.', static fn(): string => __( 'Address line 2', 'seocart' ), 'Unit 4', 255 ),
				$line( 'city', 'The city or town.', static fn(): string => __( 'City', 'seocart' ), 'Leeds', 100 ),
				$line( 'region', 'The state, province, county or other subdivision, empty when there is none.', static fn(): string => __( 'Region', 'seocart' ), 'West Yorkshire', 64 ),
				$line( 'postcode', 'The postal code.', static fn(): string => __( 'Postcode', 'seocart' ), 'LS1 6AB', 32 ),
			),
			required: $output,
			nullable: $output
		);
	}

	/**
	 * Declares the location's phone number: personal data.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's, which is null when there is none.
	 * @return FieldSpec The field.
	 */
	private static function phone( bool $output ): FieldSpec {
		return new FieldSpec(
			name: 'phone',
			type: FieldType::String,
			description: $output
				? 'The location\'s phone number for the merchant\'s staff, or null for none. It is personal data, shown only to a user who may see personal data.'
				: 'The location\'s phone number for the merchant\'s staff; on a change, an empty text removes it. It is personal data, shown only to a user who may see personal data.',
			label: static fn(): string => __( 'Phone', 'seocart' ),
			example: '+44 113 496 0000',
			required: $output,
			nullable: $output,
			max_length: 32,
			privacy: Privacy::Pii
		);
	}

	/**
	 * Declares whether the location ships online orders.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's.
	 * @return FieldSpec The field.
	 */
	private static function fulfilsOnline( bool $output ): FieldSpec {
		return self::flag( 'fulfils_online', 'Whether the location ships online orders; a new location does unless told otherwise.', static fn(): string => __( 'Fulfils online orders', 'seocart' ), $output );
	}

	/**
	 * Declares whether shoppers may collect orders at the location.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's.
	 * @return FieldSpec The field.
	 */
	private static function offersPickup( bool $output ): FieldSpec {
		return self::flag( 'offers_pickup', 'Whether shoppers may collect orders at the location; a new location does not unless told otherwise.', static fn(): string => __( 'Offers pickup', 'seocart' ), $output );
	}

	/**
	 * Declares how soon a pickup order is usually ready.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's, which is null when none was given.
	 * @return FieldSpec The field.
	 */
	private static function pickupReadyIn( bool $output ): FieldSpec {
		$keys = implode( ', ', LocationDetails::PICKUP_READY_IN );

		if ( $output ) {
			return new FieldSpec(
				name: 'pickup_ready_in',
				type: FieldType::String,
				description: 'How soon a pickup order is usually ready, one of ' . $keys . ', or null when none was given.',
				label: static fn(): string => __( 'Ready for pickup in', 'seocart' ),
				example: LocationDetails::PICKUP_READY_IN[0],
				required: true,
				nullable: true,
				max_length: 32
			);
		}

		return new FieldSpec(
			name: 'pickup_ready_in',
			type: FieldType::String,
			description: 'How soon a pickup order is usually ready: one of ' . $keys . '.',
			label: static fn(): string => __( 'Ready for pickup in', 'seocart' ),
			example: LocationDetails::PICKUP_READY_IN[0],
			allowed: LocationDetails::PICKUP_READY_IN
		);
	}

	/**
	 * Declares what a shopper collecting an order is told.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's, which is null when there is nothing to tell.
	 * @return FieldSpec The field.
	 */
	private static function pickupInstructions( bool $output ): FieldSpec {
		return new FieldSpec(
			name: 'pickup_instructions',
			type: FieldType::String,
			description: $output
				? 'What a shopper collecting an order is told, such as where to go, or null for nothing.'
				: 'What a shopper collecting an order is told, such as where to go; it may run over several lines, and on a change an empty text removes it.',
			label: static fn(): string => __( 'Pickup instructions', 'seocart' ),
			example: 'Ring the bell at the side door.',
			required: $output,
			nullable: $output,
			max_length: 1000
		);
	}

	/**
	 * Declares where the location stands in the order locations are tried.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's.
	 * @return FieldSpec The field.
	 */
	private static function priority( bool $output ): FieldSpec {
		return new FieldSpec(
			name: 'priority',
			type: FieldType::Integer,
			description: 'Where the location stands when an order is routed: the lowest number is tried first, and locations of the same number in the order they were created; a new location has 0 unless told otherwise.',
			label: static fn(): string => __( 'Priority', 'seocart' ),
			example: 1,
			required: $output,
			minimum: 0,
			maximum: 1000000
		);
	}

	/**
	 * Declares the days the location is open for pickup.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $output True for an answer's.
	 * @return FieldSpec The field.
	 */
	private static function hours( bool $output ): FieldSpec {
		$time = static fn( string $name, string $about, \Closure $label, string $example ): FieldSpec => new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: $about,
			label: $label,
			example: $example,
			required: true,
			max_length: 5
		);

		return FieldSpec::objectList(
			'hours',
			$output
				? 'The days the location is open for pickup, by day; a day not listed is closed.'
				: 'The days the location is open for pickup, each day once; given, they replace the location\'s hours, and a day not listed is closed.',
			static fn(): string => __( 'Pickup hours', 'seocart' ),
			array(
				new FieldSpec(
					name: 'weekday',
					type: FieldType::Integer,
					description: 'The day of the week, 0 for Sunday to 6 for Saturday.',
					label: static fn(): string => __( 'Day', 'seocart' ),
					example: 1,
					required: true,
					minimum: 0,
					maximum: PickupHours::DAYS - 1
				),
				$time( 'opens_at', 'When the location opens that day, local time, written HH:MM on a 24-hour clock.', static fn(): string => __( 'Opens at', 'seocart' ), '09:00' ),
				$time( 'closes_at', 'When it closes that day, local time, written HH:MM, later than it opens.', static fn(): string => __( 'Closes at', 'seocart' ), '17:30' ),
			),
			required: $output,
			max_items: PickupHours::DAYS
		);
	}

	/**
	 * Declares one Boolean of a location or a variant.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param bool     $output      True for an answer's, which is always there.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function flag( string $name, string $description, \Closure $label, bool $output ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Boolean,
			description: $description,
			label: $label,
			example: true,
			required: $output
		);
	}

	/**
	 * Declares one required integer of an answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param int      $example     A value the field accepts.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function count( string $name, string $description, \Closure $label, int $example ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: $example,
			required: true
		);
	}
}
