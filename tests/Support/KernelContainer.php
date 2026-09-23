<?php
/**
 * KernelContainer: the production container, with the test's connection and a recording reporter
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Lifecycle;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Clock;

/**
 * Builds a container on which the real module bindings run, for the kernel's integration tests.
 *
 * Owns one fact: what a kernel test replaces in the production wiring, and nothing more. The
 * services that report take the test's reporter, so a report is recorded instead of reaching the
 * PHP error log, and the connection is the test's own. Everything else is bound by
 * Modules::register(), exactly as in production, the grant ledger in its settings document
 * included.
 *
 * @since 0.1.0
 */
final class KernelContainer {

	/**
	 * Builds the container.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db        The connection every service uses.
	 * @param callable $report    Receives every report: a code (string) and its context (array).
	 * @param array    $overrides Optional. Further replacements, which win. Default none.
	 * @return Container The container, with every module registered.
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 * @phpstan-param array<string, callable(Container): object>   $overrides
	 */
	public static function build( Database $db, callable $report, array $overrides = array() ): Container {
		$defaults = array(
			Database::class   => static fn(): Database => $db,
			BootOption::class => static fn( Container $c ): BootOption => new BootOption( $c->get( Database::class ), $report ),
			Lifecycle::class  => static fn( Container $c ): Lifecycle => new Lifecycle( $c, $report ),
			Migrator::class   => static fn( Container $c ): Migrator => self::migrator( $c, $c->get( DataRegistry::class )->migrations(), $report ),
		);

		$container = new Container( $overrides + $defaults );

		Modules::register( $container );

		return $container;
	}

	/**
	 * Builds a migrator over a chain of the test's choosing, wired as production wires it.
	 *
	 * @since 0.1.0
	 *
	 * @param Container   $c      The container the migrator's collaborators come from.
	 * @param Migration[] $chain  The migrations, the bootstrap migration first.
	 * @param callable    $report Receives every report: a code (string) and its context (array).
	 * @return Migrator The migrator.
	 *
	 * @phpstan-param list<Migration>                             $chain
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public static function migrator( Container $c, array $chain, callable $report ): Migrator {
		return new Migrator( $c->get( Database::class ), $c->get( LockService::class ), $c->get( DatabaseState::class ), $chain, $c->get( Clock::class ), $report );
	}
}
