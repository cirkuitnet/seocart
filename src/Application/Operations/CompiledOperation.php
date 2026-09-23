<?php
/**
 * CompiledOperation: the schemas each surface of one operation is given
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\JsonSchemaCompiler;

defined( 'ABSPATH' ) || exit;

/**
 * One operation's declaration together with its compiled schemas.
 *
 * This class owns one fact: which compiled schema each surface of an operation reads. It is the
 * one call site of the WordPress and WP-CLI dialects of JsonSchemaCompiler, so two surfaces that
 * must agree read the same array:
 *
 * - the REST route's `args` are restArguments();
 * - the Ability's input schema, the WP-CLI command's validation and the sanitization every
 *   surface applies before the service is called are all inputSchema();
 * - the REST route's response schema and the Ability's output schema are both outputSchema(),
 *   compiled from the fields a serialized output may carry;
 * - the WP-CLI command and its reference page both show cliSynopsis().
 *
 * The OpenAPI dialect has its one call site in the OpenAPI generator, its only consumer. Each
 * schema is compiled on first use and kept.
 *
 * @since 0.1.0
 */
final class CompiledOperation {

	/**
	 * The declaration.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationDefinition
	 */
	private OperationDefinition $definition;

	/**
	 * The REST route arguments, once compiled.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $restArguments = null;

	/**
	 * The input schema, once compiled.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $inputSchema = null;

	/**
	 * The output schema, once compiled.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $outputSchema = null;

	/**
	 * The WP-CLI synopsis, once compiled.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $cliSynopsis = null;

	/**
	 * Wraps a declaration. Compiles nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The declaration.
	 */
	public function __construct( OperationDefinition $definition ) {
		$this->definition = $definition;
	}

	/**
	 * Returns the declaration.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The declaration.
	 */
	public function definition(): OperationDefinition {
		return $this->definition;
	}

	/**
	 * Returns the REST route arguments: one schema per input field.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>> The arguments, keyed by wire name.
	 */
	public function restArguments(): array {
		$this->restArguments ??= JsonSchemaCompiler::restArguments( $this->definition->input() );

		return $this->restArguments;
	}

	/**
	 * Returns the input as one object schema, in the dialect WordPress validates with.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The schema, titled with the operation id.
	 */
	public function inputSchema(): array {
		$this->inputSchema ??= $this->wordPressSchema( $this->definition->id(), $this->definition->input() );

		return $this->inputSchema;
	}

	/**
	 * Returns the serialized output as one object schema, in the dialect WordPress validates with.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> The schema, titled with the resource name.
	 */
	public function outputSchema(): array {
		$output = $this->definition->output();

		$this->outputSchema ??= $this->wordPressSchema( $output->name(), $output->serializedFields() );

		return $this->outputSchema;
	}

	/**
	 * Returns the WP-CLI synopsis, or an empty list when the operation has no command.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array<string, mixed>> The synopsis.
	 */
	public function cliSynopsis(): array {
		$cli = $this->definition->cli();

		if ( null === $cli ) {
			return array();
		}

		$this->cliSynopsis ??= JsonSchemaCompiler::cliSynopsis( $this->definition->input(), $cli->positional() );

		return $this->cliSynopsis;
	}

	/**
	 * Compiles fields into the WordPress object dialect: the one call site of that dialect.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $title  The schema title.
	 * @param FieldSpec[] $fields The fields.
	 * @return array<string, mixed> The schema.
	 *
	 * @phpstan-param list<FieldSpec> $fields
	 */
	private function wordPressSchema( string $title, array $fields ): array {
		return JsonSchemaCompiler::wordPressSchema( $title, $fields );
	}
}
