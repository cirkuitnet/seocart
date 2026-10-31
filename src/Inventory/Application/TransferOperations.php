<?php
/**
 * TransferOperations: the operations that list, create, change, send, receive and cancel stock transfers
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
use SEOCart\Inventory\Domain\TransferKind;
use SEOCart\Inventory\Domain\TransferState;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the seven transfer operations: the list of transfers, one transfer, and its creation, change, send, receipt and cancel.
 *
 * Owns one fact: how stock transfers are offered to clients. Each declaration serves a REST route
 * under `seocart/v1/stock-transfers`, an ability and a `wp seocart transfer` command, all compiled
 * from it; a transfer is named by the uuid in the URL on REST and by the input on the other two
 * surfaces, and lines are a JSON list on the command line. A transfer moves stock, so all seven
 * require `seocart_manage_inventory`. The two reads carry no personal data, and are exposed to
 * agents; the five writes are not. Who created a transfer is part of its record and is never
 * answered. An order a transfer serves is named by its id; its number and its customer are the
 * order's, read where they are shown. Every write answers the transfer as it is now, the same
 * resource the read of one transfer answers. An update changes the fields it is given and keeps
 * the others, as PATCH means. A receipt line carries the units it expects received so far, so a
 * receipt repeated after a lost answer is refused rather than recorded twice.
 *
 * Declarations are data: building one reads constants and nothing else.
 *
 * @since 0.2.0
 */
final class TransferOperations {

	/**
	 * The id of the list of transfers.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIST_TRANSFERS = 'inventory.list_transfers';

	/**
	 * The id of the read of one transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GET_TRANSFER = 'inventory.get_transfer';

	/**
	 * The id of the creation of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CREATE_TRANSFER = 'inventory.create_transfer';

	/**
	 * The id of the change of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UPDATE_TRANSFER = 'inventory.update_transfer';

	/**
	 * The id of the send of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SEND_TRANSFER = 'inventory.send_transfer';

	/**
	 * The id of a receipt of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIVE_TRANSFER = 'inventory.receive_transfer';

	/**
	 * The id of the cancel of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCEL_TRANSFER = 'inventory.cancel_transfer';

	/**
	 * The REST route of the transfers, relative to the namespace.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ROUTE = '/stock-transfers';

	/**
	 * The REST route of one transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TRANSFER_ROUTE = '/stock-transfers/{transfer_uuid}';

	/**
	 * The REST route of a transfer's send.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DISPATCH_ROUTE = '/stock-transfers/{transfer_uuid}/dispatch';

	/**
	 * The REST route of a transfer's receipts.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RECEIPTS_ROUTE = '/stock-transfers/{transfer_uuid}/receipts';

	/**
	 * The REST route of a transfer's cancel.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CANCELLATION_ROUTE = '/stock-transfers/{transfer_uuid}/cancellation';

	/**
	 * The most lines a transfer, or a receipt, may have.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_LINES = 500;

	/**
	 * The most transfers one page of the list holds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PER_PAGE = 100;

	/**
	 * The transfers a page of the list holds when the request does not say.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PER_PAGE = 20;

	/**
	 * The last page of the list a request may ask for: far past any store's transfers, and small enough that the page's offset stays an integer.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MOST_PAGE = 1000000;

	/**
	 * The name of the resource that is one transfer.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const RESOURCE = 'StockTransfer';

	/**
	 * The most units one line may send: the bound of an adjustment.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MOST_UNITS = 1000000;

	/**
	 * The longest note.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const NOTE_LENGTH = 1000;

	/**
	 * A transfer's uuid the fields show as their example.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXAMPLE_UUID = '0f7e7c3a-5b1d-4c2e-9a8f-3d6b2e1c4a90';

	/**
	 * A location's uuid the fields show as their example.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EXAMPLE_LOCATION = '2f6c1a52-8d3b-4a7e-9c41-5b0d7e93a6f8';

	/**
	 * Builds the list of transfers.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function listTransfers(): OperationDefinition {
		return new OperationDefinition(
			id: self::LIST_TRANSFERS,
			label: static fn(): string => __( 'List transfers', 'seocart' ),
			summary: 'Lists a page of stock transfers, newest first, each with its lines summed up, filtered by state, by a location they leave or arrive at, or by the order they serve.',
			input: array(
				new FieldSpec(
					name: 'state',
					type: FieldType::String,
					description: 'Only transfers in this state.',
					label: static fn(): string => __( 'State', 'seocart' ),
					example: TransferState::InTransit->value,
					allowed: self::states()
				),
				self::locationUuid( 'location_uuid', 'Only transfers that leave this location or arrive at it.', static fn(): string => __( 'Location', 'seocart' ), false ),
				new FieldSpec(
					name: 'order_id',
					type: FieldType::Integer,
					description: 'Only transfers this order waits for.',
					label: static fn(): string => __( 'Order', 'seocart' ),
					example: 1042,
					minimum: 1
				),
				new FieldSpec(
					name: 'page',
					type: FieldType::Integer,
					description: 'Which page of the list, from 1 to ' . self::MOST_PAGE . '.',
					label: static fn(): string => __( 'Page', 'seocart' ),
					example: 1,
					default_value: 1,
					minimum: 1,
					maximum: self::MOST_PAGE
				),
				new FieldSpec(
					name: 'per_page',
					type: FieldType::Integer,
					description: 'How many transfers a page holds, from 1 to ' . self::MOST_PER_PAGE . '.',
					label: static fn(): string => __( 'Per page', 'seocart' ),
					example: self::PER_PAGE,
					default_value: self::PER_PAGE,
					minimum: 1,
					maximum: self::MOST_PER_PAGE
				),
			),
			output: new ResourceSchema(
				'StockTransferList',
				array(
					FieldSpec::objectList(
						'transfers',
						'The page of transfers, newest first.',
						static fn(): string => __( 'Transfers', 'seocart' ),
						array_merge(
							self::documentFields(),
							array(
								self::count( 'lines', 'How many lines the transfer has.', static fn(): string => __( 'Lines', 'seocart' ), 3 ),
								self::count( 'sent', 'The units of every line together: what the transfer sends, or sent once it is in transit.', static fn(): string => __( 'Units', 'seocart' ), 12 ),
								self::count( 'received', 'The units received so far, every line together.', static fn(): string => __( 'Received', 'seocart' ), 10 ),
								self::count( 'lost', 'The units written off as lost, every line together.', static fn(): string => __( 'Lost', 'seocart' ), 1 ),
							)
						),
						required: true
					),
					self::count( 'page', 'The page answered.', static fn(): string => __( 'Page', 'seocart' ), 1 ),
					self::count( 'per_page', 'How many transfers a page holds.', static fn(): string => __( 'Per page', 'seocart' ), self::PER_PAGE ),
					new FieldSpec(
						name: 'has_more',
						type: FieldType::Boolean,
						description: 'Whether a next page has transfers; no total is counted.',
						label: static fn(): string => __( 'More', 'seocart' ),
						example: false,
						required: true
					),
				)
			),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::LocationMissing,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( Transfers::class, 'listTransfers' ),
			rest: new RestBinding( self::ROUTE ),
			ability: 'list-transfers',
			cli: new CliBinding( array( 'transfer', 'list' ) ),
			agent_exposed: true
		);
	}

	/**
	 * Builds the read of one transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function getTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::GET_TRANSFER,
			label: static fn(): string => __( 'View transfer', 'seocart' ),
			summary: 'Returns one stock transfer with its lines: what each sends, what was received and lost, what is on the way, and whether it carries an order\'s units.',
			input: array( self::transferUuid( 'The transfer.' ) ),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferNotFound,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: array( Transfers::class, 'getTransfer' ),
			rest: new RestBinding( self::TRANSFER_ROUTE ),
			ability: 'get-transfer',
			cli: new CliBinding( array( 'transfer', 'get' ), array( 'transfer_uuid' ) ),
			agent_exposed: true
		);
	}

	/**
	 * Builds the creation of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function createTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::CREATE_TRANSFER,
			label: static fn(): string => __( 'Add transfer', 'seocart' ),
			summary: 'Creates a draft transfer of units from one location to another: it promises nothing, may name more units than the source has, and claims them only when it is sent.',
			input: array(
				self::locationUuid( 'source_location_uuid', 'The location the units leave; it may be deactivated, so its stock can be moved away.', static fn(): string => __( 'From', 'seocart' ), true ),
				self::locationUuid( 'destination_location_uuid', 'The active location the units arrive at, not the source.', static fn(): string => __( 'To', 'seocart' ), true ),
				self::newLines( true ),
				self::note( 'A note on the transfer, such as why it is made; it may run over several lines.' ),
			),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferSameLocation,
				InventoryError::LocationMissing,
				InventoryError::LocationInactive,
				InventoryError::ItemMissing,
				InventoryError::TransferInvalid,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: false ),
			service: array( Transfers::class, 'createTransfer' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Post ),
			ability: 'create-transfer',
			cli: new CliBinding( array( 'transfer', 'create' ) )
		);
	}

	/**
	 * Builds the change of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function updateTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::UPDATE_TRANSFER,
			label: static fn(): string => __( 'Edit transfer', 'seocart' ),
			summary: 'Changes the note or the lines of a draft or requested transfer, the fields the request gives only: lines given replace the lines that carry no order\'s units, which are kept as they are.',
			input: array(
				self::transferUuid( 'The transfer to change.' ),
				self::newLines( false ),
				self::note( 'The note on the transfer; an empty text removes it.' ),
			),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferNotFound,
				InventoryError::TransferState,
				InventoryError::ItemMissing,
				InventoryError::TransferInvalid,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: true ),
			service: array( Transfers::class, 'updateTransfer' ),
			rest: new RestBinding( self::TRANSFER_ROUTE, WriteMethod::Patch ),
			ability: 'update-transfer',
			cli: new CliBinding( array( 'transfer', 'update' ), array( 'transfer_uuid' ) )
		);
	}

	/**
	 * Builds the send of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function sendTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::SEND_TRANSFER,
			label: static fn(): string => __( 'Send transfer', 'seocart' ),
			summary: 'Sends a draft or requested transfer: every line\'s units leave the source at once, or, when one line\'s are not there, none do; until they are received they are on the way, in no location\'s stock.',
			input: array(
				self::transferUuid( 'The transfer to send.' ),
				self::note( 'A note to keep with the parcel, such as its tracking number; given, it replaces the transfer\'s note.' ),
			),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferNotFound,
				InventoryError::TransferState,
				InventoryError::TransferRefused,
				InventoryError::TransferInvalid,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: false ),
			service: array( Transfers::class, 'sendTransfer' ),
			rest: new RestBinding( self::DISPATCH_ROUTE, WriteMethod::Post ),
			ability: 'send-transfer',
			cli: new CliBinding( array( 'transfer', 'send' ), array( 'transfer_uuid' ) )
		);
	}

	/**
	 * Builds a receipt of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function receiveTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::RECEIVE_TRANSFER,
			label: static fn(): string => __( 'Receive transfer', 'seocart' ),
			summary: 'Receives units of a transfer in transit at its destination, line by line, and writes off units lost on the way; the transfer is received once every line\'s units are received or lost.',
			input: array(
				self::transferUuid( 'The transfer received.' ),
				FieldSpec::objectList(
					'lines',
					'What arrived of each line, each line once: the units received and lost now, at least one of them, and the units the client last read as received and as lost, so a receipt repeated after a lost answer is refused rather than recorded twice.',
					static fn(): string => __( 'Lines', 'seocart' ),
					array(
						self::id( 'line_id', 'The line.', static fn(): string => __( 'Line', 'seocart' ), 501 ),
						self::units( 'received', 'The units of the line that arrived now.', static fn(): string => __( 'Received', 'seocart' ) ),
						self::units( 'lost', 'The units of the line written off as lost now.', static fn(): string => __( 'Lost', 'seocart' ) ),
						self::soFar( 'expected_received_quantity', 'The units of the line the client last read as received so far; the receipt applies only while the line still has that many.', static fn(): string => __( 'Received so far', 'seocart' ) ),
						self::soFar( 'expected_lost_quantity', 'The units of the line the client last read as lost so far; the receipt applies only while the line still has that many.', static fn(): string => __( 'Lost so far', 'seocart' ) ),
					),
					required: true,
					min_items: 1,
					max_items: self::MOST_LINES
				),
			),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferNotFound,
				InventoryError::TransferState,
				InventoryError::TransferReceiptConflict,
				InventoryError::TransferInvalid,
				InventoryError::ItemMissing,
				InventoryError::LocationMissing,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: false ),
			service: array( Transfers::class, 'receiveTransfer' ),
			rest: new RestBinding( self::RECEIPTS_ROUTE, WriteMethod::Post ),
			ability: 'receive-transfer',
			cli: new CliBinding( array( 'transfer', 'receive' ), array( 'transfer_uuid' ) )
		);
	}

	/**
	 * Builds the cancel of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function cancelTransfer(): OperationDefinition {
		return new OperationDefinition(
			id: self::CANCEL_TRANSFER,
			label: static fn(): string => __( 'Cancel transfer', 'seocart' ),
			summary: 'Cancels a draft, or a requested transfer that carries no order\'s units; a transfer in transit is received or written off instead, never cancelled.',
			input: array( self::transferUuid( 'The transfer to cancel.' ) ),
			output: self::document(),
			capability: InventoryOperations::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::TransferNotFound,
				InventoryError::TransferState,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: false ),
			service: array( Transfers::class, 'cancelTransfer' ),
			rest: new RestBinding( self::CANCELLATION_ROUTE, WriteMethod::Post ),
			ability: 'cancel-transfer',
			cli: new CliBinding( array( 'transfer', 'cancel' ), array( 'transfer_uuid' ) )
		);
	}

	/**
	 * Declares one transfer as an answer: the resource every operation on one transfer returns.
	 *
	 * @since 0.2.0
	 *
	 * @return ResourceSchema The resource.
	 */
	private static function document(): ResourceSchema {
		return new ResourceSchema(
			self::RESOURCE,
			array_merge(
				self::documentFields(),
				array(
					FieldSpec::objectList(
						'lines',
						'The transfer\'s lines, by ascending variant.',
						static fn(): string => __( 'Lines', 'seocart' ),
						array(
							self::count( 'line_id', 'The line, as a receipt names it.', static fn(): string => __( 'Line', 'seocart' ), 501 ),
							self::count( 'variant_id', 'The variant whose units move.', static fn(): string => __( 'Variant', 'seocart' ), 42 ),
							self::count( 'quantity', 'The units the line sends, or sent.', static fn(): string => __( 'Quantity', 'seocart' ), 4 ),
							self::count( 'received_quantity', 'The units received at the destination so far.', static fn(): string => __( 'Received', 'seocart' ), 3 ),
							self::count( 'lost_quantity', 'The units written off as lost so far.', static fn(): string => __( 'Lost', 'seocart' ), 0 ),
							self::count( 'in_transit', 'The units on the way: sent, and neither received nor lost yet; 0 before the transfer is sent.', static fn(): string => __( 'In transit', 'seocart' ), 1 ),
							new FieldSpec(
								name: 'allocated',
								type: FieldType::Boolean,
								description: 'Whether the line carries, or carried until it was received, an order\'s units; such a line cannot be edited or removed.',
								label: static fn(): string => __( 'For an order', 'seocart' ),
								example: false,
								required: true
							),
						),
						required: true
					),
				)
			)
		);
	}

	/**
	 * Declares what a list and a whole transfer both answer of a transfer.
	 *
	 * @since 0.2.0
	 *
	 * @return list<FieldSpec> The fields.
	 */
	private static function documentFields(): array {
		return array(
			self::transferUuid( 'The transfer\'s public identifier.' ),
			new FieldSpec(
				name: 'kind',
				type: FieldType::String,
				description: 'Who started the transfer: manual for a merchant\'s, order for one an order waits for, deactivation for the move of a deactivated location\'s stock.',
				label: static fn(): string => __( 'Kind', 'seocart' ),
				example: TransferKind::Manual->value,
				required: true,
				allowed: array_map( static fn( TransferKind $kind ): string => $kind->value, TransferKind::cases() )
			),
			new FieldSpec(
				name: 'state',
				type: FieldType::String,
				description: 'Where the transfer stands: draft, requested, in_transit, received or cancelled.',
				label: static fn(): string => __( 'State', 'seocart' ),
				example: TransferState::Draft->value,
				required: true,
				allowed: self::states()
			),
			self::answeredLocation( 'source_location_uuid', 'The location the units leave, or null when it no longer exists.', static fn(): string => __( 'From', 'seocart' ) ),
			self::answeredLocation( 'destination_location_uuid', 'The location the units arrive at, or null when it no longer exists.', static fn(): string => __( 'To', 'seocart' ) ),
			new FieldSpec(
				name: 'order_id',
				type: FieldType::Integer,
				description: 'The order the transfer brings units to, for one of kind order; null otherwise.',
				label: static fn(): string => __( 'Order', 'seocart' ),
				example: 1042,
				required: true,
				nullable: true
			),
			new FieldSpec(
				name: 'note',
				type: FieldType::String,
				description: 'The merchant\'s note on the transfer, such as a tracking number, or null for none.',
				label: static fn(): string => __( 'Note', 'seocart' ),
				example: 'Courier 1Z999AA10123456784',
				required: true,
				nullable: true,
				max_length: self::NOTE_LENGTH
			),
			self::time( 'created_at', 'When the transfer was created, UTC, in ISO 8601 to the microsecond, by the database clock.', static fn(): string => __( 'Created', 'seocart' ), false ),
			self::time( 'sent_at', 'When its units left the source, UTC, in ISO 8601 to the microsecond; null until it is sent.', static fn(): string => __( 'Sent', 'seocart' ), true ),
			self::time( 'received_at', 'When its last units were received or written off, UTC, in ISO 8601 to the microsecond; null until then.', static fn(): string => __( 'Received', 'seocart' ), true ),
		);
	}

	/**
	 * Declares the lines a creation or a change gives: each a variant and its units.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $required True for a creation's, which needs one at least; false for a change's, which may give none.
	 * @return FieldSpec The field.
	 */
	private static function newLines( bool $required ): FieldSpec {
		return FieldSpec::objectList(
			'lines',
			$required
				? 'The units to send of each variant, from 1 to ' . self::MOST_LINES . ' lines; lines of one variant are added together.'
				: 'The units to send of each variant; given, they replace the lines that carry no order\'s units, and the transfer keeps from 1 to ' . self::MOST_LINES . ' lines.',
			static fn(): string => __( 'Lines', 'seocart' ),
			array(
				self::id( 'variant_id', 'The variant whose units move.', static fn(): string => __( 'Variant', 'seocart' ), 42 ),
				new FieldSpec(
					name: 'quantity',
					type: FieldType::Integer,
					description: 'The units to send.',
					label: static fn(): string => __( 'Quantity', 'seocart' ),
					example: 4,
					required: true,
					minimum: 1,
					maximum: self::MOST_UNITS
				),
			),
			required: $required,
			min_items: $required ? 1 : 0,
			max_items: self::MOST_LINES
		);
	}

	/**
	 * Declares a note a request may give.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field, optional.
	 */
	private static function note( string $description ): FieldSpec {
		return new FieldSpec(
			name: 'note',
			type: FieldType::String,
			description: $description,
			label: static fn(): string => __( 'Note', 'seocart' ),
			example: 'Courier 1Z999AA10123456784',
			max_length: self::NOTE_LENGTH
		);
	}

	/**
	 * Declares the transfer's public identifier: the route's parameter, and an answer's field.
	 *
	 * @since 0.2.0
	 *
	 * @param string $description The machine description.
	 * @return FieldSpec The field, required.
	 */
	private static function transferUuid( string $description ): FieldSpec {
		return new FieldSpec(
			name: 'transfer_uuid',
			type: FieldType::Uuid,
			description: $description,
			label: static fn(): string => __( 'Transfer', 'seocart' ),
			example: self::EXAMPLE_UUID,
			required: true
		);
	}

	/**
	 * Declares a location a request names.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param bool     $required    Whether the request must give it.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function locationUuid( string $name, string $description, \Closure $label, bool $required ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Uuid,
			description: $description,
			label: $label,
			example: self::EXAMPLE_LOCATION,
			required: $required
		);
	}

	/**
	 * Declares a location an answer names, null when it no longer exists.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function answeredLocation( string $name, string $description, \Closure $label ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Uuid,
			description: $description,
			label: $label,
			example: self::EXAMPLE_LOCATION,
			required: true,
			nullable: true
		);
	}

	/**
	 * Declares a time an answer gives.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param bool     $nullable    Whether it is null until something happens.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function time( string $name, string $description, \Closure $label, bool $nullable ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::String,
			description: $description,
			label: $label,
			example: '2026-10-25T14:03:27.512345Z',
			required: true,
			nullable: $nullable
		);
	}

	/**
	 * Declares an id a request gives inside a line.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @param int      $example     A value the field accepts.
	 * @return FieldSpec The field, required, 1 or more.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function id( string $name, string $description, \Closure $label, int $example ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: $example,
			required: true,
			minimum: 1
		);
	}

	/**
	 * Declares units a receipt line gives: 0 or more, 0 when left out.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function units( string $name, string $description, \Closure $label ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: 1,
			default_value: 0,
			minimum: 0,
			maximum: self::MOST_UNITS
		);
	}

	/**
	 * Declares a figure a receipt line expects its line to have so far: required, 0 or more.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $name        The wire name.
	 * @param string   $description The machine description.
	 * @param \Closure $label       Returns the label through a literal gettext call.
	 * @return FieldSpec The field.
	 *
	 * @phpstan-param \Closure(): string $label
	 */
	private static function soFar( string $name, string $description, \Closure $label ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: $description,
			label: $label,
			example: 0,
			required: true,
			minimum: 0
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

	/**
	 * Returns the states a transfer may be in, as their wire values.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The values.
	 */
	private static function states(): array {
		return array_map( static fn( TransferState $state ): string => $state->value, TransferState::cases() );
	}
}
