<?php
/**
 * Lifecycle: activation, deactivation, the per-site installation and the reconciliation that keeps it current
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Authorization\CapabilityInstaller;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\LockProbe;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Secrets\SecretKeys;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the plugin on a site, keeps each site's installation current, and removes nothing.
 *
 * Owns one fact: what installing SEOCart on a site means, and when it happens. installSite() is
 * idempotent and runs per site, inside that site:
 *
 * 1. a site without a record gets its identity first: a new install id, the current address, the
 *    installation time and the host's lock mode, which is probed first so that no record ever
 *    exists without one. When the site's `migrations` table already holds applied migrations,
 *    the site was installed before and its identity is lost, so the rebuilt record also starts
 *    Safe Mode (Rebuilt) and the merchant is asked whether this is a copy;
 * 2. the capability installer grants what the declaration holds and the site has not settled;
 * 3. the migrator applies pending migrations, waiting briefly for the schema lock; a failure is
 *    recorded in the `migrations` table and reported, and the schema gate takes over. When data
 *    migrations remain after the time budget, the migration job is queued to finish them;
 * 4. the secrets get their first data key and canary, which later runs keep;
 * 5. the plugin's recurring jobs are scheduled, those that have no run waiting;
 * 6. the lock mode, probed again, and the plugin version are recorded last, so a run that died
 *    part-way is run again. The version is recorded only when it is newer than the recorded one:
 *    an older node of a rolling deployment that handles an activation must not record its own
 *    version over a newer one, or the newer node would install again on its next request.
 *
 * Jobs are queued and scheduled only once Action Scheduler has initialised, which it has not in
 * the request that activates the plugin, when no other plugin runs a copy of it: the admin tick
 * of the next admin request schedules the recurring jobs, and the next reconciliation queues the
 * migration job. A site a network has just created has no tables of the library's until its own
 * first request, so scheduling its jobs from the request that created it fails; that is reported,
 * the installation goes on, and the site's first admin tick or the library's cron repair
 * schedules them. Nothing here runs inside a transaction: the grant ledger, the data keys and the
 * job queue each refuse to.
 *
 * It runs on activation, and from reconcile() on every admin, command-line, cron and REST request of a
 * site whose record is missing or older than the code. That covers network activation, which
 * installs the current site only and never loops over the network: each other site installs
 * itself on its next such request, and a new site installs as soon as core has created it.
 * Two installations at once are safe: the schema lock serialises the migrator, the capability
 * installer only adds what is due, and the record is written by compare-and-swap.
 *
 * reconcile() otherwise costs one autoloaded read. When the schema gate reports that the code is
 * newer than the schema and no migration has failed, it makes one bounded attempt inline, and
 * queues the migration job when data migrations remain; the job is keyed by the code's newest
 * migration, so every request that sees the same gap queues it once. A failed migration is never
 * retried by page loads, which would re-run the failing statement on every one of them; the
 * notice's Retry link and the job's own retries retry it on purpose.
 *
 * A record of a newer shape than this version reads means a newer version installed the site, so
 * installSite() does nothing on it, whether activation or reconcile() started it: an older node
 * of a rolling deployment never installs over a newer one.
 *
 * Deactivation and uninstallation remove nothing: tables, options, roles and capabilities stay.
 * Deactivation cancels the plugin's own waiting jobs, and only those, because Action Scheduler
 * reschedules a recurring job whether or not anything handles it; activation schedules them
 * again. Deleting a site of a network cancels that site's jobs, and is the one explicit act that
 * drops the site's plugin tables.
 *
 * @since 0.1.0
 */
final class Lifecycle {

	/**
	 * The code reported when a migration failed during an installation run or an attempt.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MIGRATION_FAILED = 'kernel.migration_failed';

	/**
	 * The code reported when reconciling a site failed; the request goes on.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RECONCILE_FAILED = 'kernel.reconcile_failed';

	/**
	 * The code reported when the secrets could not get their first data key; the canary then enters Safe Mode.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SECRETS_NOT_INITIALIZED = 'kernel.secrets_not_initialized';

	/**
	 * The code reported when a job could not be queued or scheduled during an installation or an attempt.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const JOBS_NOT_SCHEDULED = 'kernel.jobs_not_scheduled';

	/**
	 * The code reported when the plugin's jobs could not be cancelled at deactivation or site deletion.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const JOBS_NOT_CANCELLED = 'kernel.jobs_not_cancelled';

	/**
	 * How long an installation run waits for another runner's schema lock, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const INSTALL_WAIT_SECONDS = 5;

	/**
	 * How long an installation run may spend on data migrations, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const INSTALL_BUDGET_SECONDS = 20;

	/**
	 * How long an attempt on an ordinary request may spend on data migrations, in seconds. It never waits for the lock.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ATTEMPT_BUDGET_SECONDS = 10;

	/**
	 * Builds the services an installation needs, when it needs them.
	 *
	 * @since 0.1.0
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * How long an attempt on an ordinary request may spend on data migrations, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $attemptBudgetSeconds;

	/**
	 * Creates the lifecycle. Builds nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Container $container            The kernel's container.
	 * @param callable  $report               Receives a machine code (string) and its context (array).
	 * @param int       $attemptBudgetSeconds Optional. How long an attempt on an ordinary request may
	 *                                        spend on data migrations; one batch always runs. Default
	 *                                        ATTEMPT_BUDGET_SECONDS.
	 */
	public function __construct( Container $container, callable $report, int $attemptBudgetSeconds = self::ATTEMPT_BUDGET_SECONDS ) {
		$this->container            = $container;
		$this->report               = $report;
		$this->attemptBudgetSeconds = max( 0, $attemptBudgetSeconds );
	}

	/**
	 * Installs the plugin on the current site. Runs on the activation hook.
	 *
	 * A network activation installs the current site only; see the class description.
	 *
	 * @since 0.1.0
	 */
	public function activate(): void {
		$this->installSite();
	}

	/**
	 * Runs on the deactivation hook: cancels the plugin's own waiting jobs, and removes nothing.
	 *
	 * No table, option, role or capability is touched: deactivating a store plugin must never cost
	 * the store its data. A network deactivation cancels the current site's jobs; a failure to
	 * cancel is reported and never stops the deactivation.
	 *
	 * @since 0.1.0
	 */
	public function deactivate(): void {
		$this->cancelJobs();
	}

	/**
	 * Installs the plugin on the current site, or brings its installation up to date. Idempotent.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the database fails in a way other than a failed migration, which
	 *                           is recorded and reported instead.
	 */
	public function installSite(): void {
		$bootOption = $this->container->get( BootOption::class );

		if ( $bootOption->read()->isNewerShape() ) {
			return;
		}

		if ( $bootOption->read()->isAbsent() ) {
			$identity = $this->identity( $this->container->get( MigrationsTableState::class )->schemaHead() );

			$bootOption->mutate( static fn( BootRecord $record ): BootRecord => $record->isAbsent() ? $identity : $record );
		}

		$this->container->get( CapabilityInstaller::class )->install();

		try {
			$this->queueIfIncomplete( $this->container->get( Migrator::class )->migrate( new MigrationRunOptions( self::INSTALL_WAIT_SECONDS, self::INSTALL_BUDGET_SECONDS ) ) );
		} catch ( MigrationFailed $failed ) {
			$this->reportMigrationFailure( $failed );
		}

		$this->initializeSecrets();
		$this->scheduleJobs( static fn( JobQueue $queue ): mixed => $queue->ensureRecurring() );

		$mode    = LockProbe::run( $this->container->get( Database::class ) );
		$version = SEOCART_VERSION;

		$bootOption->mutate(
			static function ( BootRecord $record ) use ( $mode, $version ): BootRecord {
				if ( $record->isAbsent() ) {
					return $record;
				}

				$record = $record->withLockMode( $mode );

				return self::codeIsNewerThan( $record->pluginVersion() ) ? $record->withPluginVersion( $version ) : $record;
			}
		);
	}

	/**
	 * Keeps the current site's installation current. Runs on admin, command-line, cron and REST requests.
	 *
	 * A database failure is reported and the request goes on; the next request tries again.
	 *
	 * @since 0.1.0
	 */
	public function reconcile(): void {
		try {
			$record = $this->container->get( BootOption::class )->read();

			if ( $record->isAbsent() || self::codeIsNewerThan( $record->pluginVersion() ) ) {
				$this->installSite();

				return;
			}

			$gate = $this->container->get( SchemaGate::class )->state();

			if ( GateState::CodeNewer === $gate ) {
				$this->attemptMigration( false );
			} elseif ( GateState::Ready === $gate && $record->schemaHead() !== $this->container->get( Migrator::class )->codeHead() ) {
				// Migrations that let the store trade are outstanding. An attempt that stopped at its
				// budget queued the job itself, unless Action Scheduler had not started yet, as in the
				// request that activated the plugin. Both heads are known without a query.
				$this->queueMigrationJob();
			}
		} catch ( \RuntimeException $failure ) {
			( $this->report )(
				self::RECONCILE_FAILED,
				array(
					'error' => $failure instanceof CodedException ? (string) $failure->errorCode()->value : get_class( $failure ),
				)
			);
		}
	}

	/**
	 * Tries the pending migrations again, also after a failure. The notice's Retry link.
	 *
	 * @since 0.1.0
	 */
	public function retryMigration(): void {
		$this->attemptMigration( true );
	}

	/**
	 * Installs the plugin on a site a network has just created, when the plugin is network-active.
	 *
	 * Hooked to `wp_initialize_site` after core's own initializer, which creates the site's tables and options.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $site The new site: a WP_Site from core.
	 */
	public function initializeSite( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::isNetworkActive() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			$this->installSite();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Cancels the plugin's jobs on a site a network is deleting, when the plugin is network-active.
	 *
	 * Hooked to `wp_uninitialize_site` before core's own, which drops the site's tables.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $site The site being deleted: a WP_Site from core.
	 */
	public function uninitializeSite( $site ): void {
		if ( ! $site instanceof \WP_Site || ! self::isNetworkActive() ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			$this->cancelJobs();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Filters `wpmu_drop_tables`: adds the site's plugin tables to what core drops with a deleted site.
	 *
	 * The tables are the ones the data registry lists.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $tables The table names core will drop: string[] from core.
	 * @param mixed $siteId The id of the site being deleted: an int from core.
	 * @return mixed The table names, the site's plugin tables among them.
	 */
	public function dropTables( $tables, $siteId ) {
		if ( ! is_array( $tables ) || ! is_numeric( $siteId ) ) {
			return $tables;
		}

		$switched = get_current_blog_id() !== (int) $siteId;

		if ( $switched ) {
			switch_to_blog( (int) $siteId );
		}

		try {
			$db = $this->container->get( Database::class );

			foreach ( $this->container->get( DataRegistry::class )->tables() as $definition ) {
				$tables[] = $db->table( $definition->name() );
			}
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}

		return array_values( array_unique( $tables ) );
	}

	/**
	 * Makes one bounded attempt at the pending migrations.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $evenAfterFailure Whether to try when a migration has failed before.
	 */
	private function attemptMigration( bool $evenAfterFailure ): void {
		$migrator = $this->container->get( Migrator::class );

		if ( ! $evenAfterFailure && null !== $migrator->status()->failed() ) {
			return;
		}

		try {
			// A runner holding the lock is left to finish: the attempt does not wait and reports "blocked".
			$this->queueIfIncomplete( $migrator->migrate( new MigrationRunOptions( 0, $this->attemptBudgetSeconds ) ) );
		} catch ( MigrationFailed $failed ) {
			$this->reportMigrationFailure( $failed );
		}
	}

	/**
	 * Queues the migration job when a run stopped at its time budget with data migrations left.
	 *
	 * The job is keyed by the code's newest migration, so it is queued once however many requests
	 * see the same gap. Before Action Scheduler has initialised nothing is queued; the next
	 * reconciliation queues it.
	 *
	 * @since 0.1.0
	 *
	 * @param MigrationReport $report The run's outcome.
	 */
	private function queueIfIncomplete( MigrationReport $report ): void {
		if ( MigrationReport::INCOMPLETE === $report->outcome() ) {
			$this->queueMigrationJob();
		}
	}

	/**
	 * Queues the migration job for the code's newest migration. Keyed, so it is queued once however many requests ask.
	 *
	 * @since 0.1.0
	 */
	private function queueMigrationJob(): void {
		$target = $this->container->get( Migrator::class )->codeHead();

		$this->scheduleJobs( static fn( JobQueue $queue ): mixed => $queue->enqueue( MigrationAttempt::job( $target ) ) );
	}

	/**
	 * Queues or schedules jobs once Action Scheduler has initialised. A failure is reported, never thrown.
	 *
	 * The library's tables may not exist yet on this site: a site a network has just created gets
	 * them on its own first request. What was not scheduled here is scheduled then.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $work Queues or schedules through the job queue.
	 *
	 * @phpstan-param callable(JobQueue): mixed $work
	 */
	private function scheduleJobs( callable $work ): void {
		if ( ! self::jobsReady() ) {
			return;
		}

		try {
			$work( $this->container->get( JobQueue::class ) );
		} catch ( \RuntimeException $failure ) {
			( $this->report )(
				self::JOBS_NOT_SCHEDULED,
				array(
					'error' => $failure instanceof CodedException ? (string) $failure->errorCode()->value : get_class( $failure ),
				)
			);
		}
	}

	/**
	 * Gives the secrets their first data key and canary, when they have none. Later runs change nothing.
	 *
	 * A refusal, such as damaged data keys that must be restored rather than replaced, is reported,
	 * and the installation goes on: the secrets canary then enters Safe Mode and says why.
	 *
	 * @since 0.1.0
	 */
	private function initializeSecrets(): void {
		try {
			$this->container->get( SecretKeys::class )->initialize();
		} catch ( CodedException $refused ) {
			( $this->report )(
				self::SECRETS_NOT_INITIALIZED,
				array(
					'error' => (string) $refused->errorCode()->value,
				)
			);
		}
	}

	/**
	 * Cancels the current site's waiting jobs of the plugin's own group. Never throws.
	 *
	 * @since 0.1.0
	 */
	private function cancelJobs(): void {
		if ( ! self::jobsReady() ) {
			return;
		}

		try {
			$this->container->get( JobQueue::class )->cancel();
		} catch ( \Exception $failure ) {
			( $this->report )(
				self::JOBS_NOT_CANCELLED,
				array(
					'error' => $failure instanceof CodedException ? (string) $failure->errorCode()->value : get_class( $failure ),
				)
			);
		}
	}

	/**
	 * Tells whether jobs can be queued, scheduled and cancelled in this request: Action Scheduler has initialised.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True once the `action_scheduler_init` action has run.
	 */
	private static function jobsReady(): bool {
		return 0 !== did_action( 'action_scheduler_init' );
	}

	/**
	 * Builds the identity of a site that has no record.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $tableHead The newest applied migration in the site's table, or null for a fresh site.
	 * @return BootRecord The record to create.
	 */
	private function identity( ?string $tableHead ): BootRecord {
		$now    = BootRecord::formatTime( $this->container->get( Clock::class )->now() );
		$record = BootRecord::absent()
			->withInstallUuid( $this->container->get( IdGenerator::class )->generate() )
			->withHomeUrl( home_url() )
			->withLockMode( LockProbe::run( $this->container->get( Database::class ) ) )
			->withInstalledAt( $now );

		if ( null === $tableHead ) {
			return $record;
		}

		return $record->withSchemaHead( $tableHead )->withSafeMode( SafeModeStatus::Rebuilt, $now );
	}

	/**
	 * Reports a failed migration. Its row already records the failure.
	 *
	 * @since 0.1.0
	 *
	 * @param MigrationFailed $failed The failure.
	 */
	private function reportMigrationFailure( MigrationFailed $failed ): void {
		( $this->report )(
			self::MIGRATION_FAILED,
			array(
				'migration_id' => $failed->migrationId(),
				'error_code'   => $failed->recordedCode(),
			)
		);
	}

	/**
	 * Tells whether the running code is newer than the version that last installed the site.
	 *
	 * An older version never reinstalls over a newer one: during a rolling deployment both run
	 * against one database, and the older one must not keep taking the record back.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $recorded The recorded version, or null when no run completed.
	 * @return bool True when an installation run is due.
	 */
	private static function codeIsNewerThan( ?string $recorded ): bool {
		return null === $recorded || version_compare( SEOCART_VERSION, $recorded, '>' );
	}

	/**
	 * Tells whether the plugin is active on every site of the network.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when it is network-active.
	 */
	private static function isNetworkActive(): bool {
		if ( ! is_multisite() ) {
			return false;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) && isset( $active[ plugin_basename( SEOCART_PLUGIN_FILE ) ] );
	}
}
