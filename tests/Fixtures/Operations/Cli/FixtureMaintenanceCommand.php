<?php
/**
 * FixtureMaintenanceCommand: a maintenance command class for the tests of the maintenance list
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Fixtures\Operations\Cli;

/**
 * Stands where a real maintenance command class stands, under a `Cli/` directory, so the command
 * class search finds it and the maintenance list can be checked against it both ways.
 *
 * @since 0.1.0
 */
final class FixtureMaintenanceCommand {

	/**
	 * Does nothing: only the class's place and name matter to the tests.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]             $args       The positional arguments.
	 * @param array<string, mixed> $assoc_args The options.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args, $assoc_args );
	}
}
