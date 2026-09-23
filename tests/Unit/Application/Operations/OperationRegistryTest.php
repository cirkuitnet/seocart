<?php
/**
 * Tests the operation registry: lazy, one id per operation, one operation per surface address
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Application\Operations\WriteMethod;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\SchemaException;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;

/**
 * Building nothing until asked, and refusing a duplicate id or a shared surface address.
 *
 * @since 0.1.0
 */
final class OperationRegistryTest extends TestCase {

	/**
	 * Tests that adding a factory builds nothing, and that all() builds each definition exactly once.
	 *
	 * @since 0.1.0
	 */
	public function test_factories_are_called_only_when_asked_and_only_once(): void {
		$calls    = 0;
		$registry = new OperationRegistry();

		$registry->add(
			FixtureStockOperation::ID,
			static function () use ( &$calls ): OperationDefinition {
				++$calls;

				return FixtureStockOperation::definition();
			}
		);

		$this->assertSame( 0, $calls, 'Adding an operation built its definition.' );

		$first  = $registry->all();
		$second = $registry->all();

		$this->assertSame( 1, $calls );
		$this->assertSame( $first, $second );
		$this->assertSame( array( FixtureStockOperation::ID ), array_map( static fn( OperationDefinition $definition ): string => $definition->id(), $first ) );
	}

	/**
	 * Tests that the production registry builds.
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_registry_builds(): void {
		$this->assertContainsOnlyInstancesOf( OperationDefinition::class, Operations::registry()->all() );
	}

	/**
	 * Tests that an id can be registered once.
	 *
	 * @since 0.1.0
	 */
	public function test_a_duplicate_id_is_refused_when_it_is_added(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The operation fixture_stock.adjust_stock is registered twice.' );

		FixtureStockOperation::register( $registry );
	}

	/**
	 * Tests that nothing can be added once the definitions are built, so no surface misses an operation.
	 *
	 * @since 0.1.0
	 */
	public function test_adding_after_the_build_is_refused(): void {
		$registry = new OperationRegistry();
		$registry->all();

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'is added after the registry was built' );

		FixtureStockOperation::register( $registry );
	}

	/**
	 * Tests that a factory must return the definition of the id it was added under.
	 *
	 * @since 0.1.0
	 */
	public function test_a_factory_returning_another_operation_is_refused(): void {
		$registry = new OperationRegistry();
		$registry->add( 'fixture_stock.count_stock', array( FixtureStockOperation::class, 'definition' ) );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The factory registered as fixture_stock.count_stock does not return the definition of fixture_stock.count_stock.' );

		$registry->all();
	}

	/**
	 * Tests that a factory must return a definition at all.
	 *
	 * @since 0.1.0
	 */
	public function test_a_factory_returning_something_else_is_refused(): void {
		$registry = new OperationRegistry();
		$registry->add( FixtureStockOperation::ID, static fn(): string => 'not a definition' ); // @phpstan-ignore argument.type (The wrong return type is the point of the test.)

		$this->expectException( SchemaException::class );

		$registry->all();
	}

	/**
	 * Provides second operations that take an address the fixture already has.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<string, mixed>, string}> Overrides of a copy of the fixture, and the address named in the message.
	 */
	public static function sharedAddresses(): array {
		return array(
			'the same route and method'                  => array(
				array(
					'ability' => 'other-ability',
					'cli'     => null,
				),
				'REST POST /fixture-stock/{}/adjustments',
			),
			'the same route with another parameter name' => array(
				array(
					'input'   => self::renamedItemInput(),
					'rest'    => new RestBinding( '/fixture-stock/{stock_item_id}/adjustments', WriteMethod::Post ),
					'ability' => 'other-ability',
					'cli'     => null,
				),
				'REST POST /fixture-stock/{}/adjustments',
			),
			'the same ability'                           => array(
				array(
					'rest' => null,
					'cli'  => null,
				),
				'ability seocart/fixture-adjust-stock',
			),
			'the same command'                           => array(
				array(
					'rest'          => null,
					'ability'       => null,
					'agent_exposed' => false,
				),
				'command wp seocart fixture-stock adjust',
			),
		);
	}

	/**
	 * Tests that two operations cannot share a surface address, which the first build refuses.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider sharedAddresses
	 *
	 * @param array<string, mixed> $overrides The second operation's differences from the fixture.
	 * @param string               $address   The shared address the message must name.
	 */
	public function test_two_operations_sharing_an_address_are_refused( array $overrides, string $address ): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$registry->add( 'fixture_stock.adjust_other_stock', static fn(): OperationDefinition => self::copy( 'fixture_stock.adjust_other_stock', $overrides ) );

		$this->expectException( SchemaException::class );
		$this->expectExceptionMessage( 'The operations fixture_stock.adjust_stock and fixture_stock.adjust_other_stock share the address ' . $address . '.' );

		$registry->all();
	}

	/**
	 * Tests that the same route with another method is a different address.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_route_with_another_method_is_another_address(): void {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );

		$registry->add(
			'fixture_stock.replace_stock',
			static fn(): OperationDefinition => self::copy(
				'fixture_stock.replace_stock',
				array(
					'rest'    => new RestBinding( FixtureStockOperation::ROUTE, WriteMethod::Put ),
					'ability' => 'fixture-replace-stock',
					'cli'     => new CliBinding( array( 'fixture-stock', 'replace' ), array( 'item_id' ) ),
				)
			)
		);

		$this->assertCount( 2, $registry->all() );
	}

	/**
	 * Builds a copy of the fixture's declaration under another id.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $id        The id.
	 * @param array<string, mixed> $overrides Named constructor arguments to replace.
	 * @return OperationDefinition The definition.
	 */
	private static function copy( string $id, array $overrides ): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			...array_merge(
				array(
					'id'             => $id,
					'label'          => $fixture->label(),
					'summary'        => $fixture->summary(),
					'input'          => $fixture->input(),
					'output'         => $fixture->output(),
					'capability'     => $fixture->capability(),
					'resource_field' => null,
					'errors'         => $fixture->errors(),
					'annotations'    => $fixture->annotations(),
					'service'        => $fixture->service(),
					'rest'           => $fixture->rest(),
					'ability'        => 'fixture-adjust-stock',
					'cli'            => $fixture->cli(),
					'agent_exposed'  => false,
				),
				$overrides
			)
		);
	}

	/**
	 * Returns the fixture's input with the item id under another name.
	 *
	 * @since 0.1.0
	 *
	 * @return list<FieldSpec> The input fields.
	 */
	private static function renamedItemInput(): array {
		$input = FixtureStockOperation::definition()->input();

		$input[] = new FieldSpec(
			name: 'stock_item_id',
			type: FieldType::Uuid,
			description: 'Identifier of the stock item.',
			label: static fn(): string => 'Stock item',
			example: FixtureStockOperation::EXAMPLE_ITEM,
			required: true
		);

		return $input;
	}
}
