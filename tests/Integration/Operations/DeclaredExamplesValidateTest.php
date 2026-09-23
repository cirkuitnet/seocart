<?php
/**
 * Tests that every declared example and default validates against its own compiled schemas (DRY rule 13)
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use WP_UnitTestCase;

/**
 * Walks the registry — the production operations and the fixture — and validates, with WordPress's
 * own validator, each example and each default against every schema compiled from its field:
 *
 * - an input field's REST argument, the way the REST API validates one argument;
 * - the input object made of every input example, against the input schema the Ability and the
 *   command validate with;
 * - the output object made of every serialized output example, against the output schema;
 * - both objects against the OpenAPI schemas compiled from the same fields.
 *
 * A declared example the API would refuse is documentation that lies. The second test shows a
 * field whose example breaks its own constraint, and requires the walk to report it.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class DeclaredExamplesValidateTest extends WP_UnitTestCase {

	/**
	 * Tests every example and default of every operation in the registry.
	 *
	 * @since 0.1.0
	 */
	public function test_every_example_validates_against_its_compiled_schemas(): void {
		$registry = Operations::registry();

		FixtureStockOperation::register( $registry );

		$problems = array();
		$checked  = 0;

		foreach ( $registry->all() as $definition ) {
			$problems = array_merge( $problems, self::problems( $definition ) );
			++$checked;
		}

		$this->assertGreaterThanOrEqual( 1, $checked, 'The walk checked no operation.' );
		$this->assertSame( array(), $problems, "Declared examples the API would refuse:\n  " . implode( "\n  ", $problems ) . "\n" );
	}

	/**
	 * Tests that the walk reports an example that breaks its own field's constraint.
	 *
	 * @since 0.1.0
	 */
	public function test_the_walk_reports_an_example_its_schema_refuses(): void {
		$fixture = FixtureStockOperation::definition();
		$broken  = new FieldSpec(
			name: 'count',
			type: FieldType::Integer,
			description: 'How many.',
			label: static fn(): string => 'Count',
			example: 12,
			default_value: 0,
			maximum: 10
		);

		$definition = new OperationDefinition(
			id: 'fixture_stock.count_stock',
			label: $fixture->label(),
			summary: $fixture->summary(),
			input: array_merge( $fixture->input(), array( $broken ) ),
			output: new ResourceSchema( 'BrokenCount', array( $broken ) ),
			capability: $fixture->capability(),
			resource_field: null,
			errors: array(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			ability: 'fixture-count-stock'
		);

		$problems = self::problems( $definition );

		$this->assertContains( 'fixture_stock.count_stock: the example of the input field count is refused by its REST argument: count must be less than or equal to 10', $problems );
		$this->assertContains( 'fixture_stock.count_stock: the input examples are refused by the input schema: input[count] must be less than or equal to 10', $problems );
		$this->assertContains( 'fixture_stock.count_stock: the output examples are refused by the output schema: output[count] must be less than or equal to 10', $problems );
		$this->assertContains( 'fixture_stock.count_stock: the input examples are refused by the OpenAPI schema: input[count] must be less than or equal to 10', $problems );
		$this->assertContains( 'fixture_stock.count_stock: the output examples are refused by the OpenAPI schema: output[count] must be less than or equal to 10', $problems );
		$this->assertCount( 5, $problems, 'The five refusals above, and nothing about the valid default.' . "\n" . implode( "\n", $problems ) );
	}

	/**
	 * Validates one operation's examples and defaults.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @return list<string> One message per refusal.
	 */
	private static function problems( OperationDefinition $definition ): array {
		$compiled  = new CompiledOperation( $definition );
		$id        = $definition->id();
		$problems  = array();
		$arguments = $compiled->restArguments();
		$inputs    = array();
		$outputs   = array();

		foreach ( $definition->input() as $field ) {
			$inputs[ $field->name() ] = $field->example();

			foreach ( array(
				'example' => $field->example(),
				'default' => $field->defaultValue(),
			) as $kind => $value ) {
				if ( null === $value ) {
					continue;
				}

				$valid = rest_validate_value_from_schema( $value, $arguments[ $field->name() ], $field->name() );

				if ( is_wp_error( $valid ) ) {
					$problems[] = $id . ': the ' . $kind . ' of the input field ' . $field->name() . ' is refused by its REST argument: ' . $valid->get_error_message();
				}
			}
		}

		foreach ( $definition->output()->serializedFields() as $field ) {
			$outputs[ $field->name() ] = $field->example();
		}

		$objects = array(
			'the input examples are refused by the input schema'      => array( $inputs, $compiled->inputSchema(), 'input' ),
			'the output examples are refused by the output schema'    => array( $outputs, $compiled->outputSchema(), 'output' ),
			'the input examples are refused by the OpenAPI schema'    => array( $inputs, JsonSchemaCompiler::openApiSchema( $definition->input() ), 'input' ),
			'the output examples are refused by the OpenAPI schema'   => array( $outputs, JsonSchemaCompiler::openApiSchema( $definition->output()->serializedFields() ), 'output' ),
		);

		foreach ( $objects as $what => list( $value, $schema, $name ) ) {
			$valid = rest_validate_value_from_schema( $value, $schema, $name );

			if ( is_wp_error( $valid ) ) {
				$problems[] = $id . ': ' . $what . ': ' . $valid->get_error_message();
			}
		}

		return $problems;
	}
}
