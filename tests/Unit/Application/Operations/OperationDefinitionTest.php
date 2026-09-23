<?php
/**
 * Tests the operation declaration: what it derives and what it refuses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\SchemaException;
use SEOCart\Support\SupportError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockError;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;

/**
 * The fixture's declaration, its derived values, and one refused variant per rule.
 *
 * Each refused variant changes the fixture's declaration in one place, so the rule it breaks is the
 * only reason it can be refused.
 *
 * @since 0.1.0
 */
final class OperationDefinitionTest extends TestCase {

	/**
	 * Tests that the fixture's declaration is carried as declared, and that the label is not called.
	 *
	 * @since 0.1.0
	 */
	public function test_the_fixture_is_carried_as_declared(): void {
		$definition = FixtureStockOperation::definition();

		$this->assertSame( FixtureStockOperation::ID, $definition->id() );
		$this->assertSame( 'Adjust fixture stock', ( $definition->label() )() );
		$this->assertSame( 'Adjusts the stock level of one fixture item by a signed change.', $definition->summary() );
		$this->assertSame( array( 'item_id', 'delta', 'reason', 'note' ), array_map( static fn( FieldSpec $field ): string => $field->name(), $definition->input() ) );
		$this->assertSame( 'FixtureStockLevel', $definition->output()->name() );
		$this->assertSame( 'seocart_manage_inventory', $definition->capability() );
		$this->assertNull( $definition->resourceField() );
		$this->assertSame( array( FixtureStockError::Insufficient ), $definition->errors() );
		$this->assertFalse( $definition->annotations()->isReadOnly() );
		$this->assertSame( array( FixtureStockService::class, 'adjust' ), $definition->service() );
		$this->assertTrue( $definition->isAgentExposed() );
	}

	/**
	 * Tests the derived surface addresses: the method from the annotations, the ability name and the command.
	 *
	 * @since 0.1.0
	 */
	public function test_the_surface_addresses_are_derived(): void {
		$definition = FixtureStockOperation::definition();

		$this->assertSame( 'POST', $definition->httpMethod() );
		$this->assertSame( FixtureStockOperation::ROUTE, $definition->rest()?->route() );
		$this->assertSame( FixtureStockOperation::ABILITY, $definition->abilityName() );
		$this->assertSame( FixtureStockOperation::COMMAND, $definition->cli()?->command() );
	}

	/**
	 * Tests that a read-only operation is served by GET, the only way to get GET.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_only_operation_is_served_by_get(): void {
		$definition = self::variant(
			array(
				'annotations' => new Annotations( read_only: true, destructive: false, idempotent: true ),
				'rest'        => new RestBinding( FixtureStockOperation::ROUTE ),
			)
		);

		$this->assertSame( 'GET', $definition->httpMethod() );
	}

	/**
	 * Tests the values of an operation bound to one surface only.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_without_a_surface_binding_reports_none(): void {
		$definition = self::variant(
			array(
				'rest'          => null,
				'cli'           => null,
				'agent_exposed' => false,
			)
		);

		$this->assertNull( $definition->httpMethod() );
		$this->assertNull( $definition->rest() );
		$this->assertNull( $definition->cli() );
		$this->assertFalse( $definition->isAgentExposed() );
	}

	/**
	 * Tests that a meta capability is accepted with a required route parameter that names the resource.
	 *
	 * @since 0.1.0
	 */
	public function test_a_meta_capability_is_checked_on_a_route_parameter(): void {
		$definition = self::variant(
			array(
				'capability'     => 'seocart_view_order',
				'resource_field' => 'item_id',
				'agent_exposed'  => false,
			)
		);

		$this->assertSame( 'seocart_view_order', $definition->capability() );
		$this->assertSame( 'item_id', $definition->resourceField() );
	}

	/**
	 * Provides one refused variant per rule, with the part of the message that names the rule.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> Overrides of the fixture's declaration, and the expected message part.
	 */
	public static function refusedVariants(): array {
		$optional_uuid = new FieldSpec(
			name: 'item_id',
			type: FieldType::Uuid,
			description: 'An item.',
			label: static fn(): string => 'Item',
			example: FixtureStockOperation::EXAMPLE_ITEM
		);

		return array(
			'id without a module'                  => array( array( 'id' => 'adjust_stock' ), 'is not module.verb_noun' ),
			'id with a one-word action'            => array( array( 'id' => 'fixture_stock.adjust' ), 'is not module.verb_noun' ),
			'id in kebab-case'                     => array( array( 'id' => 'fixture-stock.adjust-stock' ), 'is not module.verb_noun' ),
			'empty summary'                        => array( array( 'summary' => '' ), 'needs a summary' ),
			'input field declared twice'           => array( array( 'input' => array( self::input( 'delta' ), self::input( 'delta' ) ) ), 'declares the input field delta twice' ),
			'input that is not a field'            => array( array( 'input' => array( 'delta' ) ), 'must be a FieldSpec' ),
			'service written as a string'          => array( array( 'service' => array( FixtureStockService::class . '::adjust' ) ), 'must be written array( Service::class' ),
			'service with a named key'             => array(
				array(
					'service' => array(
						'class' => FixtureStockService::class,
						1       => 'adjust',
					),
				),
				'must be written array( Service::class',
			),
			'error code written as a string'       => array( array( 'errors' => array( 'fixture_stock.insufficient' ) ), 'must be cases of an error catalog' ),
			'error code declared twice'            => array( array( 'errors' => array( FixtureStockError::Insufficient, SupportError::UnknownCurrency, FixtureStockError::Insufficient ) ), 'declares the error code fixture_stock.insufficient twice' ),
			'capability the plugin does not know'  => array( array( 'capability' => 'manage_options' ), 'is not a primitive the plugin declares' ),
			'meta capability without its resource' => array( array( 'capability' => 'seocart_view_order' ), 'is not a primitive the plugin declares' ),
			'primitive with a resource field'      => array( array( 'resource_field' => 'item_id' ), 'is not a meta capability the plugin declares' ),
			'resource field that is no input'      => array(
				array(
					'capability'     => 'seocart_view_order',
					'resource_field' => 'order_id',
				),
				'must be a required, non-nullable uuid or integer input',
			),
			'resource field that is text'          => array(
				array(
					'capability'     => 'seocart_view_order',
					'resource_field' => 'reason',
				),
				'must be a required, non-nullable uuid or integer input',
			),
			'resource field not in the route'      => array(
				array(
					'capability'     => 'seocart_view_order',
					'resource_field' => 'item_id',
					'rest'           => new RestBinding( '/fixture-stock/adjustments', WriteMethod::Post ),
				),
				'must be a parameter of its route',
			),
			'no surface at all'                    => array(
				array(
					'rest'          => null,
					'ability'       => null,
					'cli'           => null,
					'agent_exposed' => false,
				),
				'is bound to no surface',
			),
			'route parameter that is no input'     => array( array( 'rest' => new RestBinding( '/fixture-stock/{sku}/adjustments', WriteMethod::Post ) ), 'The route parameter sku' ),
			'route parameter that is optional'     => array(
				array(
					'input' => array( $optional_uuid, self::input( 'delta' ) ),
					'cli'   => null,
				),
				'The route parameter item_id',
			),
			'read-only with a write method'        => array( array( 'annotations' => new Annotations( read_only: true, destructive: false, idempotent: true ) ), 'is read-only, so its route is served by GET' ),
			'changing without a write method'      => array( array( 'rest' => new RestBinding( FixtureStockOperation::ROUTE ) ), 'needs a write method: POST, PUT, PATCH or DELETE, never GET' ),
			'ability slug with a slash'            => array( array( 'ability' => 'seocart/adjust' ), 'is not kebab-case' ),
			'positional argument that is no input' => array( array( 'cli' => new CliBinding( array( 'fixture-stock', 'adjust' ), array( 'sku' ) ) ), 'The positional argument sku' ),
			'positional argument that is optional' => array( array( 'cli' => new CliBinding( array( 'fixture-stock', 'adjust' ), array( 'note' ) ) ), 'The positional argument note' ),
			'nullable input field'                 => array(
				array(
					'input' => array(
						FixtureStockOperation::definition()->input()[0],
						self::input( 'delta' ),
						new FieldSpec(
							name: 'note',
							type: FieldType::String,
							description: 'A note.',
							label: static fn(): string => 'Note',
							example: 'x',
							nullable: true
						),
					),
				),
				'The input field note of fixture_stock.adjust_stock is nullable',
			),
		);
	}

	/**
	 * Tests that each variant breaking one rule is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedVariants
	 *
	 * @param array<string, mixed> $overrides        The one change to the fixture's declaration.
	 * @param string               $expected_message Part of the message.
	 */
	public function test_a_declaration_breaking_a_rule_is_refused( array $overrides, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		self::variant( $overrides );
	}

	/**
	 * Provides the variants that ask for agent exposure an operation may never have. Pinned: these
	 * operations are never exposed to agents, whatever the declaration says.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> Overrides, and the expected message part.
	 */
	public static function neverExposedVariants(): array {
		$destructive = new Annotations( read_only: false, destructive: true, idempotent: false );
		$on_resource = 'is checked on one resource, so what it can reach cannot be told from its declaration, and it is never exposed to agents';

		return array(
			'destructive'                 => array( array( 'annotations' => $destructive ), 'is destructive, and a destructive operation is never exposed to agents' ),
			'moves money: refunds'        => array(
				array(
					'capability'  => 'seocart_refund_orders',
					'annotations' => $destructive,
				),
				'requires seocart_refund_orders: it moves money or reads personal data in bulk',
			),
			'moves money: one refund'     => array(
				array(
					'capability'     => 'seocart_refund_order',
					'resource_field' => 'item_id',
					'annotations'    => $destructive,
				),
				'requires seocart_refund_order, which ' . $on_resource,
			),
			'moves money: captures'       => array(
				array(
					'capability'  => 'seocart_capture_payments',
					'annotations' => $destructive,
				),
				'requires seocart_capture_payments: it moves money',
			),
			'moves money: stored value'   => array(
				array(
					'capability'  => 'seocart_manage_stored_value',
					'annotations' => $destructive,
				),
				'requires seocart_manage_stored_value: it moves money',
			),
			'overrides money state'       => array(
				array(
					'capability'  => 'seocart_override_money_state',
					'annotations' => $destructive,
				),
				'requires seocart_override_money_state: it moves money',
			),
			'edits one order'             => array(
				array(
					'capability'     => 'seocart_edit_order',
					'resource_field' => 'item_id',
				),
				'requires seocart_edit_order, which ' . $on_resource,
			),
			'reads one customer'          => array(
				array(
					'capability'     => 'seocart_view_customer',
					'resource_field' => 'item_id',
				),
				'requires seocart_view_customer, which ' . $on_resource,
			),
			'edits one product'           => array(
				array(
					'capability'     => 'edit_seocart_product',
					'resource_field' => 'item_id',
				),
				'requires edit_seocart_product, which ' . $on_resource,
			),
			'reads personal data in bulk' => array( array( 'capability' => 'seocart_export_customers' ), 'requires seocart_export_customers: it moves money or reads personal data in bulk' ),
			'exports orders'              => array( array( 'capability' => 'seocart_export_orders' ), 'requires seocart_export_orders' ),
			'reads personal data'         => array( array( 'capability' => 'seocart_view_customer_pii' ), 'requires seocart_view_customer_pii' ),
			'exposed without an ability'  => array( array( 'ability' => null ), 'is exposed to agents but has no ability' ),
		);
	}

	/**
	 * Tests that agent exposure is refused for a destructive operation, one that moves money, and one that reads personal data in bulk.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider neverExposedVariants
	 *
	 * @param array<string, mixed> $overrides        The change to the fixture's declaration, which asks for exposure.
	 * @param string               $expected_message Part of the message.
	 */
	public function test_an_operation_that_may_never_be_exposed_to_agents_is_refused( array $overrides, string $expected_message ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( $expected_message );

		self::variant( $overrides + array( 'agent_exposed' => true ) );
	}

	/**
	 * Tests that the same operations are accepted when they do not ask for exposure: the rule is about agents only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_operations_are_accepted_without_agent_exposure(): void {
		foreach ( self::neverExposedVariants() as $name => $case ) {
			$definition = self::variant( $case[0] + array( 'agent_exposed' => false ) );

			$this->assertFalse( $definition->isAgentExposed(), $name );
		}
	}

	/**
	 * Provides operations that move money but are not annotated destructive.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>}> Overrides of the fixture's declaration.
	 */
	public static function moneyWithoutDestructive(): array {
		$cases = array();

		foreach ( array( 'seocart_capture_payments', 'seocart_void_payments', 'seocart_refund_orders', 'seocart_manage_stored_value', 'seocart_override_money_state' ) as $capability ) {
			$cases[ $capability ] = array( array( 'capability' => $capability ) );
		}

		$cases['seocart_refund_order'] = array(
			array(
				'capability'     => 'seocart_refund_order',
				'resource_field' => 'item_id',
			),
		);

		return $cases;
	}

	/**
	 * Tests that an operation that moves money must be annotated destructive, exposed or not.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider moneyWithoutDestructive
	 *
	 * @param array<string, mixed> $overrides The capability, and the resource field of a meta capability.
	 */
	public function test_an_operation_that_moves_money_must_be_annotated_destructive( array $overrides ): void {
		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'moves money, so it must be annotated destructive' );

		self::variant( $overrides + array( 'agent_exposed' => false ) );
	}

	/**
	 * Tests which meta capabilities count as moving money: exactly the refund of one order.
	 *
	 * Every other capability's group is declared by CapabilityDeclaration; a meta capability has no
	 * group, so OperationDefinition keeps the money ones in a list of its own. This test is that
	 * list's companion: it tries every declared meta capability and requires the refused ones to be
	 * exactly the ones named here, so a new meta capability is classified in the change that adds it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_meta_capabilities_that_move_money_are_exactly_the_listed_ones(): void {
		$moves_money = array();

		foreach ( ( new CapabilityDeclaration() )->metaCapabilities() as $capability ) {
			try {
				self::variant(
					array(
						'capability'     => $capability,
						'resource_field' => 'item_id',
						'agent_exposed'  => false,
					)
				);
			} catch ( SchemaException $exception ) {
				$this->assertStringContainsString( 'moves money, so it must be annotated destructive', $exception->getMessage() );

				$moves_money[] = $capability;
			}
		}

		$this->assertSame( array( 'seocart_refund_order' ), $moves_money );
	}

	/**
	 * Builds the fixture's declaration with some named arguments replaced.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $overrides Named constructor arguments to replace.
	 * @return OperationDefinition The definition.
	 */
	private static function variant( array $overrides ): OperationDefinition {
		$fixture   = FixtureStockOperation::definition();
		$arguments = array_merge(
			array(
				'id'             => $fixture->id(),
				'label'          => $fixture->label(),
				'summary'        => $fixture->summary(),
				'input'          => $fixture->input(),
				'output'         => $fixture->output(),
				'capability'     => $fixture->capability(),
				'resource_field' => $fixture->resourceField(),
				'errors'         => $fixture->errors(),
				'annotations'    => $fixture->annotations(),
				'service'        => $fixture->service(),
				'rest'           => $fixture->rest(),
				'ability'        => 'fixture-adjust-stock',
				'cli'            => $fixture->cli(),
				'agent_exposed'  => $fixture->isAgentExposed(),
			),
			$overrides
		);

		return new OperationDefinition( ...$arguments );
	}

	/**
	 * Declares a required integer input.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name The name.
	 * @return FieldSpec The field.
	 */
	private static function input( string $name ): FieldSpec {
		return new FieldSpec(
			name: $name,
			type: FieldType::Integer,
			description: 'A number.',
			label: static fn(): string => 'Number',
			example: 1,
			required: true
		);
	}
}
