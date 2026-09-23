<?php
/**
 * JobRunner: runs the plugin's jobs when Action Scheduler fires them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

use SEOCart\Platform\Events\Backoff;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A failed job's message goes to Action Scheduler's log and the reporter, never to HTML.

/**
 * Runs one job for Action Scheduler, and runs the plugin's due jobs on demand.
 *
 * Owns one fact: what happens when a job runs. run() is the callback of HOOK, whichever copy
 * of Action Scheduler fires it (the kernel registers it). It reads the stored JobEnvelope,
 * resolves the handler by name, and runs it under the correlation id of the request that
 * queued the job; a recurring run gets a fresh id. Then:
 *
 * - The handler returns null: done. Action Scheduler marks the action complete.
 * - It returns a delay: the same job runs again after it, as a new action (a continuation, for
 *   work that stops at its time budget). A recurring handler's delay is ignored.
 * - It throws, on a queued job with attempts left: the next attempt is stored to run after the
 *   Backoff delay, and this action completes. On the last attempt, or on a recurring run, the
 *   failure is rethrown, so Action Scheduler records the action as failed, with the message in
 *   its log, and it is counted: an exhausted job is never silently gone. A recurring job keeps
 *   its schedule however often it fails (keepRescheduling()).
 * - Jobs are paused (Safe Mode): nothing runs. A queued job is stored again to run after
 *   PAUSED_DELAY_SECONDS, unchanged; a recurring run is skipped, since the next one follows.
 *
 * A stored job that cannot be read, or names a handler no longer registered, fails at once.
 *
 * runDue() is how `wp seocart jobs run` and the admin tick run jobs: it claims the plugin's due
 * actions, and only the plugin's, and hands each to the library's own queue runner, which
 * marks it running, fires HOOK, records the outcome and schedules a recurring action's next
 * run, exactly as it does for WP-Cron. A fatal error in a handler is recorded by the library's
 * fatal-error monitor as the action's failure. The ids come from the library's tables, so
 * runDue() runs nothing when the library uses a store another plugin chose
 * (ActionSchedulerQueue::customStore()): an id from the tables could name one of that store's
 * actions. WP-Cron runs the plugin's jobs there.
 *
 * @since 0.1.0
 */
final class JobRunner {

	/**
	 * The action every SEOCart job fires. Its one argument is the stored job; it is not a public hook.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const HOOK = 'seocart_job';

	/**
	 * After how long a queued job that came due while jobs were paused runs again, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAUSED_DELAY_SECONDS = 900;

	/**
	 * How many due actions runDue() claims at once.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const CLAIM_SIZE = 10;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The registered handlers.
	 *
	 * @since 0.1.0
	 *
	 * @var JobHandlers
	 */
	private JobHandlers $handlers;

	/**
	 * Stores retries and continuations.
	 *
	 * @since 0.1.0
	 *
	 * @var ActionSchedulerQueue
	 */
	private ActionSchedulerQueue $queue;

	/**
	 * The correlation id each job runs under.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Mints the correlation id of a recurring run.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

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
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Creates the runner. Sends nothing and registers nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param JobHandlers          $handlers    The registered handlers.
	 * @param ActionSchedulerQueue $queue       Stores retries and continuations.
	 * @param CorrelationId        $correlation The correlation id each job runs under.
	 * @param IdGenerator          $ids         Mints the correlation id of a recurring run.
	 * @param callable             $report      Receives a report code (string) and its context (array).
	 * @param callable|null        $isPaused    Optional. Returns true while jobs are paused, for
	 *                                          example in Safe Mode. Default null, never paused.
	 * @param callable|null        $clock       Optional. Returns a monotonic time in nanoseconds (int).
	 *                                          Default null, which uses hrtime().
	 */
	public function __construct( JobHandlers $handlers, ActionSchedulerQueue $queue, CorrelationId $correlation, IdGenerator $ids, callable $report, ?callable $isPaused = null, ?callable $clock = null ) {
		$this->handlers    = $handlers;
		$this->queue       = $queue;
		$this->correlation = $correlation;
		$this->ids         = $ids;
		$this->report      = $report;
		$this->isPaused    = $isPaused ?? static fn(): bool => false;
		$this->clock       = $clock ?? static fn(): int => (int) hrtime( true );
	}

	/**
	 * Runs one job. The callback of HOOK.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the stored job cannot be read, names no registered handler,
	 *                           or failed for the last time. Action Scheduler records the action
	 *                           as failed, with this message in its log.
	 *
	 * @param mixed $stored The stored job, as the hook passes it.
	 */
	public function run( mixed $stored ): void {
		try {
			$envelope = JobEnvelope::fromStored( $stored );
		} catch ( \UnexpectedValueException $unreadable ) {
			$this->reportSafely( ReportCode::Unreadable, array( 'message' => $unreadable->getMessage() ) );

			throw new \RuntimeException( ReportCode::Unreadable->value . ': ' . $unreadable->getMessage(), 0, $unreadable );
		}

		$name = $envelope->job->handler;

		if ( ! $this->handlers->has( $name ) ) {
			$this->reportSafely( ReportCode::UnknownHandler, array( 'handler' => $name ) );

			throw new \RuntimeException( ReportCode::UnknownHandler->value . ': no job handler is registered as ' . $name );
		}

		if ( ( $this->isPaused )() ) {
			$this->pause( $envelope );

			return;
		}

		try {
			$again = $this->correlation->scoped(
				$envelope->correlationId ?? $this->ids->generate(),
				fn(): ?int => $this->handlers->handler( $name )->handle( $envelope->job->payload )
			);
		} catch ( \Throwable $failure ) {
			$this->fail( $envelope, $failure );

			return;
		}

		if ( null !== $again && null === $envelope->every ) {
			$this->queue->requeue( $envelope, max( 0, $again ) );
		}
	}

	/**
	 * Runs the plugin's due jobs until none is due or the time budget is spent.
	 *
	 * Runs outside any transaction. When nothing has registered HOOK in this request, the runner
	 * registers itself, so a claimed job cannot fail for lack of a callback.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When Action Scheduler is not initialised yet.
	 *
	 * @param int    $budgetSeconds How long to keep starting jobs, 1 second or more. A job started
	 *                              before the budget ran out runs to its end.
	 * @param string $context       The name Action Scheduler's log gives the runner, for example `WP CLI`.
	 * @return array{ran: int, exhausted: bool, paused: bool, custom_store: string|null} How many jobs ran,
	 *         whether the budget ran out, whether nothing ran because jobs are paused, and the class of
	 *         the store another plugin chose when nothing ran because of it (reported as
	 *         `jobs.custom_store`).
	 */
	public function runDue( int $budgetSeconds, string $context ): array {
		if ( ( $this->isPaused )() ) {
			return array(
				'ran'          => 0,
				'exhausted'    => false,
				'paused'       => true,
				'custom_store' => null,
			);
		}

		if ( ! has_action( self::HOOK ) ) {
			add_action( self::HOOK, array( $this, 'run' ) );
		}

		if ( 0 === did_action( 'action_scheduler_init' ) ) {
			throw new \LogicException( 'Jobs run once Action Scheduler is initialised; wait for the action_scheduler_init action.' );
		}

		$custom = $this->queue->customStore();

		if ( null !== $custom ) {
			$this->reportSafely( ReportCode::CustomStore, array( 'store' => $custom ) );

			return array(
				'ran'          => 0,
				'exhausted'    => false,
				'paused'       => false,
				'custom_store' => $custom,
			);
		}

		/*
		 * The claims are made on a table store of the runner's own. The library keeps the hook and
		 * group of a claim on the store that made it, so claiming through its shared store would
		 * limit every later claim in this request, the library's own runner's included, to the
		 * plugin's jobs. And the plugin's jobs are always in the tables: while the library still
		 * moves its oldest actions out of the posts table, its shared store claims from both, and
		 * refuses a group the posts table has never held.
		 */
		$deadline  = ( $this->clock )() + max( 1, $budgetSeconds ) * self::NANOSECONDS;
		$store     = new \ActionScheduler_DBStore();
		$runner    = \ActionScheduler::runner();
		$monitor   = new \ActionScheduler_FatalErrorMonitor( $store );
		$ran       = 0;
		$exhausted = false;

		while ( ! $exhausted ) {
			if ( ( $this->clock )() >= $deadline ) {
				$exhausted = true;

				break;
			}

			try {
				$claim = $store->stake_claim( self::CLAIM_SIZE, null, array( self::HOOK ), JobQueue::GROUP );
			} catch ( \InvalidArgumentException $noGroupYet ) {
				// The library refuses a claim on a group that has never held an action: nothing is due.
				break;
			}

			$ids = $claim->get_actions();

			if ( array() === $ids ) {
				$store->release_claim( $claim );

				break;
			}

			$monitor->attach( $claim );

			foreach ( $ids as $id ) {
				if ( ( $this->clock )() >= $deadline ) {
					$exhausted = true;

					break;
				}

				// Another runner took over the claim after it lapsed.
				if ( $claim->get_id() !== $store->get_claim_id( $id ) ) {
					break;
				}

				$runner->process_action( $id, $context );

				++$ran;
			}

			$store->release_claim( $claim );
			$monitor->detach();
		}

		return array(
			'ran'          => $ran,
			'exhausted'    => $exhausted,
			'paused'       => false,
			'custom_store' => null,
		);
	}

	/**
	 * Keeps Action Scheduler rescheduling the plugin's recurring jobs however often they fail.
	 *
	 * The callback of the library's `action_scheduler_recurring_action_is_consistently_failing`
	 * filter (Action Scheduler 3.6.0 and newer), added by a failing recurring run just before it
	 * rethrows, so an idle request pays nothing. The library stops rescheduling a recurring
	 * action once the last few actions of its hook have all failed. Every SEOCart job shares
	 * HOOK, so one-off failures count against the recurring jobs too, and a sweep that fails for
	 * a while, the outbox retention job say, would stop for good. Its failures are recorded and
	 * counted instead (`jobs.failed`), and it keeps running. Another hook's verdict is untouched.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $failing Whether the library judged the action consistently failing.
	 * @param mixed $action  The action, an ActionScheduler_Action.
	 * @return bool False for the plugin's actions; the library's verdict for any other.
	 */
	public static function keepRescheduling( mixed $failing, mixed $action ): bool {
		return $action instanceof \ActionScheduler_Action && self::HOOK === $action->get_hook() ? false : (bool) $failing;
	}

	/**
	 * Handles a job that came due while jobs are paused.
	 *
	 * @since 0.1.0
	 *
	 * @param JobEnvelope $envelope The job.
	 */
	private function pause( JobEnvelope $envelope ): void {
		$recurring = null !== $envelope->every;

		if ( ! $recurring ) {
			$this->queue->requeue( $envelope, self::PAUSED_DELAY_SECONDS );
		}

		$this->reportSafely(
			ReportCode::Paused,
			array(
				'handler'  => $envelope->job->handler,
				'key'      => $envelope->job->uniqueKey,
				'outcome'  => $recurring ? 'skipped' : 'requeued',
				'retry_in' => $recurring ? null : self::PAUSED_DELAY_SECONDS,
			)
		);
	}

	/**
	 * Retries a failed job, or records its last failure.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When the job failed for the last time.
	 *
	 * @param JobEnvelope $envelope The job that failed.
	 * @param \Throwable  $failure  What it threw.
	 */
	private function fail( JobEnvelope $envelope, \Throwable $failure ): void {
		$name    = $envelope->job->handler;
		$context = array(
			'handler'        => $name,
			'key'            => $envelope->job->uniqueKey,
			'attempt'        => $envelope->attempt,
			'correlation_id' => $envelope->correlationId,
			'exception'      => get_class( $failure ),
			'message'        => $failure->getMessage(),
		);

		if ( null === $envelope->every && $envelope->attempt < $this->handlers->maxAttempts( $name ) ) {
			$delay = Backoff::seconds( $envelope->attempt );

			$this->queue->requeue( $envelope->nextAttempt(), $delay );
			$this->reportSafely( ReportCode::RetryScheduled, $context + array( 'retry_in' => $delay ) );

			return;
		}

		$this->reportSafely( ReportCode::Failed, $context );

		if ( null !== $envelope->every ) {
			add_filter( 'action_scheduler_recurring_action_is_consistently_failing', array( self::class, 'keepRescheduling' ), 10, 2 );
		}

		throw new \RuntimeException(
			sprintf( '%s: %s failed on attempt %d: %s: %s', ReportCode::Failed->value, $name, $envelope->attempt, get_class( $failure ), $failure->getMessage() ),
			0,
			$failure
		);
	}

	/**
	 * Reports. A reporter that throws loses its report, never the job's outcome.
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
