<?php
/**
 * InventoryOperations: the operations that change stock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Application;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Inventory\Domain\LedgerReason;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;

defined( 'ABSPATH' ) || exit;

/**
 * Declares `inventory.adjust_stock`: a merchant's change of one variant's units on hand.
 *
 * Owns one fact: how a stock adjustment is offered to clients. One declaration serves the REST
 * route `POST seocart/v1/stock-items/{variant_id}/adjustments`, the ability
 * `seocart/adjust-stock` and the command `wp seocart stock adjust <variant_id>`; each is
 * compiled from it and none restates it. The variant is named by the URL on REST, and by the
 * input on the other two surfaces. The reasons a client may give are LedgerReason::merchant(),
 * read here and written down nowhere else.
 *
 * An adjustment is destructive, because a negative change removes stock that could be sold, and
 * it is not idempotent: a request repeated after a timeout applies again, unless the client sends
 * `expected_on_hand`, the units on hand it read, which the repeat then no longer matches. So it is
 * never exposed to agents.
 *
 * Declarations are data: building the definition reads the ledger reasons and nothing else.
 *
 * @since 0.1.0
 */
final class InventoryOperations {

	/**
	 * The id of the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUST_STOCK = 'inventory.adjust_stock';

	/**
	 * The REST route of the adjustment, relative to the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE = '/stock-items/{variant_id}/adjustments';

	/**
	 * The ability slug of the adjustment, below `seocart/`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ABILITY = 'adjust-stock';

	/**
	 * The capability the adjustment requires.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY = 'seocart_manage_inventory';

	/**
	 * The name of the resource the adjustment returns.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RESOURCE = 'StockLevel';

	/**
	 * Builds the adjustment.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function adjustStock(): OperationDefinition {
		return new OperationDefinition(
			id: self::ADJUST_STOCK,
			label: static fn(): string => __( 'Adjust stock', 'seocart' ),
			summary: 'Changes the units on hand of one variant by a signed amount, records the change in the stock ledger with its reason, and returns the stock level after it.',
			input: array(
				self::variantId(),
				new FieldSpec(
					name: 'delta',
					type: FieldType::Integer,
					description: 'The change of the units on hand: positive for units that arrived, negative for units that left; never 0.',
					label: static fn(): string => __( 'Change by', 'seocart' ),
					example: 5,
					required: true,
					minimum: -1000000,
					maximum: 1000000
				),
				new FieldSpec(
					name: 'reason',
					type: FieldType::String,
					description: 'Why the units on hand changed.',
					label: static fn(): string => __( 'Reason', 'seocart' ),
					example: LedgerReason::Received->value,
					required: true,
					allowed: array_map( static fn( LedgerReason $reason ): string => $reason->value, LedgerReason::merchant() )
				),
				new FieldSpec(
					name: 'expected_on_hand',
					type: FieldType::Integer,
					description: 'The units on hand the client last read. When given, the change applies only while the variant still has exactly that many, so a repeated request cannot apply it twice.',
					label: static fn(): string => __( 'Expected units on hand', 'seocart' ),
					example: 12,
					minimum: 0
				),
			),
			output: new ResourceSchema(
				self::RESOURCE,
				array(
					self::variantId(),
					self::count( 'on_hand', 'The units physically in stock after the change.', static fn(): string => __( 'On hand', 'seocart' ), 17 ),
					self::count( 'allocated', 'The units promised to accepted orders.', static fn(): string => __( 'Allocated', 'seocart' ), 2 ),
					self::count( 'held', 'The units held by checkouts, expired holds included until they are reclaimed.', static fn(): string => __( 'Held', 'seocart' ), 3 ),
					self::count( 'available', 'The units that may still be promised: on hand, less allocated, less held. Negative when fewer units were counted than are promised or held.', static fn(): string => __( 'Available', 'seocart' ), 12 ),
					self::count( 'ledger_entry_id', 'The id of the stock ledger entry that records the change.', static fn(): string => __( 'Ledger entry', 'seocart' ), 381 ),
				)
			),
			capability: self::CAPABILITY,
			resource_field: null,
			errors: array(
				InventoryError::ItemMissing,
				InventoryError::ZeroDelta,
				InventoryError::OnHandConflict,
				InventoryError::AdjustmentBelowZero,
				AuthorizationError::Denied,
			),
			annotations: new Annotations( read_only: false, destructive: true, idempotent: false ),
			service: array( StockService::class, 'adjustStock' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Post ),
			ability: self::ABILITY,
			cli: new CliBinding( array( 'stock', 'adjust' ), array( 'variant_id' ) )
		);
	}

	/**
	 * Declares the variant id, the same field in the input and in the output.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function variantId(): FieldSpec {
		return new FieldSpec(
			name: 'variant_id',
			type: FieldType::Integer,
			description: 'The id of the variant whose stock is adjusted.',
			label: static fn(): string => __( 'Variant', 'seocart' ),
			example: 42,
			required: true,
			minimum: 1
		);
	}

	/**
	 * Declares one required integer of the stock level.
	 *
	 * @since 0.1.0
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
