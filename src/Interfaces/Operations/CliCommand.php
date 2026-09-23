<?php
/**
 * CliCommand: runs one operation from the command line
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Support\Schema\JsonSchemaCompiler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The WP-CLI command of one operation.
 *
 * This class owns one fact: the order in which a command handles its arguments, which is the
 * order the REST API and the Abilities API use as well — validate, then check the permission,
 * then run. The arguments are validated against the operation's input schema, the same schema and
 * the same WordPress validator the Ability uses, so the same bad input gets the same message; the
 * permission is PermissionFactory's; the run is OperationInvoker's.
 *
 * Arguments arrive as text. Validation accepts a whole number written as text for an integer
 * field, as the REST API does, and the invoker's sanitization turns it into an integer.
 *
 * @since 0.1.0
 */
final class CliCommand {

	/**
	 * The operation.
	 *
	 * @since 0.1.0
	 *
	 * @var CompiledOperation
	 */
	private CompiledOperation $operation;

	/**
	 * Runs an operation's service.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationInvoker
	 */
	private OperationInvoker $invoker;

	/**
	 * Prints one result in a format.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(array<string, mixed>, string): void
	 */
	private $display;

	/**
	 * Reports a failure and ends the command with a non-zero exit status.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string): void
	 */
	private $fail;

	/**
	 * Creates the command.
	 *
	 * @since 0.1.0
	 *
	 * @param CompiledOperation $operation The operation.
	 * @param OperationInvoker  $invoker   Runs the operation's service.
	 * @param callable          $display     Prints one result in the format asked for.
	 * @param callable          $fail      Reports a failure: WP_CLI::error(), which also exits.
	 *
	 * @phpstan-param callable(array<string, mixed>, string): void $display
	 * @phpstan-param callable(string): void                       $fail
	 */
	public function __construct( CompiledOperation $operation, OperationInvoker $invoker, callable $display, callable $fail ) {
		$this->operation = $operation;
		$this->invoker   = $invoker;
		$this->display   = $display;
		$this->fail      = $fail;
	}

	/**
	 * Runs the operation with the command's arguments. WP-CLI calls it.
	 *
	 * On failure it reports `<code>: <message>` through the fail function and prints nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]             $args       The positional arguments, in synopsis order.
	 * @param array<string, mixed> $assoc_args The options, keyed by name, `format` among them.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$definition = $this->operation->definition();
		$format     = $assoc_args[ JsonSchemaCompiler::CLI_FORMAT_OPTION ] ?? JsonSchemaCompiler::CLI_FORMATS[0];

		if ( ! in_array( $format, JsonSchemaCompiler::CLI_FORMATS, true ) ) {
			( $this->fail )(
				'rest_invalid_param: ' . sprintf(
					/* translators: 1: The name of the format option. 2: The formats it accepts, separated by commas. */
					__( '--%1$s must be one of %2$s.', 'seocart' ),
					JsonSchemaCompiler::CLI_FORMAT_OPTION,
					implode( ', ', JsonSchemaCompiler::CLI_FORMATS )
				)
			);

			return;
		}

		$positional = null === $definition->cli() ? array() : $definition->cli()->positional();
		$values     = array();

		foreach ( array_values( $args ) as $index => $value ) {
			if ( isset( $positional[ $index ] ) ) {
				$values[ $positional[ $index ] ] = $value;
			}
		}

		foreach ( $definition->input() as $field ) {
			if ( ! in_array( $field->name(), $positional, true ) && array_key_exists( $field->name(), $assoc_args ) ) {
				$values[ $field->name() ] = $assoc_args[ $field->name() ];
			}
		}

		$valid = rest_validate_value_from_schema( $values, $this->operation->inputSchema(), 'input' );

		if ( $valid instanceof WP_Error ) {
			( $this->fail )( self::describe( $valid ) );

			return;
		}

		if ( ! PermissionFactory::allows( $definition, $values ) ) {
			( $this->fail )(
				'rest_forbidden: ' . sprintf(
					/* translators: %s: A capability name, such as seocart_manage_inventory. */
					__( 'The current user may not run this command, which requires the capability %s. Run it as a user who has it, with --user.', 'seocart' ),
					$definition->capability()
				)
			);

			return;
		}

		$result = $this->invoker->invoke( $this->operation, $values );

		if ( $result instanceof WP_Error ) {
			( $this->fail )( self::describe( $result ) );

			return;
		}

		( $this->display )( $result, (string) $format );
	}

	/**
	 * Describes an error the way the command reports it.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_Error $error The error.
	 * @return string `<code>: <message>`.
	 */
	private static function describe( WP_Error $error ): string {
		return $error->get_error_code() . ': ' . $error->get_error_message();
	}
}
