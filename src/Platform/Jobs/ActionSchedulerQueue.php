<?php
/**
 * ActionSchedulerQueue: the job queue, on Action Scheduler
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Logging\CorrelationId;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These messages name a handler for the developer and the log, never HTML.

/**
 * Stores the plugin's jobs as Action Scheduler actions, finds, cancels and cleans them up, and reports on them.
 *
 * Owns one fact: how SEOCart's jobs live in Action Scheduler. Every job is an action of the
 * hook JobRunner::HOOK in the group JobQueue::GROUP, whose one argument is a JobEnvelope. The
 * library may be another plugin's copy, whichever registered the highest version, so the
 * adapter keeps to rules that hold for every copy it supports:
 *
 * - It never sets a queue-wide setting. The retention filters (`action_scheduler_retention_period`
 *   and its failed-action twin) govern every plugin's actions; cleanup() deletes the plugin's
 *   own finished actions instead, by age, one bounded batch at a time.
 * - It touches only its own actions: every lookup, cancellation and deletion is limited to its
 *   hook and its group.
 * - It does not use the library's own `$unique` flag, whose meaning changed between versions
 *   (before 4.0 it ignored the arguments, so two jobs of one handler with different keys would
 *   have collapsed into one). A keyed job is looked up by the start of its stored arguments,
 *   under a lock on its key, so two requests queueing it at once store it once.
 * - It never drops the library's tables.
 *
 * Writes go through the library's API, so its caches, hooks and logs stay right, with one
 * exception: the library records no time for a cancellation, so the queue stamps it on the
 * rows it cancelled, in their last-attempt columns, and cleanup ages them from it; the
 * runner's check-in ignores cancelled rows. Reads that the API cannot answer in one query
 * (the counts, the key lookup) read its tables directly: the
 * actions, groups and logs tables, whose columns are the same in every supported version.
 * Those tables are where the library keeps its actions unless another plugin has given it a
 * store of its own; customStore() says when one has.
 *
 * @since 0.1.0
 */
final class ActionSchedulerQueue implements JobQueue {

	/**
	 * Action Scheduler's status of a waiting action.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PENDING = 'pending';

	/**
	 * Action Scheduler's status of a running action.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RUNNING = 'in-progress';

	/**
	 * Action Scheduler's status of a completed action.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const COMPLETE = 'complete';

	/**
	 * Action Scheduler's status of a failed action.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FAILED = 'failed';

	/**
	 * Action Scheduler's status of a cancelled action.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CANCELED = 'canceled';

	/**
	 * How long the lock on one keyed job lasts without renewal, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const KEY_LOCK_TTL_SECONDS = 30;

	/**
	 * How long a request waits for another that is queueing the same keyed job, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const KEY_LOCK_WAIT_SECONDS = 5;

	/**
	 * The library's table store: it keeps every action in the library's tables, under the ids those tables give them.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TABLE_STORE = 'ActionScheduler_DBStore';

	/**
	 * The library's hybrid store, which it uses while it moves old actions out of the posts table.
	 *
	 * It saves every new action in its destination store, and keeps their ids above every id left
	 * in the posts table.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const HYBRID_STORE = 'ActionScheduler_HybridStore';

	/**
	 * The destinations that make a hybrid store keep its new actions in the library's tables.
	 *
	 * The table store's subclass the library builds as the destination by default, which adds
	 * only the saving of migrated actions, and the table store itself.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const HYBRID_DESTINATIONS = array( 'ActionScheduler_DBStoreMigrator', 'ActionScheduler_DBStore' );

	/**
	 * How many cancelled rows one statement stamps with their cancellation time.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const STAMP_BATCH = 500;

	/**
	 * A last attempt before this date means the action never ran: the library stores a zero date.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NEVER_RAN = '2000-01-01 00:00:00';

	/**
	 * What the one query of runnerWork() returns for "a job is due": never a stored argument list.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const DUE_MARKER = '';

	/**
	 * The lock held while recurring jobs are scheduled.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RECURRING_LOCK = 'jobs_recurring';

	/**
	 * How many recent failed jobs report() reads to rank the failing handlers.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const FAILING_SAMPLE = 200;

	/**
	 * How many failing handlers report() names.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const FAILING_TOP = 5;

	/**
	 * How many characters of a failure message report() keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ERROR_LENGTH = 200;

	/**
	 * The connection: asked whether a transaction is open, and used to read the library's tables.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Takes the lock on a keyed job, and on recurring scheduling.
	 *
	 * @since 0.1.0
	 *
	 * @var LockService
	 */
	private LockService $locks;

	/**
	 * The registered handlers.
	 *
	 * @since 0.1.0
	 *
	 * @var JobHandlers
	 */
	private JobHandlers $handlers;

	/**
	 * The correlation id each queued job carries.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * How long a completed or cancelled job is kept after its last run, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $finishedRetentionSeconds;

	/**
	 * How long a failed job is kept after it failed, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $failedRetentionSeconds;

	/**
	 * Creates the queue, with the job history's retention periods from the retention catalog. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database      $db          The connection.
	 * @param LockService   $locks       Takes the locks.
	 * @param JobHandlers   $handlers    The registered handlers.
	 * @param CorrelationId $correlation The correlation id each queued job carries.
	 * @param callable      $report      Receives a report code (string) and its context (array).
	 */
	public function __construct( Database $db, LockService $locks, JobHandlers $handlers, CorrelationId $correlation, callable $report ) {
		$periods = ( new RetentionCatalog() )->defaults( self::RETENTION );

		$this->db                       = $db;
		$this->locks                    = $locks;
		$this->handlers                 = $handlers;
		$this->correlation              = $correlation;
		$this->report                   = $report;
		$this->finishedRetentionSeconds = self::seconds( $periods['finished'] );
		$this->failedRetentionSeconds   = self::seconds( $periods['failed'] );
	}

	/**
	 * Queues a job to run as soon as a runner takes it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open, the handler is not registered, or Action
	 *                         Scheduler is not initialised yet.
	 *
	 * @param Job $job The job.
	 * @return bool True when queued; false when a job with the same handler and key already
	 *              waits, runs or completed, or another request held its lock for the whole wait.
	 */
	public function enqueue( Job $job ): bool {
		return $this->queue( $job, null );
	}

	/**
	 * Queues a job to run once a time has come.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException As enqueue().
	 *
	 * @param Job                $job   The job.
	 * @param \DateTimeImmutable $runAt The earliest time it may run.
	 * @return bool As enqueue().
	 */
	public function schedule( Job $job, \DateTimeImmutable $runAt ): bool {
		return $this->queue( $job, $runAt->getTimestamp() );
	}

	/**
	 * Stores the next run of a job the runner is running: its retry, or its continuation.
	 *
	 * The unique key is not looked up: the run that asks for it holds the key while it runs, and
	 * the new run takes over from it.
	 *
	 * @since 0.1.0
	 *
	 * @internal Only JobRunner calls it.
	 *
	 * @throws \LogicException When a transaction is open or Action Scheduler is not initialised.
	 *
	 * @param JobEnvelope $envelope     The run to store.
	 * @param int         $delaySeconds After how long it may run; 0 runs it as soon as a runner takes it.
	 */
	public function requeue( JobEnvelope $envelope, int $delaySeconds ): void {
		$this->assertReady();
		$this->store( $envelope, $delaySeconds > 0 ? time() + $delaySeconds : null );
	}

	/**
	 * Cancels the plugin's own waiting jobs: all of them, one handler's, or one keyed job.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a unique key is given without its handler.
	 * @throws \LogicException           When a transaction is open or Action Scheduler is not initialised.
	 *
	 * @param string|null $handler   Optional. Only this handler's jobs. Default null, every job.
	 * @param string|null $uniqueKey Optional. Only the job with this key. Default null.
	 * @return int How many jobs were cancelled.
	 */
	public function cancel( ?string $handler = null, ?string $uniqueKey = null ): int {
		$prefix = self::cancelPrefix( $handler, $uniqueKey );

		$this->assertReady();

		$ids = $this->ownIds( array( self::PENDING ), $prefix );

		$this->cancelOwn( $ids );

		return count( $ids );
	}

	/**
	 * Deletes the plugin's own finished jobs past retention, in one bounded batch.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open or Action Scheduler is not initialised.
	 *
	 * @param int $limit At most this many finished jobs, and this many failed ones, are deleted.
	 * @return int How many jobs were deleted.
	 */
	public function cleanup( int $limit ): int {
		$this->assertReady();

		$ids = array_merge(
			$this->ownIds( array( self::COMPLETE, self::CANCELED ), null, $this->finishedRetentionSeconds, max( 1, $limit ) ),
			$this->ownIds( array( self::FAILED ), null, $this->failedRetentionSeconds, max( 1, $limit ) )
		);

		$deleted = 0;

		foreach ( $ids as $id ) {
			try {
				\ActionScheduler::store()->delete_action( $id );
			} catch ( \InvalidArgumentException $gone ) {
				// Another process deleted it first.
				continue;
			}

			++$deleted;
		}

		return $deleted;
	}

	/**
	 * Schedules every recurring handler that has no run waiting.
	 *
	 * One query when every recurring handler has its run. The lock is taken only to schedule a
	 * missing one, whose lookup is repeated under it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open or Action Scheduler is not initialised.
	 *
	 * @return list<string> The handlers that were scheduled now. Empty when another request was
	 *                      scheduling them at the same moment.
	 */
	public function ensureRecurring(): array {
		$this->assertReady();

		$missing = $this->runnerWork()['missing'];

		if ( array() === $missing ) {
			return array();
		}

		try {
			return $this->locks->withLock(
				self::RECURRING_LOCK,
				self::KEY_LOCK_TTL_SECONDS,
				self::KEY_LOCK_WAIT_SECONDS,
				function () use ( $missing ): array {
					$scheduled = array();

					foreach ( $missing as $name => $every ) {
						if ( $this->scheduleRecurring( $name, $every ) ) {
							$scheduled[] = $name;
						}
					}

					return $scheduled;
				}
			);
		} catch ( LockNotAcquired $held ) {
			return array();
		}
	}

	/**
	 * Tells whether the runner has work: a job is due, or a recurring handler has no run waiting. One query.
	 *
	 * The admin tick asks it on every admin request, so a recurring schedule lost any way (a
	 * fatal error in its run, a cancellation) comes back at the next tick.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a waiting job's time has come, or ensureRecurring() has a run to schedule.
	 */
	public function hasWork(): bool {
		$work = $this->runnerWork();

		return $work['due'] || array() !== $work['missing'];
	}

	/**
	 * Names the store Action Scheduler keeps its actions in, when it is not one the plugin's own triggers can run jobs from.
	 *
	 * The plugin's runner claims its jobs in the library's tables, then hands each id to the
	 * library's runner, which looks it up in the active store; the queue's lookups, counts and
	 * cleanup read the same tables. That holds for the library's table store (TABLE_STORE), and
	 * for its hybrid store (HYBRID_STORE) while the destination it saves new actions in is the
	 * library's own (HYBRID_DESTINATIONS). The destination is read the way the library builds it,
	 * from its migration controller's config, which another plugin can change with the
	 * `action_scheduler/migration_config` filter. A store another plugin chose instead, with the
	 * `action_scheduler_store_class` filter or as a hybrid store's destination, keeps its actions
	 * elsewhere under ids of its own, so an id from the tables could name one of its actions.
	 * There, `wp seocart jobs run` and the admin tick run no job, and WP-Cron still runs the
	 * plugin's jobs through the library. A subclass of a library store counts as another store:
	 * nothing proves it keeps the same tables.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When Action Scheduler is not initialised.
	 *
	 * @return string|null The class of the store another plugin chose, the active store or a hybrid
	 *                     store's destination; null for the library's own.
	 */
	public function customStore(): ?string {
		$this->assertInitialised();

		$class = get_class( \ActionScheduler::store() );

		if ( self::TABLE_STORE === $class ) {
			return null;
		}

		if ( self::HYBRID_STORE !== $class ) {
			return $class;
		}

		$destination = self::hybridDestination();

		return in_array( $destination, self::HYBRID_DESTINATIONS, true ) ? null : $destination;
	}

	/**
	 * Returns the name of the lock a keyed job is queued under.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler   The handler name.
	 * @param string $uniqueKey The job's key.
	 * @return string The lock name: `job:` and 32 hexadecimal characters.
	 */
	public static function keyLock( string $handler, string $uniqueKey ): string {
		return 'job:' . substr( hash( 'sha256', $handler . "\n" . $uniqueKey ), 0, 32 );
	}

	/**
	 * Counts the plugin's jobs and describes the runner and the runtime in control.
	 *
	 * @since 0.1.0
	 *
	 * @return JobsReport The report. Three queries, and one more per failing handler named.
	 */
	public function report(): JobsReport {
		$rows = $this->db->fetchAll(
			'SELECT a.status, COUNT(*) AS total, SUM( a.scheduled_date_gmt <= UTC_TIMESTAMP() ) AS due, TIMESTAMPDIFF( SECOND, MIN( CASE WHEN a.scheduled_date_gmt <= UTC_TIMESTAMP() THEN a.scheduled_date_gmt END ), UTC_TIMESTAMP() ) AS oldest_due_seconds, TIMESTAMPDIFF( SECOND, MAX( CASE WHEN a.last_attempt_gmt > %s THEN a.last_attempt_gmt END ), UTC_TIMESTAMP() ) AS since_attempt FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s AND a.hook = %s GROUP BY a.status',
			self::NEVER_RAN,
			$this->libraryTable( 'actions' ),
			$this->libraryTable( 'groups' ),
			self::GROUP,
			JobRunner::HOOK
		);

		$byStatus = array();
		$checkIn  = null;

		foreach ( $rows as $row ) {
			$byStatus[ (string) $row['status'] ] = $row;

			// A cancelled row's last attempt is the moment it was cancelled, not a run.
			if ( null !== $row['since_attempt'] && self::CANCELED !== (string) $row['status'] ) {
				$checkIn = null === $checkIn ? (int) $row['since_attempt'] : min( $checkIn, (int) $row['since_attempt'] );
			}
		}

		$pending = $byStatus[ self::PENDING ] ?? null;
		$runtime = $this->runtime();
		$custom  = class_exists( 'ActionScheduler', false ) && 0 !== did_action( 'action_scheduler_init' ) ? $this->customStore() : null;

		return new JobsReport(
			null === $pending ? 0 : (int) $pending['due'],
			null === $pending ? 0 : (int) $pending['total'],
			(int) ( $byStatus[ self::RUNNING ]['total'] ?? 0 ),
			(int) ( $byStatus[ self::FAILED ]['total'] ?? 0 ),
			null === $pending || null === $pending['oldest_due_seconds'] ? null : max( 0, (int) $pending['oldest_due_seconds'] ),
			null === $checkIn ? null : max( 0, $checkIn ),
			$this->failingHandlers(),
			$runtime['version'],
			$runtime['source'],
			$runtime['registered'],
			$custom
		);
	}

	/**
	 * Queues a job, looking up its unique key under the key's lock.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open, the handler is not registered, or Action
	 *                         Scheduler is not initialised.
	 *
	 * @param Job      $job       The job.
	 * @param int|null $timestamp When it may run, as a Unix time, or null for as soon as possible.
	 * @return bool True when queued.
	 */
	private function queue( Job $job, ?int $timestamp ): bool {
		$this->assertReady();

		if ( ! $this->handlers->has( $job->handler ) ) {
			throw new \LogicException( sprintf( 'No job handler is registered as %s, so a job for it would never run.', $job->handler ) );
		}

		$envelope = new JobEnvelope( $job, $this->correlation->current() );
		$key      = $job->uniqueKey;

		if ( null === $key ) {
			$this->store( $envelope, $timestamp );

			return true;
		}

		try {
			return $this->locks->withLock(
				self::keyLock( $job->handler, $key ),
				self::KEY_LOCK_TTL_SECONDS,
				self::KEY_LOCK_WAIT_SECONDS,
				function () use ( $envelope, $job, $key, $timestamp ): bool {
					if ( in_array( $this->latestStatus( JobEnvelope::keyPrefix( $job->handler, $key ) ), array( self::PENDING, self::RUNNING, self::COMPLETE ), true ) ) {
						return false;
					}

					$this->store( $envelope, $timestamp );

					return true;
				}
			);
		} catch ( LockNotAcquired $held ) {
			$this->reportSafely(
				ReportCode::EnqueueContended,
				array(
					'handler' => $job->handler,
					'key'     => $key,
				)
			);

			return false;
		}
	}

	/**
	 * Schedules one recurring handler, unless a run at its current interval is waiting or running.
	 *
	 * A waiting run at another interval, left by an earlier release, is cancelled first.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When Action Scheduler does not store the action.
	 *
	 * @param string $name  The handler name.
	 * @param int    $every Its interval, in seconds.
	 * @return bool True when it was scheduled now.
	 */
	private function scheduleRecurring( string $name, int $every ): bool {
		$envelope = self::recurringEnvelope( $name, $every );

		$current = $this->db->fetchValue(
			'SELECT a.action_id FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s AND a.hook = %s AND a.args = %s AND a.status IN ( %s, %s ) LIMIT 1',
			$this->libraryTable( 'actions' ),
			$this->libraryTable( 'groups' ),
			self::GROUP,
			JobRunner::HOOK,
			$envelope->encode(),
			self::PENDING,
			self::RUNNING
		);

		if ( null !== $current ) {
			return false;
		}

		$this->cancelOwn( $this->ownIds( array( self::PENDING ), JobEnvelope::recurringPrefix( $name ) ) );

		$id = as_schedule_recurring_action( time(), $every, JobRunner::HOOK, $envelope->toArguments(), self::GROUP );

		if ( 0 === (int) $id ) {
			throw new \RuntimeException( sprintf( 'Action Scheduler did not store the recurring %s job.', $name ) );
		}

		return true;
	}

	/**
	 * Stores one run of a job through the library's API.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When Action Scheduler does not store the action.
	 *
	 * @param JobEnvelope $envelope  The run.
	 * @param int|null    $timestamp When it may run, as a Unix time, or null for as soon as possible.
	 */
	private function store( JobEnvelope $envelope, ?int $timestamp ): void {
		$arguments = $envelope->toArguments();

		$id = null === $timestamp
			? as_enqueue_async_action( JobRunner::HOOK, $arguments, self::GROUP )
			: as_schedule_single_action( $timestamp, JobRunner::HOOK, $arguments, self::GROUP );

		if ( 0 === (int) $id ) {
			throw new \RuntimeException( sprintf( 'Action Scheduler did not store the %s job.', $envelope->job->handler ) );
		}
	}

	/**
	 * Returns the status of the latest run of a keyed job: the action with that key stored last.
	 *
	 * A retry and a continuation are new actions with the same key, stored while the run that
	 * asked for them is still running, so the latest run tells how the job stands: waiting or
	 * running, completed, or failed for the last time. An earlier attempt that completed only
	 * because its retry took over does not count.
	 *
	 * @since 0.1.0
	 *
	 * @param string $argsPrefix How the keyed job's stored arguments start.
	 * @return string|null The status, or null when the job was never stored or its record is gone.
	 */
	private function latestStatus( string $argsPrefix ): ?string {
		$status = $this->db->fetchValue(
			'SELECT a.status FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s AND a.hook = %s AND a.args LIKE %s ORDER BY a.action_id DESC LIMIT 1',
			$this->libraryTable( 'actions' ),
			$this->libraryTable( 'groups' ),
			self::GROUP,
			JobRunner::HOOK,
			self::startsWith( $argsPrefix )
		);

		return null === $status ? null : (string) $status;
	}

	/**
	 * Cancels some of the plugin's own waiting actions, and records when.
	 *
	 * The library records no time for a cancellation, and a job cancelled before it ran has no
	 * run to count from; so the cancellation time goes where a run's time goes, and cleanup keeps
	 * the job for the retention period from it.
	 *
	 * @since 0.1.0
	 *
	 * @param int[] $ids The actions.
	 */
	private function cancelOwn( array $ids ): void {
		foreach ( $ids as $id ) {
			\ActionScheduler::store()->cancel_action( $id );
		}

		foreach ( array_chunk( $ids, self::STAMP_BATCH ) as $batch ) {
			$this->db->execute(
				'UPDATE %i SET last_attempt_gmt = UTC_TIMESTAMP(), last_attempt_local = %s WHERE status = %s AND action_id IN ( ' . implode( ', ', array_fill( 0, count( $batch ), '%d' ) ) . ' )',
				$this->libraryTable( 'actions' ),
				current_time( 'mysql' ),
				self::CANCELED,
				...$batch
			);
		}
	}

	/**
	 * Returns the ids of the plugin's own actions in some statuses, optionally by the start of their arguments or by age.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]    $statuses   The statuses.
	 * @param string|null $argsPrefix Optional. How the stored arguments start. Default null, any.
	 * @param int|null    $olderThan  Optional. Only actions last run more than this many seconds ago,
	 *                                or, for one that never ran, due more than this many seconds
	 *                                ago. Default null, any age.
	 * @param int|null    $limit      Optional. At most this many. Default null, all.
	 * @return list<int> The ids, oldest first.
	 */
	private function ownIds( array $statuses, ?string $argsPrefix = null, ?int $olderThan = null, ?int $limit = null ): array {
		$sql  = 'SELECT a.action_id FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s AND a.hook = %s AND a.status IN ( ' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';
		$args = array_merge( array( $this->libraryTable( 'actions' ), $this->libraryTable( 'groups' ), self::GROUP, JobRunner::HOOK ), $statuses );

		if ( null !== $argsPrefix ) {
			$sql   .= ' AND a.args LIKE %s';
			$args[] = self::startsWith( $argsPrefix );
		}

		if ( null !== $olderThan ) {
			$sql   .= ' AND CASE WHEN a.last_attempt_gmt > %s THEN a.last_attempt_gmt ELSE a.scheduled_date_gmt END < UTC_TIMESTAMP() - INTERVAL %d SECOND';
			$args[] = self::NEVER_RAN;
			$args[] = $olderThan;
		}

		$sql .= ' ORDER BY a.action_id';

		if ( null !== $limit ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		$rows = $this->db->fetchAll( $sql, ...$args );

		return array_map( static fn( array $row ): int => (int) $row['action_id'], $rows );
	}

	/**
	 * Ranks the handlers of the most recent failed jobs, with the last error of each.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{handler: string, failures: int, last_error: string}> The top handlers, most failures first.
	 */
	private function failingHandlers(): array {
		$rows = $this->db->fetchAll(
			'SELECT a.action_id, a.args FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s AND a.hook = %s AND a.status = %s ORDER BY a.last_attempt_gmt DESC, a.action_id DESC LIMIT %d',
			$this->libraryTable( 'actions' ),
			$this->libraryTable( 'groups' ),
			self::GROUP,
			JobRunner::HOOK,
			self::FAILED,
			self::FAILING_SAMPLE
		);

		$counts = array();
		$latest = array();

		foreach ( $rows as $row ) {
			$handler = self::handlerOf( (string) $row['args'] );

			$counts[ $handler ] = ( $counts[ $handler ] ?? 0 ) + 1;
			$latest[ $handler ] = $latest[ $handler ] ?? (int) $row['action_id'];
		}

		uksort(
			$counts,
			static fn( string $a, string $b ): int => array( $counts[ $b ], $a ) <=> array( $counts[ $a ], $b )
		);

		$failing = array();

		foreach ( array_slice( $counts, 0, self::FAILING_TOP, true ) as $handler => $failures ) {
			$message = $this->db->fetchValue(
				'SELECT message FROM %i WHERE action_id = %d ORDER BY log_id DESC LIMIT 1',
				$this->libraryTable( 'logs' ),
				$latest[ $handler ]
			);

			$failing[] = array(
				'handler'    => (string) $handler,
				'failures'   => $failures,
				'last_error' => mb_substr( (string) $message, 0, self::ERROR_LENGTH ),
			);
		}

		return $failing;
	}

	/**
	 * Describes the Action Scheduler copy in control: the one whose classes this request loaded.
	 *
	 * Version negotiation initialises the highest registered copy, but a copy loaded outside it
	 * can still have defined the classes first. So the copy in control is read from where its
	 * main class was loaded, and its version from that copy's own header.
	 *
	 * @since 0.1.0
	 *
	 * @return array{version: string, source: string, registered: list<string>} The runtime.
	 */
	private function runtime(): array {
		if ( ! class_exists( 'ActionScheduler', false ) ) {
			return array(
				'version'    => '',
				'source'     => 'not loaded',
				'registered' => array(),
			);
		}

		$root    = dirname( (string) ( new \ReflectionMethod( 'ActionScheduler', 'store' ) )->getFileName(), 3 );
		$headers = get_file_data( $root . '/action-scheduler.php', array( 'version' => 'Version' ) );

		$registered = class_exists( 'ActionScheduler_Versions', false ) ? array_map( 'strval', array_keys( \ActionScheduler_Versions::instance()->get_versions() ) ) : array();

		usort( $registered, static fn( string $a, string $b ): int => version_compare( $b, $a ) );

		return array(
			'version'    => (string) $headers['version'],
			'source'     => self::sourceOf( $root ),
			'registered' => $registered,
		);
	}

	/**
	 * Names where a copy of Action Scheduler lives: SEOCart, a plugin, a must-use plugin or a theme.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root The copy's directory.
	 * @return string For example `SEOCart`, `plugin woocommerce`, `theme storefront`, or the path.
	 */
	private static function sourceOf( string $root ): string {
		$root = self::resolved( $root );

		if ( defined( 'SEOCART_PLUGIN_FILE' ) && str_starts_with( $root . '/', self::resolved( dirname( SEOCART_PLUGIN_FILE ) ) . '/' ) ) {
			return 'SEOCart';
		}

		$places = array(
			'plugin'          => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'must-use plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'theme'           => get_theme_root(),
		);

		foreach ( $places as $kind => $directory ) {
			if ( '' === (string) $directory ) {
				continue;
			}

			$directory = rtrim( self::resolved( (string) $directory ), '/' ) . '/';

			if ( str_starts_with( $root, $directory ) ) {
				return $kind . ' ' . (string) strtok( substr( $root, strlen( $directory ) ), '/' );
			}
		}

		return $root;
	}

	/**
	 * Resolves a directory the way PHP reports a loaded file's path: symbolic links followed, forward slashes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The path.
	 * @return string The resolved path, or the path as given when it does not exist.
	 */
	private static function resolved( string $path ): string {
		$real = realpath( $path );

		return wp_normalize_path( false === $real ? $path : $real );
	}

	/**
	 * Returns how the stored arguments of the jobs a cancellation names start.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a unique key is given without its handler.
	 *
	 * @param string|null $handler   The handler, or null for every job.
	 * @param string|null $uniqueKey The unique key, or null for every job of the handler.
	 * @return string|null The prefix, or null for every job.
	 */
	private static function cancelPrefix( ?string $handler, ?string $uniqueKey ): ?string {
		if ( null === $handler ) {
			if ( null !== $uniqueKey ) {
				throw new \InvalidArgumentException( 'A unique key belongs to one handler; name the handler to cancel a keyed job.' );
			}

			return null;
		}

		return null === $uniqueKey ? JobEnvelope::prefixFor( $handler ) : JobEnvelope::keyPrefix( $handler, $uniqueKey );
	}

	/**
	 * Returns the length of a retention period, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @param string $period An ISO 8601 duration, as the retention catalog writes it.
	 * @return int Its length, counted from the Unix epoch.
	 */
	private static function seconds( string $period ): int {
		return ( new \DateTimeImmutable( '@0' ) )->add( new \DateInterval( $period ) )->getTimestamp();
	}

	/**
	 * Returns the LIKE pattern of the text that starts with a prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix The prefix.
	 * @return string The prefix with LIKE's wildcards and escape character escaped, then `%`.
	 */
	private static function startsWith( string $prefix ): string {
		return addcslashes( $prefix, '\\%_' ) . '%';
	}

	/**
	 * Reads the handler name out of a failed job's stored arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $stored The stored arguments.
	 * @return string The handler name, or `(unreadable)`.
	 */
	private static function handlerOf( string $stored ): string {
		$arguments = json_decode( $stored, true );

		try {
			return JobEnvelope::fromStored( is_array( $arguments ) ? ( $arguments[0] ?? null ) : null )->job->handler;
		} catch ( \UnexpectedValueException $unreadable ) {
			return '(unreadable)';
		}
	}

	/**
	 * Returns the full name of one of Action Scheduler's tables on the current site.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name `actions`, `groups` or `logs`.
	 * @return string The name, read at call time so it follows switch_to_blog().
	 */
	private function libraryTable( string $name ): string {
		return $this->db->prefix() . 'actionscheduler_' . $name;
	}

	/**
	 * Refuses to touch the queue inside a transaction, or before the library is initialised.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open, or Action Scheduler is not initialised.
	 */
	private function assertReady(): void {
		if ( 0 !== $this->db->depth() ) {
			throw new \LogicException( 'Jobs are queued and cancelled outside any transaction: the queue is not part of the unit of work, so the change would outlive a rollback. Queue the job after commit.' );
		}

		$this->assertInitialised();
	}

	/**
	 * Reads, in one query, whether a job is due, and which recurring handlers have no run waiting or running at their interval.
	 *
	 * Two index lookups in one statement: the first due action of the hook, on the library's
	 * hook-status-date index, and the recurring runs by their exact stored arguments, on its
	 * arguments index.
	 *
	 * @since 0.1.0
	 *
	 * @return array{due: bool, missing: array<string, int>} Whether a job is due, and the recurring
	 *                                                        handlers without a run, with their intervals.
	 */
	private function runnerWork(): array {
		$recurring = array();

		foreach ( $this->handlers->recurring() as $name => $every ) {
			$recurring[ self::recurringEnvelope( $name, $every )->encode() ] = array( $name, $every );
		}

		$actions = $this->libraryTable( 'actions' );
		$sql     = '( SELECT %s AS args FROM %i WHERE hook = %s AND status = %s AND scheduled_date_gmt <= UTC_TIMESTAMP() LIMIT 1 )';
		$args    = array( self::DUE_MARKER, $actions, JobRunner::HOOK, self::PENDING );

		if ( array() !== $recurring ) {
			$sql .= ' UNION ALL ( SELECT DISTINCT args FROM %i WHERE hook = %s AND status IN ( %s, %s ) AND args IN ( ' . implode( ', ', array_fill( 0, count( $recurring ), '%s' ) ) . ' ) )';
			$args = array_merge( $args, array( $actions, JobRunner::HOOK, self::PENDING, self::RUNNING ), array_map( 'strval', array_keys( $recurring ) ) );
		}

		$found   = array_map( static fn( array $row ): string => (string) $row['args'], $this->db->fetchAll( $sql, ...$args ) );
		$missing = array();

		foreach ( $recurring as $encoded => $handler ) {
			if ( ! in_array( (string) $encoded, $found, true ) ) {
				$missing[ $handler[0] ] = $handler[1];
			}
		}

		return array(
			'due'     => in_array( self::DUE_MARKER, $found, true ),
			'missing' => $missing,
		);
	}

	/**
	 * Builds the stored form of a recurring handler's run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name  The handler name.
	 * @param int    $every Its interval, in seconds.
	 * @return JobEnvelope The run, as it is stored at that interval.
	 */
	private static function recurringEnvelope( string $name, int $every ): JobEnvelope {
		return new JobEnvelope( new Job( $name ), null, 1, $every );
	}

	/**
	 * Names the class of the store a hybrid store saves new actions in, read the way the library builds it.
	 *
	 * @since 0.1.0
	 *
	 * @return string The destination's class; the hybrid store's own when the migration config
	 *                names no destination, or is not a config at all.
	 */
	private static function hybridDestination(): string {
		try {
			return get_class( \Action_Scheduler\Migration\Controller::instance()->get_migration_config_object()->get_destination_store() );
		} catch ( \Throwable $unreadable ) {
			// A filter returned no usable config: nothing proves where new actions go.
			return self::HYBRID_STORE;
		}
	}

	/**
	 * Refuses to touch the library before it is initialised.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When Action Scheduler is not initialised.
	 */
	private function assertInitialised(): void {
		if ( ! class_exists( 'ActionScheduler', false ) || 0 === did_action( 'action_scheduler_init' ) ) {
			throw new \LogicException( 'The job queue is used before Action Scheduler is initialised; wait for the action_scheduler_init action.' );
		}
	}

	/**
	 * Reports. A reporter that throws loses its report, never the caller's work.
	 *
	 * @since 0.1.0
	 *
	 * @param ReportCode           $code    The code.
	 * @param array<string, mixed> $context What happened.
	 */
	private function reportSafely( ReportCode $code, array $context ): void {
		try {
			( $this->report )( $code->value, $context );
		} catch ( \Throwable $reporterFailed ) {
			unset( $reporterFailed );
		}
	}
}
