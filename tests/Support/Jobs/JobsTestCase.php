<?php
/**
 * JobsTestCase: the base of the tests that queue and run jobs on the real Action Scheduler
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\JobEnvelope;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read and age Action Scheduler's rows directly, to see what the queue stored.

/**
 * A DatabaseTestCase with the jobs module wired as the kernel wires it, over the real Action Scheduler.
 *
 * Owns one fact: how a jobs test gets a queue, a runner and the triggers, and leaves Action
 * Scheduler's tables as it found them. Action Scheduler writes with autocommit through the
 * shared connection, so every action of the plugin's group, of OTHER_GROUP and of the plugin's
 * hook is deleted, with its log lines, before and after each test.
 *
 * A run of the library's queue runner in a test is the run a fresh WP-Cron request makes, however
 * long the test process has been running and whatever the queue held before the test:
 *
 * - Its stop is a count, not a clock. The library counts its time limit from when its one runner
 *   was created, which in a test process was at bootstrap; late in a long run it would start one
 *   action per run. For each test the runner stops after MAX_ACTIONS_PER_RUN actions instead of
 *   when its time looks spent, so a run is never cut short by the process's age, and a job that
 *   puts itself back due at once fails its test instead of hanging the suite. The filter is
 *   removed after the test.
 * - It sees only the actions the test stored. Every action already pending when the test starts
 *   — the library's own, such as its migration hook, due a minute after the test site is
 *   installed, or one another test left — is deferred for the test and put back after it.
 *   Deferring leaves every group in reach of the library's runner, which some tests require;
 *   limiting the runner to the plugin's group would not.
 *
 * The handlers are the fixture ones (RecordingJob, RecurringJob), resolved with the test's
 * correlation id; a test that needs other handlers calls wire(). The runner's hook is
 * registered as the kernel registers it, in place of the kernel's own runner, which the plugin
 * hooked when it booted; `$this->paused` pauses the runner and the triggers.
 * The lock service holds its locks in table mode, so a SecondConnection can hold one too. The
 * `locks` and `outbox` tables are created, and dropped by the base class.
 *
 * @since 0.1.0
 */
abstract class JobsTestCase extends DatabaseTestCase {

	/**
	 * The group another plugin's actions live in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const OTHER_GROUP = 'another-plugin';

	/**
	 * The hook another plugin's actions fire.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const OTHER_HOOK = 'another_plugin_task';

	/**
	 * How far an action pending before the test is deferred while it runs: past any test's reach.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEFERRAL_YEARS = 1000;

	/**
	 * How many actions one run of the library's queue runner may process in a jobs test before it stops.
	 *
	 * Far above what any test stores, and low enough that a job requeuing itself at once ends the
	 * run within a second or two.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_ACTIONS_PER_RUN = 500;

	/**
	 * The filter that replaces the library's time-based stop with MAX_ACTIONS_PER_RUN, kept so tear_down() removes this one.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $runStop = null;

	/**
	 * The ids of the actions deferred for the test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $deferred = array();

	/**
	 * The request's correlation id, minting sequential ids from 1.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	protected CorrelationId $correlation;

	/**
	 * The registered handlers.
	 *
	 * @since 0.1.0
	 *
	 * @var JobHandlers
	 */
	protected JobHandlers $handlers;

	/**
	 * The queue.
	 *
	 * @since 0.1.0
	 *
	 * @var ActionSchedulerQueue
	 */
	protected ActionSchedulerQueue $queue;

	/**
	 * The runner. It mints the correlation ids of recurring runs from 1000.
	 *
	 * @since 0.1.0
	 *
	 * @var JobRunner
	 */
	protected JobRunner $runner;

	/**
	 * The triggers, with a drainer over the test's outbox.
	 *
	 * @since 0.1.0
	 *
	 * @var RunnerTriggers
	 */
	protected RunnerTriggers $triggers;

	/**
	 * The outbox rows.
	 *
	 * @since 0.1.0
	 *
	 * @var Outbox
	 */
	protected Outbox $outbox;

	/**
	 * Whether jobs are paused.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	protected bool $paused = false;

	/**
	 * Creates the tables, wires the module, clears the queue and gives the library's runner a fresh request's run.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );

		$operations->createTable( PlatformTables::locks() );
		( new CreateOutboxMigration() )->up( $operations );

		$this->purgeActions();
		$this->deferPendingActions();

		$this->runStop = static fn( $likely, $runner, $processed ): bool => (int) $processed >= self::MAX_ACTIONS_PER_RUN;
		add_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', $this->runStop, PHP_INT_MAX, 3 );

		RecordingJob::reset();
		RecurringJob::reset();

		$this->paused      = false;
		$this->correlation = new CorrelationId( new SequentialIdGenerator() );
		$this->outbox      = new Outbox( $this->db );

		$this->wire( array( RecordingJob::class, RecurringJob::class ) );
	}

	/**
	 * Clears the queue, puts back the deferred actions and gives the library's runner its time limit back.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		if ( null !== $this->runStop ) {
			remove_filter( 'action_scheduler_maximum_execution_time_likely_to_be_exceeded', $this->runStop, PHP_INT_MAX );
			$this->runStop = null;
		}

		$this->purgeActions();
		$this->restoreDeferredActions();

		parent::tear_down();
	}

	/**
	 * Builds the handlers, the queue, the runner and the triggers, and hooks the runner.
	 *
	 * @since 0.1.0
	 *
	 * @param string[]      $classes  The handler classes.
	 * @param callable|null $resolve  Optional. Builds a handler from its class. Default: with the test's correlation id.
	 * @param callable|null $clock    Optional. The runner's monotonic clock in nanoseconds. Default hrtime().
	 */
	protected function wire( array $classes, ?callable $resolve = null, ?callable $clock = null ): void {
		if ( isset( $this->runner ) ) {
			remove_action( JobRunner::HOOK, array( $this->runner, 'run' ) );
		}

		KernelHooks::detach( JobRunner::HOOK );

		$locks          = $this->locks();
		$this->handlers = new JobHandlers( $classes, $resolve ?? fn( string $handlerClass ): object => new $handlerClass( $this->correlation ) );
		$this->queue    = new ActionSchedulerQueue( $this->db, $locks, $this->handlers, $this->correlation, $this->reporter() );
		$this->runner   = new JobRunner( $this->handlers, $this->queue, $this->correlation, new SequentialIdGenerator( 1000 ), $this->reporter(), fn(): bool => $this->paused, $clock );
		$this->triggers = new RunnerTriggers( $this->runner, $this->queue, $this->drainer(), $locks, $this->reporter(), fn(): bool => $this->paused );

		add_action( JobRunner::HOOK, array( $this->runner, 'run' ) );
	}

	/**
	 * Returns a lock service over the test's connection, in table mode.
	 *
	 * @since 0.1.0
	 *
	 * @return LockService The service.
	 */
	protected function locks(): LockService {
		return new LockService( $this->db, LockMode::Table, $this->sleeper() );
	}

	/**
	 * Returns a drainer over the test's outbox, with the fixture events.
	 *
	 * @since 0.1.0
	 *
	 * @return OutboxDrainer The drainer.
	 */
	protected function drainer(): OutboxDrainer {
		return new OutboxDrainer( $this->db, $this->outbox, new HookBridge( $this->reporter() ), new EventCatalog( array( ThingHappened::class, ThingNoticed::class ) ), $this->locks(), $this->correlation, $this->reporter() );
	}

	/**
	 * Lists the actions of a group, oldest first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Optional. The group. Default the plugin's.
	 * @return list<array{id: int, hook: string, status: string, args: string, due_in: int, attempted_ago: int|null}> The actions,
	 *         with the seconds until each is due (negative when it is overdue) and since its last attempt.
	 */
	protected function actions( string $group = JobQueue::GROUP ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.action_id, a.hook, a.status, a.args, TIMESTAMPDIFF( SECOND, UTC_TIMESTAMP(), a.scheduled_date_gmt ) AS due_in, IF( a.last_attempt_gmt > '2000-01-01', TIMESTAMPDIFF( SECOND, a.last_attempt_gmt, UTC_TIMESTAMP() ), NULL ) AS attempted_ago FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s ORDER BY a.action_id",
				$wpdb->prefix . 'actionscheduler_actions',
				$wpdb->prefix . 'actionscheduler_groups',
				$group
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'            => (int) $row['action_id'],
				'hook'          => (string) $row['hook'],
				'status'        => (string) $row['status'],
				'args'          => (string) $row['args'],
				'due_in'        => (int) $row['due_in'],
				'attempted_ago' => null === $row['attempted_ago'] ? null : (int) $row['attempted_ago'],
			),
			$rows
		);
	}

	/**
	 * Reads the job an action stores.
	 *
	 * @since 0.1.0
	 *
	 * @param array{args: string} $action The action, as actions() lists it.
	 * @return JobEnvelope The stored job.
	 */
	protected static function envelopeOf( array $action ): JobEnvelope {
		$arguments = json_decode( $action['args'], true, 512, JSON_THROW_ON_ERROR );

		return JobEnvelope::fromStored( $arguments[0] ?? null );
	}

	/**
	 * Makes an action due now.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The action id.
	 */
	protected function makeDue( int $id ): void {
		$this->reschedule( $id, -1 );
	}

	/**
	 * Moves when an action is due.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id             The action id.
	 * @param int $secondsFromNow When it is due, in seconds from now; negative for the past.
	 */
	protected function reschedule( int $id, int $secondsFromNow ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scheduled_date_gmt = UTC_TIMESTAMP() + INTERVAL %d SECOND, scheduled_date_local = UTC_TIMESTAMP() + INTERVAL %d SECOND WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $secondsFromNow, $secondsFromNow, $id ) );
	}

	/**
	 * Sets an action's status, and when it was last attempted.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id      The action id.
	 * @param string $status  The status.
	 * @param int    $daysAgo How many days ago its last attempt was.
	 */
	protected function setStatus( int $id, string $status, int $daysAgo = 0 ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL %d DAY, last_attempt_local = UTC_TIMESTAMP() - INTERVAL %d DAY WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $status, $daysAgo, $daysAgo, $id ) );
	}

	/**
	 * Stores an action of another plugin, through the library's API.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $hook      Optional. Its hook. Default OTHER_HOOK.
	 * @param array<mixed> $arguments Optional. Its arguments. Default one number.
	 * @return int Its id.
	 */
	protected function otherPluginAction( string $hook = self::OTHER_HOOK, array $arguments = array( 7 ) ): int {
		return $this->storeAction( $hook, $arguments, self::OTHER_GROUP );
	}

	/**
	 * Stores an action through the library's API, bypassing the queue.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $hook      Its hook.
	 * @param array<mixed> $arguments Its arguments.
	 * @param string       $group     Its group.
	 * @return int Its id.
	 */
	protected function storeAction( string $hook, array $arguments, string $group ): int {
		return (int) as_enqueue_async_action( $hook, $arguments, $group );
	}

	/**
	 * Adds a line to an action's log, through the library's logger.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id      The action id.
	 * @param string $message The line.
	 */
	protected function logToAction( int $id, string $message ): void {
		\ActionScheduler_Logger::instance()->log( (string) $id, $message );
	}

	/**
	 * Runs the library's own queue runner once, as WP-Cron runs it: until it finds nothing due in any group.
	 *
	 * One run is enough because the process's age never cuts a run short in a jobs test; it stops
	 * after MAX_ACTIONS_PER_RUN actions (see the class).
	 *
	 * @since 0.1.0
	 */
	protected function runLibraryQueue(): void {
		\ActionScheduler_QueueRunner::instance()->run( 'WP Cron' );
	}

	/**
	 * Returns the latest line of an action's log.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id The action id.
	 * @return string The message, or an empty string.
	 */
	protected function lastLog( int $id ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT message FROM %i WHERE action_id = %d ORDER BY log_id DESC LIMIT 1', $wpdb->prefix . 'actionscheduler_logs', $id ) );
	}

	/**
	 * Returns the codes reported so far, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The codes.
	 */
	protected function reportedCodes(): array {
		return array_column( $this->reports, 'code' );
	}

	/**
	 * Deletes every action of the plugin's hook, the plugin's group and OTHER_GROUP, with its log lines.
	 *
	 * @since 0.1.0
	 */
	private function purgeActions(): void {
		PluginActions::purge( array( JobRunner::HOOK, self::OTHER_HOOK ), array( JobQueue::GROUP, self::OTHER_GROUP ) );
	}

	/**
	 * Defers every action pending before the test by DEFERRAL_YEARS.
	 *
	 * @since 0.1.0
	 */
	private function deferPendingActions(): void {
		global $wpdb;

		$table          = $wpdb->prefix . 'actionscheduler_actions';
		$this->deferred = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT action_id FROM %i WHERE status = %s', $table, 'pending' ) ) );

		foreach ( $this->deferred as $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scheduled_date_gmt = scheduled_date_gmt + INTERVAL %d YEAR, scheduled_date_local = scheduled_date_local + INTERVAL %d YEAR WHERE action_id = %d', $table, self::DEFERRAL_YEARS, self::DEFERRAL_YEARS, $id ) );
		}
	}

	/**
	 * Puts each deferred action back to when it was due, unless the test moved it.
	 *
	 * @since 0.1.0
	 */
	private function restoreDeferredActions(): void {
		global $wpdb;

		foreach ( $this->deferred as $id ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scheduled_date_gmt = scheduled_date_gmt - INTERVAL %d YEAR, scheduled_date_local = scheduled_date_local - INTERVAL %d YEAR WHERE action_id = %d AND scheduled_date_gmt > UTC_TIMESTAMP() + INTERVAL %d YEAR', $wpdb->prefix . 'actionscheduler_actions', self::DEFERRAL_YEARS, self::DEFERRAL_YEARS, $id, self::DEFERRAL_YEARS - 1 ) );
		}

		$this->deferred = array();
	}
}
