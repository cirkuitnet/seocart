<?php
/**
 * CliBinding: the WP-CLI command an operation is run with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

use SEOCart\Support\Schema\SchemaException;

defined( 'ABSPATH' ) || exit;

/**
 * The command path of an operation below `wp seocart`, and which inputs are positional.
 *
 * This class owns one fact: the address of an operation on the command line. Every input field
 * that is not positional is an `--name=<value>` option; the synopsis is compiled from the fields.
 *
 * @since 0.1.0
 */
final class CliBinding {

	/**
	 * The WP-CLI command every SEOCart command is a subcommand of.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROOT = 'seocart';

	/**
	 * One word of a command path: kebab-case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const WORD_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/';

	/**
	 * The command path below the root, one word per level.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $path;

	/**
	 * The input fields given as positional arguments, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $positional;

	/**
	 * Declares the command.
	 *
	 * @since 0.1.0
	 *
	 * @throws SchemaException When the path is empty or a word is not kebab-case, or a positional
	 *                         name is repeated.
	 *
	 * @param string[] $path       The command path below `wp seocart`, such as array( 'stock', 'adjust' ).
	 * @param string[] $positional Optional. The input fields given as positional arguments, in
	 *                             order. OperationDefinition checks that each is a required input.
	 *                             Default none.
	 *
	 * @phpstan-param list<string> $path
	 * @phpstan-param list<string> $positional
	 */
	public function __construct( array $path, array $positional = array() ) {
		if ( array() === $path ) {
			SchemaException::raise( 'A command needs a path below wp %1$s.', self::ROOT );
		}

		foreach ( $path as $word ) {
			if ( 1 !== preg_match( self::WORD_PATTERN, $word ) ) {
				SchemaException::raise( 'The command word "%1$s" is not kebab-case.', $word );
			}
		}

		if ( count( array_unique( $positional ) ) !== count( $positional ) ) {
			SchemaException::raise( 'The command %1$s names a positional argument twice.', implode( ' ', $path ) );
		}

		$this->path       = $path;
		$this->positional = $positional;
	}

	/**
	 * Returns the command as WP_CLI::add_command() names it: the root and the path.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `seocart stock adjust`.
	 */
	public function command(): string {
		return self::ROOT . ' ' . implode( ' ', $this->path );
	}

	/**
	 * Returns the input fields given as positional arguments.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The names, in order.
	 */
	public function positional(): array {
		return $this->positional;
	}
}
