<?php
/**
 * CliAdapter: registers every operation that has a WP-CLI command
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `wp seocart <path>` for each operation that declares a command.
 *
 * This class owns one fact: how an operation becomes a WP-CLI command — its name from the
 * binding, its description from the summary, its synopsis compiled from the input fields, and a
 * CliCommand that validates, checks the permission and runs the operation like every other
 * surface.
 *
 * It names no WP-CLI symbol itself: the kernel passes WP-CLI's functions in, under WP-CLI only,
 * so the commands are tested without WP-CLI loaded:
 *
 *     $cli_adapter->register(
 *         array( 'WP_CLI', 'add_command' ),
 *         static function ( array $item, string $format ): void {
 *             ( new \WP_CLI\Formatter( $args = array( 'format' => $format ), array_keys( $item ) ) )->display_item( $item );
 *         },
 *         array( 'WP_CLI', 'error' )
 *     );
 *
 * Maintenance commands such as `wp seocart migrate` are operational tooling with no REST route or
 * ability, so they have no definition and are registered by their own modules.
 *
 * @since 0.1.0
 */
final class CliAdapter {

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * Runs an operation's service.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationInvoker
	 */
	private OperationInvoker $invoker;

	/**
	 * Creates the adapter. Registers nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param OperationInvoker  $invoker  Runs an operation's service.
	 */
	public function __construct( OperationRegistry $registry, OperationInvoker $invoker ) {
		$this->registry = $registry;
		$this->invoker  = $invoker;
	}

	/**
	 * Registers the command of every operation that has one.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $add_command Registers a command: WP_CLI::add_command().
	 * @param callable $display       Prints one result in the format the user asked for.
	 * @param callable $fail        Reports a failure and ends the command with a non-zero exit
	 *                              status: WP_CLI::error().
	 *
	 * @phpstan-param callable(string, callable, array<string, mixed>): mixed $add_command
	 * @phpstan-param callable(array<string, mixed>, string): void           $display
	 * @phpstan-param callable(string): void                                 $fail
	 */
	public function register( callable $add_command, callable $display, callable $fail ): void {
		foreach ( $this->registry->all() as $definition ) {
			$cli = $definition->cli();

			if ( null === $cli ) {
				continue;
			}

			$operation = new CompiledOperation( $definition );

			$add_command(
				$cli->command(),
				new CliCommand( $operation, $this->invoker, $display, $fail ),
				array(
					'shortdesc' => $definition->summary(),
					'synopsis'  => $operation->cliSynopsis(),
				)
			);
		}
	}
}
