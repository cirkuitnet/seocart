<?php
/**
 * Doctor: the list of checks `wp seocart doctor` runs, and running them safely
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Holds every doctor check and runs them for the current site.
 *
 * Owns one fact: which checks doctor runs. checks() is the one list of the checks a healthy
 * store passes: the platform's own (the schema, the migrations, the locks, the outbox and the
 * runner of background jobs), then the checks the modules contribute, which the kernel passes
 * in; residueChecks() is the list for a site whose store data was deleted. The command prints
 * them and Site Health can show the same list; a test holds both lists, as the kernel builds
 * them, equal to the Check classes that exist.
 *
 * Every check is read-only. run() never throws: a check that cannot run, because a table is
 * missing or the database refuses, counts as failed with its error code, never its values.
 *
 * @since 0.1.0
 */
final class Doctor {

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * What the plugin owns.
	 *
	 * @since 0.1.0
	 *
	 * @var DataRegistry
	 */
	private DataRegistry $registry;

	/**
	 * The migrator, built from the registry's chain.
	 *
	 * @since 0.1.0
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	private Outbox $outbox;

	/**
	 * The queue of background jobs, which reports on them.
	 *
	 * @since 0.1.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $jobs;

	/**
	 * The checks the modules contribute, run after the platform's.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Check>
	 */
	private array $moduleChecks;

	/**
	 * Creates the doctor. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database     $db              The connection.
	 * @param DataRegistry $registry        What the plugin owns.
	 * @param Migrator     $migrator        The migrator, built from the registry's chain.
	 * @param Outbox       $outbox          The outbox rows.
	 * @param JobQueue     $jobs            The queue of background jobs.
	 * @param Check        ...$moduleChecks The checks the modules contribute, in the order they run.
	 */
	public function __construct( Database $db, DataRegistry $registry, Migrator $migrator, Outbox $outbox, JobQueue $jobs, Check ...$moduleChecks ) {
		$this->db           = $db;
		$this->registry     = $registry;
		$this->migrator     = $migrator;
		$this->outbox       = $outbox;
		$this->jobs         = $jobs;
		$this->moduleChecks = array_values( $moduleChecks );
	}

	/**
	 * Returns the checks a healthy store passes, in the order they run.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Check> The checks.
	 */
	public function checks(): array {
		$platform = array(
			new SchemaCheck( $this->db, $this->registry ),
			new MigrationsCheck( $this->migrator, $this->db, $this->registry ),
			new LocksCheck( $this->db ),
			new OutboxCheck( $this->outbox ),
			new RunnerCheck( $this->jobs ),
		);

		return array_merge( $platform, $this->moduleChecks );
	}

	/**
	 * Returns the checks of a site whose store data was deleted.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Check> The checks.
	 */
	public function residueChecks(): array {
		return array(
			new ResidueCheck( $this->db, $this->registry ),
		);
	}

	/**
	 * Runs one list of checks. Never throws.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $residue Optional. True to run residueChecks() instead of checks(). Default false.
	 * @return list<CheckResult> One result per check, in order.
	 */
	public function run( bool $residue = false ): array {
		return $this->runList( $residue ? $this->residueChecks() : $this->checks() );
	}

	/**
	 * Runs a given list of checks, in order. Never throws.
	 *
	 * `--repair` calls this twice on the same list from checks() — once for the first pass, once
	 * for the second, after repair() has run on what the first pass failed — so both passes see
	 * exactly the same checks, in exactly the same order.
	 *
	 * @since 0.1.0
	 *
	 * @param Check[] $checks The checks to run.
	 * @return list<CheckResult> One result per check, in order.
	 *
	 * @phpstan-param list<Check> $checks
	 */
	public function runList( array $checks ): array {
		$results = array();

		foreach ( $checks as $check ) {
			try {
				$results[] = $check->run();
			} catch ( \Throwable $failure ) {
				$results[] = CheckResult::fail( $check->name(), 'The check could not run: ' . self::describe( $failure ) );
			}
		}

		return $results;
	}

	/**
	 * Describes why a check could not run, without any value the failure carries.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $failure The failure.
	 * @return string For a coded failure its code and its message, which renders only its declared
	 *                placeholders; otherwise the class.
	 */
	private static function describe( \Throwable $failure ): string {
		if ( $failure instanceof CodedException ) {
			return (string) $failure->errorCode()->value . ': ' . ErrorDefinition::of( $failure->errorCode() )->render( $failure->context() );
		}

		return get_class( $failure ) . '.';
	}
}
