<?php
/**
 * MigrationAttempt: the job that applies pending migrations a little at a time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs\Handlers;

use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the migrator for a bounded time, and runs again until nothing is pending.
 *
 * Owns one fact: how a migration is attempted in the background. When the schema is behind
 * the code, the kernel migrates inline for a moment and, if data migrations remain, queues
 * job(): one job per schema it aims for, so every request that sees the gap queues it once.
 * Each run calls Migrator::migrate() with BUDGET_SECONDS of data batches and no wait for the
 * schema lock:
 *
 * - applied or up to date: done;
 * - incomplete, the budget spent inside a data migration: it runs again at once, as a new job,
 *   and resumes from the migration's saved cursor;
 * - blocked, another runner holds the schema lock: it runs again after BLOCKED_DELAY_SECONDS,
 *   when that runner has finished or its lock has expired;
 * - a migration failed: MigrationFailed propagates, and the job is retried after the retry
 *   delay, MAX_ATTEMPTS times in all, then recorded as failed. A failed migration is never
 *   retried by page loads; this backoff and an operator's retry are the only ways.
 *
 * The schema gate, not this job, keeps a half-migrated store safe: nothing depends on when it runs.
 *
 * @since 0.1.0
 */
final class MigrationAttempt implements JobHandler {

	/**
	 * How long one run may keep working through data batches, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * After how long a run that found the schema lock held runs again, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BLOCKED_DELAY_SECONDS = 60;

	/**
	 * How many times a run that fails is attempted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * The migrator.
	 *
	 * @since 0.1.0
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * How long one run may keep working through data batches, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $budgetSeconds;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param Migrator $migrator      The migrator.
	 * @param int      $budgetSeconds Optional. How long one run may work through data batches; at
	 *                                least one batch always runs. Default BUDGET_SECONDS.
	 */
	public function __construct( Migrator $migrator, int $budgetSeconds = self::BUDGET_SECONDS ) {
		$this->migrator      = $migrator;
		$this->budgetSeconds = max( 0, $budgetSeconds );
	}

	/**
	 * Returns the job that migrates the schema towards a target.
	 *
	 * Its unique key is derived from the target, so the job is queued once per schema the code
	 * aims for: a second request that sees the same gap adds nothing, and a later release, with
	 * a new target, queues a new job.
	 *
	 * @since 0.1.0
	 *
	 * @param string $target What the schema should reach, for example the id of the code's newest migration.
	 * @return Job The job.
	 */
	public static function job( string $target ): Job {
		return new Job( self::name(), array(), self::name() . ':' . substr( hash( 'sha256', $target ), 0, 16 ) );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `schema.migrate`.
	 */
	public static function name(): string {
		return 'schema.migrate';
	}

	/**
	 * Returns how often the handler runs on its own.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Null: it runs when queued.
	 */
	public static function recurrence(): ?int {
		return null;
	}

	/**
	 * Returns how many times a failing run is attempted.
	 *
	 * @since 0.1.0
	 *
	 * @return int MAX_ATTEMPTS.
	 */
	public static function maxAttempts(): int {
		return self::MAX_ATTEMPTS;
	}

	/**
	 * Migrates for up to the time budget.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null when nothing is left to do; 0 to continue at once; BLOCKED_DELAY_SECONDS
	 *                  when another runner holds the schema lock.
	 */
	public function handle( array $payload ): ?int {
		$report = $this->migrator->migrate( new MigrationRunOptions( 0, $this->budgetSeconds ) );

		switch ( $report->outcome() ) {
			case MigrationReport::INCOMPLETE:
				return 0;

			case MigrationReport::BLOCKED:
				return self::BLOCKED_DELAY_SECONDS;

			default:
				return null;
		}
	}
}
