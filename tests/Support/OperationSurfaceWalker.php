<?php
/**
 * OperationSurfaceWalker: checks that every plugin route, ability and command resolves to one operation
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Application\Operations\CliBinding;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Tests\Unit\Support\PhpSource;
use WP_REST_Server;

/**
 * Compares what is registered on each surface with what the operation registry declares, both ways.
 *
 * One declaration per operation: every REST route of a `seocart*` namespace, every `seocart/`
 * ability and every `wp seocart` command must be the address of exactly one operation definition,
 * and every address a definition declares must really be registered — so a route registered only
 * under some condition cannot make the walk pass with nothing checked.
 *
 * The one exception is a maintenance command: operational tooling such as applying migrations,
 * which has no REST route or ability and so no definition. Those are an asserted set,
 * MAINTENANCE_COMMANDS, each with its class and the reason. A command class under a `Cli/`
 * directory of src/ that is not listed fails the walk, and so does a listed command whose class is
 * gone.
 *
 * The REST walk reuses RoutePermissionWalker's decision of which routes are the plugin's, so the
 * two walks cannot disagree about that.
 *
 * @since 0.1.0
 */
final class OperationSurfaceWalker {

	/**
	 * The maintenance commands: no operation definition by design.
	 *
	 * Keyed by the command as WP_CLI::add_command() names it, such as `seocart migrate`, each with
	 * the class that implements it and the reason it has no definition. Add a command here in the
	 * change that adds its class; the walk fails until it is listed. Empty until the first
	 * maintenance command is on this branch.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{class: string, reason: string}>
	 */
	public const MAINTENANCE_COMMANDS = array();

	/**
	 * Checks the plugin's REST routes against the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server    $server   A server on which `rest_api_init` has run.
	 * @param OperationRegistry $registry The operations that should be registered.
	 * @return list<string> One message per route without an operation and per operation route not registered.
	 */
	public static function restViolations( WP_REST_Server $server, OperationRegistry $registry ): array {
		$declared = array();

		foreach ( $registry->all() as $definition ) {
			$rest = $definition->rest();

			if ( null !== $rest ) {
				$declared[ $definition->httpMethod() . ' ' . RestAdapter::serverRoute( $rest ) ] = $definition->id();
			}
		}

		$registered = array();
		$routes     = $server->get_routes();

		foreach ( ( new RoutePermissionWalker() )->walk( $server )['plugin_routes'] as $route ) {
			foreach ( $routes[ $route ] as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
					$registered[ $method . ' ' . $route ] = true;
				}
			}
		}

		return self::compare( $declared, $registered, 'the REST route' );
	}

	/**
	 * Checks the plugin's abilities against the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]          $names    Every ability name registered with WordPress.
	 * @param OperationRegistry $registry The operations that should be registered.
	 * @return list<string> One message per ability without an operation and per operation ability not registered.
	 *
	 * @phpstan-param list<string> $names
	 */
	public static function abilityViolations( array $names, OperationRegistry $registry ): array {
		$declared = array();

		foreach ( $registry->all() as $definition ) {
			if ( null !== $definition->abilityName() ) {
				$declared[ $definition->abilityName() ] = $definition->id();
			}
		}

		$registered = array();

		foreach ( $names as $name ) {
			if ( str_starts_with( $name, OperationDefinition::ABILITY_NAMESPACE . '/' ) ) {
				$registered[ $name ] = true;
			}
		}

		return self::compare( $declared, $registered, 'the ability' );
	}

	/**
	 * Checks the plugin's commands against the registry and the maintenance set.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                                            $commands    Every command registered, as WP_CLI::add_command() names it.
	 * @param OperationRegistry                                   $registry    The operations that should be registered.
	 * @param array<string, array{class: string, reason: string}> $maintenance The maintenance commands.
	 * @param string[]                                            $classes     The command classes found under `Cli/` directories of src/.
	 * @return list<string> One message per unresolved, unregistered or unlisted command.
	 *
	 * @phpstan-param list<string> $commands
	 * @phpstan-param list<string> $classes
	 */
	public static function commandViolations( array $commands, OperationRegistry $registry, array $maintenance, array $classes ): array {
		$declared = array();

		foreach ( $registry->all() as $definition ) {
			if ( null !== $definition->cli() ) {
				$declared[ $definition->cli()->command() ] = $definition->id();
			}
		}

		foreach ( $maintenance as $command => $entry ) {
			$declared[ $command ] = 'the maintenance command class ' . $entry['class'];
		}

		$registered = array();

		foreach ( $commands as $command ) {
			if ( str_starts_with( $command . ' ', CliBinding::ROOT . ' ' ) ) {
				$registered[ $command ] = true;
			}
		}

		$violations = self::compare( $declared, $registered, 'the command' );
		$listed     = array_column( $maintenance, 'class' );

		foreach ( $classes as $class ) {
			if ( ! in_array( $class, $listed, true ) ) {
				$violations[] = "The command class {$class} is neither an operation's command nor listed as a maintenance command with its reason.";
			}
		}

		foreach ( $maintenance as $command => $entry ) {
			if ( ! in_array( $entry['class'], $classes, true ) ) {
				$violations[] = "The maintenance command {$command} is listed, but its class {$entry['class']} no longer exists: remove it from the list.";
			}
		}

		return $violations;
	}

	/**
	 * Finds the command classes under the `Cli/` directories of src/.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The class names.
	 */
	public static function commandClasses(): array {
		$classes = array();

		foreach ( PhpSource::files( 'src' ) as $file => $source ) {
			if ( 1 === preg_match( '~/Cli/[A-Za-z0-9]+Command\.php$~', $file ) ) {
				$classes = array_merge( $classes, PhpSource::declarations( $source ) );
			}
		}

		return $classes;
	}

	/**
	 * Compares declared addresses with registered ones.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $declared   The addresses the declarations name, with who names them.
	 * @param array<string, true>   $registered The addresses registered.
	 * @param string                $what       What an address is, for the messages.
	 * @return list<string> The messages.
	 */
	private static function compare( array $declared, array $registered, string $what ): array {
		$violations = array();

		foreach ( array_keys( array_diff_key( $registered, $declared ) ) as $address ) {
			$violations[] = ucfirst( $what ) . " {$address} resolves to no operation definition.";
		}

		foreach ( array_diff_key( $declared, $registered ) as $address => $owner ) {
			$violations[] = "{$owner} declares {$what} {$address}, which is not registered.";
		}

		return $violations;
	}
}
