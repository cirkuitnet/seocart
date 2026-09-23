<?php
/**
 * RunnerTriggers: the two runner triggers the plugin owns, the command and the admin tick
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\DrainReport;
use SEOCart\Platform\Events\OutboxDrainer;

defined( 'ABSPATH' ) || exit;

/**
 * Runs background work when an operator's cron calls the command, or opportunistically at the end of an admin request.
 *
 * Owns one fact: what the plugin's own triggers do; WP-Cron, the third, is Action Scheduler's.
 *
 * - command() backs `wp seocart jobs run`, which a system cron runs every minute on a site
 *   where WP-Cron does not fire reliably. It drains the outbox, without the drain's
 *   opportunistic prune (the retention job prunes), schedules any recurring job that has lost
 *   its next run, then runs the plugin's due jobs, within one time budget.
 * - tick() is the opportunistic trigger on admin requests, for sites where no runner fires at
 *   all. It costs one query when nothing is due and every recurring job has its next run. When
 *   there is work it takes the `jobs_tick` lock without waiting, so concurrent admin requests
 *   do not all run jobs, schedules any missing recurring run, and runs due jobs for
 *   TICK_BUDGET_SECONDS. The outbox catch-up is one of those jobs, so the tick drains the
 *   outbox too, every few minutes. It never throws: a failure is reported.
 * - Where Action Scheduler keeps its actions in a store another plugin chose, neither runs a job
 *   (JobRunner::runDue() says why). The command reports it; the tick, which runs on every admin
 *   request, stays silent and costs no query, and `wp seocart jobs status` shows the store.
 * - repairRecurring() schedules again any recurring job that lost its next run, on the
 *   library's WP-Cron hooks (CRON_REPAIR_HOOKS), for a site where only WP-Cron runs jobs. A
 *   recurring run that dies of a fatal error or times out is marked failed by the library
 *   without a next run; the command and the tick repair that too, but such a site has neither.
 *   One query when nothing is missing, and only on the requests that run the library's queue.
 * - The tick runs no job in a request whose events the wake handed to the runner because the
 *   response could not end early (EventWake::handedOff()): it would deliver them inline.
 * - tickAtShutdown() defers the tick to the end of the admin request, after its page is built,
 *   the way the outbox is drained after a publishing request. On PHP-FPM the connection stays
 *   open until shutdown work ends, so the tick adds up to its budget of latency to the request
 *   that ran it, and only when a job is due.
 *
 * Nothing runs while jobs are paused.
 *
 * @since 0.1.0
 */
final class RunnerTriggers {

	/**
	 * The lock one admin tick holds at a time.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const TICK_LOCK = 'jobs_tick';

	/**
	 * How long an admin tick keeps starting jobs, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const TICK_BUDGET_SECONDS = 2;

	/**
	 * The default time budget of `wp seocart jobs run`, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const COMMAND_BUDGET_SECONDS = 60;

	/**
	 * The library's hooks the kernel adds repairRecurring() to, with their priorities.
	 *
	 * `action_scheduler_run_queue` is the WP-Cron hook of the library's queue runner, in every
	 * supported version; at priority 5 the repair comes before the runner (priority 10), which
	 * then runs the restored job in the same pass. `action_scheduler_ensure_recurring_actions` is
	 * the daily hook newer versions fire for plugins to schedule their recurring actions; where it
	 * does not exist it never fires.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, int>
	 */
	public const CRON_REPAIR_HOOKS = array(
		'action_scheduler_run_queue'                => 5,
		'action_scheduler_ensure_recurring_actions' => 10,
	);

	/**
	 * The most of the command's budget the outbox drain may take, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const COMMAND_DRAIN_SECONDS = 20;

	/**
	 * How long the tick lock lasts without renewal, in seconds: longer than any tick.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TICK_LOCK_TTL_SECONDS = 60;

	/**
	 * Runs due jobs.
	 *
	 * @since 0.1.0
	 *
	 * @var JobRunner
	 */
	private JobRunner $runner;

	/**
	 * Tells whether a job is due.
	 *
	 * @since 0.1.0
	 *
	 * @var ActionSchedulerQueue
	 */
	private ActionSchedulerQueue $queue;

	/**
	 * Drains the outbox for the command.
	 *
	 * @since 0.1.0
	 *
	 * @var OutboxDrainer
	 */
	private OutboxDrainer $drainer;

	/**
	 * Takes the tick lock.
	 *
	 * @since 0.1.0
	 *
	 * @var LockService
	 */
	private LockService $locks;

	/**
	 * Receives a report code and its context.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * Tells whether jobs are paused.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): bool
	 */
	private $isPaused;

	/**
	 * Tells whether this request handed its events to the job runner.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): bool
	 */
	private $handedOff;

	/**
	 * Whether tick() is registered to run at shutdown.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $tickRegistered = false;

	/**
	 * Creates the triggers. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param JobRunner            $runner    Runs due jobs.
	 * @param ActionSchedulerQueue $queue     Tells whether there is work.
	 * @param OutboxDrainer        $drainer   Drains the outbox for the command.
	 * @param LockService          $locks     Takes the tick lock.
	 * @param callable             $report    Receives a report code (string) and its context (array).
	 * @param callable|null        $isPaused  Optional. Returns true while jobs are paused. Default null, never paused.
	 * @param callable|null        $handedOff Optional. Returns true when this request handed its events to the
	 *                                        job runner instead of delivering them, normally
	 *                                        EventWake::handedOff(). Default null, never.
	 */
	public function __construct( JobRunner $runner, ActionSchedulerQueue $queue, OutboxDrainer $drainer, LockService $locks, callable $report, ?callable $isPaused = null, ?callable $handedOff = null ) {
		$this->runner    = $runner;
		$this->queue     = $queue;
		$this->drainer   = $drainer;
		$this->locks     = $locks;
		$this->report    = $report;
		$this->isPaused  = $isPaused ?? static fn(): bool => false;
		$this->handedOff = $handedOff ?? static fn(): bool => false;
	}

	/**
	 * Drains the outbox, then runs the plugin's due jobs, within one budget. Backs `wp seocart jobs run`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $budgetSeconds Optional. The time budget, 1 second or more. Default COMMAND_BUDGET_SECONDS.
	 * @return array{drain: DrainReport|null, ran: int, exhausted: bool, paused: bool, custom_store: string|null} The
	 *         drain's report (null when paused), how many jobs ran, whether the budget ran out, whether
	 *         nothing ran because jobs are paused, and the class of the store another plugin chose when
	 *         no job ran because of it.
	 */
	public function command( int $budgetSeconds = self::COMMAND_BUDGET_SECONDS ): array {
		if ( ( $this->isPaused )() ) {
			return array(
				'drain'        => null,
				'ran'          => 0,
				'exhausted'    => false,
				'paused'       => true,
				'custom_store' => null,
			);
		}

		$budgetSeconds = max( 1, $budgetSeconds );
		$started       = hrtime( true );
		$drain         = $this->drainer->drain( new DrainOptions( min( $budgetSeconds, self::COMMAND_DRAIN_SECONDS ), prune: false ) );
		$spent         = (int) ceil( ( hrtime( true ) - $started ) / 1000000000 );

		// A recurring job whose next run was lost (a fatal error in its run, a cancellation) comes back.
		if ( null === $this->queue->customStore() ) {
			$this->queue->ensureRecurring();
		}

		$jobs = $this->runner->runDue( max( 1, $budgetSeconds - $spent ), 'WP CLI' );

		return array(
			'drain'        => $drain,
			'ran'          => $jobs['ran'],
			'exhausted'    => $jobs['exhausted'] || $drain->budgetExhausted,
			'paused'       => $jobs['paused'],
			'custom_store' => $jobs['custom_store'],
		);
	}

	/**
	 * Runs the plugin's due jobs for a moment, if any is due and no other tick is running. Never throws.
	 *
	 * @since 0.1.0
	 */
	public function tick(): void {
		// After a fatal error the request is in no state to run anything; the next tick runs the jobs.
		if ( OutboxDrainer::isFatal( error_get_last() ) ) {
			return;
		}

		// This request handed its events to the runner, so as not to deliver them before its response ends.
		if ( ( $this->handedOff )() ) {
			return;
		}

		try {
			if ( ( $this->isPaused )() || null !== $this->queue->customStore() || ! $this->queue->hasWork() ) {
				return;
			}

			$this->locks->withLock(
				self::TICK_LOCK,
				self::TICK_LOCK_TTL_SECONDS,
				0,
				function (): array {
					$this->queue->ensureRecurring();

					return $this->runner->runDue( self::TICK_BUDGET_SECONDS, 'SEOCart admin' );
				}
			);
		} catch ( LockNotAcquired $held ) {
			// Another admin request is running the jobs.
			return;
		} catch ( \Throwable $failure ) {
			try {
				( $this->report )(
					ReportCode::TickFailed->value,
					array(
						'exception' => get_class( $failure ),
						'message'   => $failure->getMessage(),
					)
				);
			} catch ( \Throwable $reporterFailed ) {
				unset( $reporterFailed );
			}
		}
	}

	/**
	 * Schedules again any recurring job that lost its next run. Added to the library's WP-Cron hooks (CRON_REPAIR_HOOKS); never throws.
	 *
	 * One query when every recurring job has its next run. Nothing on a store another plugin
	 * chose, whose actions the lookup cannot see: scheduling there would add a run every time.
	 * A failure is reported, never thrown into the library's queue runner.
	 *
	 * @since 0.1.0
	 */
	public function repairRecurring(): void {
		try {
			if ( null !== $this->queue->customStore() ) {
				return;
			}

			$this->queue->ensureRecurring();
		} catch ( \Throwable $failure ) {
			try {
				( $this->report )(
					ReportCode::RepairFailed->value,
					array(
						'exception' => get_class( $failure ),
						'message'   => $failure->getMessage(),
					)
				);
			} catch ( \Throwable $reporterFailed ) {
				unset( $reporterFailed );
			}
		}
	}

	/**
	 * Arranges for tick() to run at the end of this request. No query, no I/O; once per process.
	 *
	 * @since 0.1.0
	 */
	public function tickAtShutdown(): void {
		if ( $this->tickRegistered ) {
			return;
		}

		$this->tickRegistered = true;

		register_shutdown_function( array( $this, 'tick' ) );
	}
}
