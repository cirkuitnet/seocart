<?php
/**
 * CliReference: generates docs/reference/cli.md from the operation registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Support\Schema\FieldSpec;

/**
 * Documents every operation command: its synopsis, arguments, capability and error codes.
 *
 * This generator owns docs/reference/cli.md whole. The synopsis is the one the command is
 * registered with, compiled from the operation's fields, so the page cannot describe arguments
 * the command does not take. Every operation that declares a command is documented; nothing is
 * skipped, and an operation that cannot be built or documented fails the run.
 *
 * @since 0.1.0
 */
final class CliReference implements Generator {

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * The error table, for each declared code's status.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTable
	 */
	private ErrorTable $errors;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param ErrorTable        $errors   The error table.
	 */
	public function __construct( OperationRegistry $registry, ErrorTable $errors ) {
		$this->registry = $registry;
		$this->errors   = $errors;
	}

	/**
	 * Returns the generator's stable identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string The identifier.
	 */
	public function id(): string {
		return 'reference-cli';
	}

	/**
	 * Returns the file this generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @return string Path relative to the repository root.
	 */
	public function target(): string {
		return 'docs/reference/cli.md';
	}

	/**
	 * Returns the source items this generator may leave out: none.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Always empty.
	 */
	public function expectedSkips(): array {
		return array();
	}

	/**
	 * Renders the document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When an operation cannot be built or documented.
	 *
	 * @param string $current The current content. Ignored: the file is generated whole.
	 * @return GenerationResult The document. Nothing is skipped.
	 */
	public function generate( string $current ): GenerationResult {
		$lines = array(
			'# WP-CLI commands',
			'',
			FieldDocs::generatedNotice( 'the operation registry' ),
			'',
			'Each command runs one SEOCart operation, with the same application service, input validation, permission check and error codes as the operation\'s REST route and ability. Run it as a user who holds the capability it names, with `--user=<login>`. A failure prints `<code>: <message>` and ends with a non-zero exit status. Maintenance commands are not operations; `wp help seocart` lists them.',
		);
		$count = 0;

		try {
			foreach ( $this->registry->all() as $definition ) {
				$cli = $definition->cli();

				if ( null === $cli ) {
					continue;
				}

				$synopsis = ( new CompiledOperation( $definition ) )->cliSynopsis();
				$fields   = array();

				foreach ( $definition->input() as $field ) {
					$fields[ $field->name() ] = $field;
				}

				array_push(
					$lines,
					'',
					'## `wp ' . $cli->command() . '`',
					'',
					$definition->summary(),
					'',
					'```sh',
					'wp ' . $cli->command() . ' ' . implode( ' ', array_map( static fn( array $argument ): string => self::usage( $argument ), $synopsis ) ),
					'```',
					'',
					...FieldDocs::operationFacts( $definition, $this->errors )
				);
				array_push( $lines, '', '### Arguments', '' );

				foreach ( $synopsis as $argument ) {
					$lines[] = '- `' . self::usage( $argument ) . '`: ' . self::describe( $argument, $fields[ (string) $argument['name'] ] ?? null );
				}

				++$count;
			}
		} catch ( \LogicException $exception ) {
			throw new \RuntimeException( $exception->getMessage(), 0, $exception );
		}

		if ( 0 === $count ) {
			array_push( $lines, '', 'SEOCart has no operation commands yet.' );
		}

		return new GenerationResult( implode( "\n", $lines ) . "\n" );
	}

	/**
	 * Writes one synopsis entry the way `wp help` shows it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $argument The entry.
	 * @return string For example `<item_id>`, `--delta=<delta>` or `[--note=<note>]`.
	 */
	private static function usage( array $argument ): string {
		$name  = (string) $argument['name'];
		$usage = 'positional' === $argument['type'] ? '<' . $name . '>' : '--' . $name . '=<' . $name . '>';

		return empty( $argument['optional'] ) ? $usage : '[' . $usage . ']';
	}

	/**
	 * Describes one synopsis entry: the field's description and constraints, or the entry's own.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $argument The entry.
	 * @param FieldSpec|null       $field    The input field it compiles, or null for the format option.
	 * @return string The description.
	 */
	private static function describe( array $argument, ?FieldSpec $field ): string {
		$description = (string) $argument['description'];

		if ( null !== $field ) {
			return $description . ' ' . FieldDocs::describe( $field, false );
		}

		$sentences = array( $description );

		if ( isset( $argument['options'] ) && is_array( $argument['options'] ) ) {
			$sentences[] = FieldDocs::values( $argument['options'] );
		}

		if ( isset( $argument['default'] ) ) {
			$sentences[] = FieldDocs::defaultValue( (string) $argument['default'] );
		}

		return implode( ' ', $sentences );
	}
}
