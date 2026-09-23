<?php
/**
 * FixtureStockOperation: the one operation the operations mechanism is built and tested against
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\Privacy;
use SEOCart\Support\Schema\ResourceSchema;

/**
 * Declares "adjust fixture stock", shaped like the first real operation, stock adjustment.
 *
 * A uuid resource id in the path, a signed integer change, a reason from a closed set with a
 * default, an optional personal-data note, one declared error code, and a changing (not read-only)
 * annotation, bound to a REST route, an ability and a command. Its output carries a nullable
 * personal-data field and a secret, so every privacy rule is exercised.
 *
 * The production registry does not contain it; tests add it with register(). Its texts are plain
 * strings rather than gettext calls, so no fixture text can reach the plugin's translation template.
 *
 * @since 0.1.0
 */
final class FixtureStockOperation {

	/**
	 * The operation id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = 'fixture_stock.adjust_stock';

	/**
	 * The REST route, relative to the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUTE = '/fixture-stock/{item_id}/adjustments';

	/**
	 * The ability name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ABILITY = 'seocart/fixture-adjust-stock';

	/**
	 * The command, as WP_CLI::add_command() names it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const COMMAND = 'seocart fixture-stock adjust';

	/**
	 * An item id the examples use.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const EXAMPLE_ITEM = '0b6f2c52-7f0a-4c1e-9a55-3f0a4e0f6d21';

	/**
	 * Adds the operation's factory to a registry.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The registry.
	 */
	public static function register( OperationRegistry $registry ): void {
		$registry->add( self::ID, array( self::class, 'definition' ) );
	}

	/**
	 * Builds the definition.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function definition(): OperationDefinition {
		return new OperationDefinition(
			id: self::ID,
			label: static fn(): string => 'Adjust fixture stock',
			summary: 'Adjusts the stock level of one fixture item by a signed change.',
			input: array(
				self::itemId(),
				new FieldSpec(
					name: 'delta',
					type: FieldType::Integer,
					description: 'Signed change to the stock level. A negative value decreases it.',
					label: static fn(): string => 'Change by',
					example: -3,
					required: true,
					minimum: -1000000,
					maximum: 1000000
				),
				self::reason( false ),
				new FieldSpec(
					name: 'note',
					type: FieldType::String,
					description: 'Free-text note stored with the adjustment.',
					label: static fn(): string => 'Note',
					example: 'Two units were found behind the shelf.',
					max_length: 500,
					privacy: Privacy::Pii
				),
			),
			output: new ResourceSchema(
				'FixtureStockLevel',
				array(
					self::itemId(),
					new FieldSpec(
						name: 'on_hand',
						type: FieldType::Integer,
						description: 'Stock level of the item after the adjustment.',
						label: static fn(): string => 'In stock',
						example: 12,
						required: true
					),
					self::reason( true ),
					new FieldSpec(
						name: 'note',
						type: FieldType::String,
						description: 'Free-text note stored with the adjustment, or null when there is none.',
						label: static fn(): string => 'Note',
						example: 'Two units were found behind the shelf.',
						required: true,
						nullable: true,
						privacy: Privacy::Pii
					),
					new FieldSpec(
						name: 'audit_token',
						type: FieldType::String,
						description: 'Opaque token of the adjustment in the audit trail.',
						label: static fn(): string => 'Audit token',
						example: 'token-example',
						privacy: Privacy::Secret
					),
				)
			),
			capability: 'seocart_manage_inventory',
			resource_field: null,
			errors: array( FixtureStockError::Insufficient ),
			annotations: new Annotations( read_only: false, destructive: false, idempotent: false ),
			service: array( FixtureStockService::class, 'adjust' ),
			rest: new RestBinding( self::ROUTE, WriteMethod::Post ),
			ability: 'fixture-adjust-stock',
			cli: new CliBinding( array( 'fixture-stock', 'adjust' ), array( 'item_id' ) ),
			agent_exposed: true
		);
	}

	/**
	 * Declares the item id, the same field in the input and in the output.
	 *
	 * @since 0.1.0
	 *
	 * @return FieldSpec The field.
	 */
	private static function itemId(): FieldSpec {
		return new FieldSpec(
			name: 'item_id',
			type: FieldType::Uuid,
			description: 'Identifier of the stock item whose level is adjusted.',
			label: static fn(): string => 'Stock item',
			example: self::EXAMPLE_ITEM,
			required: true
		);
	}

	/**
	 * Declares the reason: optional with a default in the input, always present in the output.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $in_output Whether the field is declared for the output.
	 * @return FieldSpec The field.
	 */
	private static function reason( bool $in_output ): FieldSpec {
		return new FieldSpec(
			name: 'reason',
			type: FieldType::String,
			description: 'Why the stock level changed.',
			label: static fn(): string => 'Reason',
			example: 'recount',
			required: $in_output,
			default_value: $in_output ? null : 'correction',
			allowed: array( 'recount', 'damage', 'return', 'correction' )
		);
	}
}
