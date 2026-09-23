<?php
/**
 * Tests which compiled schema each surface of the fixture operation reads
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Application\Operations;

use PHPUnit\Framework\TestCase;
use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;

/**
 * The input fields feed the REST arguments, the input schema and the synopsis; the serialized
 * output fields, and only they, feed the output schema.
 *
 * @since 0.1.0
 */
final class CompiledOperationTest extends TestCase {

	/**
	 * Tests that the REST arguments are the input fields.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rest_arguments_are_the_input_fields(): void {
		$arguments = self::compiled()->restArguments();

		$this->assertSame( array( 'item_id', 'delta', 'reason', 'note' ), array_keys( $arguments ) );
		$this->assertSame( 'correction', $arguments['reason']['default'] );
	}

	/**
	 * Tests that the input schema is titled with the operation id and requires the required inputs.
	 *
	 * @since 0.1.0
	 */
	public function test_the_input_schema_is_the_input_fields(): void {
		$schema = self::compiled()->inputSchema();

		$this->assertSame( FixtureStockOperation::ID, $schema['title'] );
		$this->assertSame( JsonSchemaCompiler::WORDPRESS_SCHEMA_DRAFT, $schema['$schema'] );
		$this->assertSame( array( 'item_id', 'delta' ), $schema['required'] );
		$this->assertSame( array( 'item_id', 'delta', 'reason', 'note' ), array_keys( $schema['properties'] ) );
	}

	/**
	 * Tests that the output schema describes what a serialized output may carry: no secret, personal data optional.
	 *
	 * @since 0.1.0
	 */
	public function test_the_output_schema_is_the_serialized_output(): void {
		$schema = self::compiled()->outputSchema();

		$this->assertSame( 'FixtureStockLevel', $schema['title'] );
		$this->assertSame( array( 'item_id', 'on_hand', 'reason', 'note' ), array_keys( $schema['properties'] ), 'The secret audit token is not in the output schema.' );
		$this->assertSame( array( 'item_id', 'on_hand', 'reason' ), $schema['required'], 'The personal-data note is left out for some users, so it is not required.' );
		$this->assertSame( array( 'string', 'null' ), $schema['properties']['note']['type'] );
	}

	/**
	 * Tests that the synopsis gives the item id as a positional argument.
	 *
	 * @since 0.1.0
	 */
	public function test_the_synopsis_is_compiled_from_the_input_and_the_binding(): void {
		$synopsis = self::compiled()->cliSynopsis();

		$this->assertSame( array( 'item_id', 'delta', 'reason', 'note', 'format' ), array_column( $synopsis, 'name' ) );
		$this->assertSame( 'positional', $synopsis[0]['type'] );
	}

	/**
	 * Tests that an operation without a command has no synopsis.
	 *
	 * @since 0.1.0
	 */
	public function test_an_operation_without_a_command_has_no_synopsis(): void {
		$fixture    = FixtureStockOperation::definition();
		$definition = new OperationDefinition(
			id: $fixture->id(),
			label: $fixture->label(),
			summary: $fixture->summary(),
			input: $fixture->input(),
			output: $fixture->output(),
			capability: $fixture->capability(),
			resource_field: null,
			errors: $fixture->errors(),
			annotations: $fixture->annotations(),
			service: $fixture->service(),
			rest: $fixture->rest()
		);

		$this->assertSame( array(), ( new CompiledOperation( $definition ) )->cliSynopsis() );
	}

	/**
	 * Tests that the compiled operation hands back its declaration.
	 *
	 * @since 0.1.0
	 */
	public function test_the_declaration_is_kept(): void {
		$definition = FixtureStockOperation::definition();

		$this->assertSame( $definition, ( new CompiledOperation( $definition ) )->definition() );
	}

	/**
	 * Returns the fixture, compiled.
	 *
	 * @since 0.1.0
	 *
	 * @return CompiledOperation The operation.
	 */
	private static function compiled(): CompiledOperation {
		return new CompiledOperation( FixtureStockOperation::definition() );
	}
}
