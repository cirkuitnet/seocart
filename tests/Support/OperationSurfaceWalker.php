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
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Cli\DoctorCommand;
use SEOCart\Platform\Database\Cli\MigrateCommand;
use SEOCart\Platform\Events\Cli\OutboxCommand;
use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Kernel\Cli\SafeModeCommand;
use SEOCart\Platform\Secrets\Cli\SecretsCommand;
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
 * MAINTENANCE_COMMANDS, each with its class and the reason, checked against the command classes
 * under the `Cli/` directories of src/ in both directions (maintenanceViolations()): an unlisted
 * class fails, and so does a listed class that is gone. They are never required to be registered,
 * and a registered one is never taken for an unresolved operation command (commandViolations()).
 *
 * A route at an operation's address must also be guarded as that operation declares: by a
 * PermissionCallback for the definition's capability, checked on the definition's resource field
 * when it names one. RoutePermissionWalker only checks that a callback is of the plugin's type,
 * which a callback for another capability also is; the comparison with the definition is made
 * here, against the definition itself rather than the permission factory, so a factory that
 * guards a route wrongly is caught as well.
 *
 * The REST walk reuses RoutePermissionWalker's decision of which routes are the plugin's, so the
 * two walks cannot disagree about that.
 *
 * @since 0.1.0
 */
final class OperationSurfaceWalker {

	/**
	 * The maintenance list: the commands that have no operation definition by design.
	 *
	 * Keyed by the command as WP_CLI::add_command() names it, each with the class that implements it
	 * and the reason it has no definition. A module that adds a maintenance command adds exactly one
	 * entry here, in the same change as its class. The contract test fails until the class is
	 * listed, and fails again if the class goes away while its entry stays.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{class: string, reason: string}>
	 */
	public const MAINTENANCE_COMMANDS = array(
		'seocart migrate'   => array(
			'class'  => MigrateCommand::class,
			'reason' => 'Applies the pending schema and data migrations: a maintenance tool for operators, with no REST route or ability twin.',
		),
		'seocart outbox'    => array(
			'class'  => OutboxCommand::class,
			'reason' => 'Drains, reports on and prunes the event outbox: a maintenance tool for operators, with no REST route or ability twin.',
		),
		'seocart safe-mode' => array(
			'class'  => SafeModeCommand::class,
			'reason' => 'Switches Safe Mode on or off from a shell or a deployment script: an operator\'s control over the site\'s environment state, with no REST route or ability twin.',
		),
		'seocart secrets'   => array(
			'class'  => SecretsCommand::class,
			'reason' => 'Reports on the data keys (status), creates a new one (rotate) and re-seals the stored secrets with it (rekey): key management for operators on the server, which no REST route or ability may offer.',
		),
		'seocart doctor'    => array(
			'class'  => DoctorCommand::class,
			'reason' => 'Checks the schema, migrations, locks, outbox and residue read-only and exits non-zero on a problem: a diagnostic for operators, with no REST route or ability twin.',
		),
		'seocart jobs'      => array(
			'class'  => JobsCommand::class,
			'reason' => 'Runs the due background jobs for a system cron and reports on them: a maintenance tool for operators, with no REST route or ability twin.',
		),
	);

	/**
	 * Checks the plugin's REST routes against the registry.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server    $server   A server on which `rest_api_init` has run.
	 * @param OperationRegistry $registry The operations that should be registered.
	 * @return list<string> One message per route without an operation, per operation route not
	 *                      registered, and per endpoint at an operation's address guarded otherwise
	 *                      than the operation declares.
	 */
	public static function restViolations( WP_REST_Server $server, OperationRegistry $registry ): array {
		$declared    = array();
		$definitions = array();

		foreach ( $registry->all() as $definition ) {
			$rest = $definition->rest();

			if ( null !== $rest ) {
				$address                 = $definition->httpMethod() . ' ' . RestAdapter::serverRoute( $rest );
				$declared[ $address ]    = $definition->id();
				$definitions[ $address ] = $definition;
			}
		}

		$registered = array();
		$guards     = array();
		$routes     = $server->get_routes();

		foreach ( ( new RoutePermissionWalker() )->walk( $server )['plugin_routes'] as $route ) {
			foreach ( $routes[ $route ] as $handler ) {
				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
					$address                = $method . ' ' . $route;
					$registered[ $address ] = true;

					if ( isset( $definitions[ $address ] ) ) {
						$guards = array_merge( $guards, self::guardViolations( $address, $handler['permission_callback'] ?? null, $definitions[ $address ] ) );
					}
				}
			}
		}

		return array_merge( self::compare( $declared, $registered, 'the REST route' ), $guards );
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
	 * Checks the operation commands: the registered ones against the registry, both ways.
	 *
	 * A registered command that is a listed maintenance command is not an operation command and is
	 * left out; a maintenance command is never required to be registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]                                            $commands    Every command registered, as WP_CLI::add_command() names it.
	 * @param OperationRegistry                                   $registry    The operations that should be registered.
	 * @param array<string, array{class: string, reason: string}> $maintenance The maintenance list.
	 * @return list<string> One message per registered command without an operation and per operation command not registered.
	 *
	 * @phpstan-param list<string> $commands
	 */
	public static function commandViolations( array $commands, OperationRegistry $registry, array $maintenance ): array {
		$declared = array();

		foreach ( $registry->all() as $definition ) {
			if ( null !== $definition->cli() ) {
				$declared[ $definition->cli()->command() ] = $definition->id();
			}
		}

		$registered = array();

		foreach ( $commands as $command ) {
			if ( str_starts_with( $command . ' ', CliBinding::ROOT . ' ' ) && ! isset( $maintenance[ $command ] ) ) {
				$registered[ $command ] = true;
			}
		}

		return self::compare( $declared, $registered, 'the command' );
	}

	/**
	 * Checks the maintenance list against the command classes found, both ways.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array{class: string, reason: string}> $maintenance The maintenance list.
	 * @param string[]                                            $classes     The command classes found by commandClasses().
	 * @return list<string> One message per unlisted class and per listed class that is gone.
	 *
	 * @phpstan-param list<string> $classes
	 */
	public static function maintenanceViolations( array $maintenance, array $classes ): array {
		$violations = array();
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
	 * Finds the command classes under the `Cli/` directories of a directory of the repository.
	 *
	 * @since 0.1.0
	 *
	 * @param string $directory The directory, relative to the repository root, such as `src`.
	 * @return list<string> The class names.
	 */
	public static function commandClasses( string $directory ): array {
		$classes = array();

		foreach ( PhpSource::files( $directory ) as $file => $source ) {
			if ( 1 === preg_match( '~/Cli/[A-Za-z0-9]+Command\.php$~', $file ) ) {
				$classes = array_merge( $classes, PhpSource::declarations( $source ) );
			}
		}

		return $classes;
	}

	/**
	 * Checks that an endpoint at an operation's address is guarded as the operation declares.
	 *
	 * The capability and the resource parameter must both be the definition's: the same capability
	 * checked on another parameter, or without the resource, is another check. A policy added with
	 * PermissionCallback::withPolicy() can only narrow what the callback allows, so it is not compared.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $address    The endpoint's method and route.
	 * @param mixed               $callback   The endpoint's `permission_callback`.
	 * @param OperationDefinition $definition The operation declared at that address.
	 * @return list<string> One message when the endpoint is guarded otherwise, or none.
	 */
	private static function guardViolations( string $address, $callback, OperationDefinition $definition ): array {
		if ( $callback instanceof PermissionCallback && $definition->capability() === $callback->capability() && $definition->resourceField() === $callback->resourceParameter() ) {
			return array();
		}

		$guard = $callback instanceof PermissionCallback ? self::describeGuard( $callback->capability(), $callback->resourceParameter() ) : 'a callback that is not a PermissionCallback';

		return array( "The REST route {$address} is guarded by {$guard}, but {$definition->id()} declares " . self::describeGuard( $definition->capability(), $definition->resourceField() ) . '.' );
	}

	/**
	 * Names a permission check for a message.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $capability The capability, or null for the public-read marker.
	 * @param string|null $parameter  The request parameter naming the resource, or null for none.
	 * @return string For example `seocart_manage_settings` or `seocart_edit_order on item_id`.
	 */
	private static function describeGuard( ?string $capability, ?string $parameter ): string {
		if ( null === $capability ) {
			return 'the public-read marker';
		}

		return null === $parameter ? $capability : "{$capability} on {$parameter}";
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
