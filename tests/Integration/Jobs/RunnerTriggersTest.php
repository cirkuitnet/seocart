<?php
/**
 * Tests the plugin's own runner triggers: the admin tick and `wp seocart jobs`
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;
use SEOCart\Tests\Support\Jobs\RecurringJob;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The test ages outbox rows directly.

/**
 * The tick costs one query when nothing is due, runs due jobs under its lock, and never runs while paused; the command drains and runs.
 *
 * Each test names its planted violation, in src/Platform/Jobs/RunnerTriggers.php.
 *
 * @since 0.1.0
 */
final class RunnerTriggersTest extends JobsTestCase {

	/**
	 * The lines the command printed.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	/**
	 * Tests that an admin tick with nothing due, and every recurring run in place, costs one query, and takes no lock.
	 *
	 * Planted violation: in tick(), drop `|| ! $this->queue->hasWork()`; the tick then takes the
	 * lock and stakes a claim on every admin request.
	 *
	 * @since 0.1.0
	 */
	public function test_a_tick_with_nothing_due_costs_one_query(): void {
		$this->queue->schedule( new Job( RecordingJob::NAME ), new \DateTimeImmutable( '+1 hour' ) );
		$this->queue->ensureRecurring();
		$this->reschedule( (int) array_column( $this->actions(), 'id' )[1], HOUR_IN_SECONDS );

		$log = $this->captureQueries(
			function (): void {
				$this->triggers->tick();
			}
		);

		$this->assertQueryCount( 1, $log, 'An admin tick with nothing due' );
		$this->assertSame( array(), RecordingJob::$runs );
	}

	/**
	 * Tests that a tick runs the due jobs, unless another tick holds its lock or jobs are paused.
	 *
	 * Planted violation: in tick(), call runDue() directly instead of inside withLock(); the job
	 * runs while the other request holds the tick lock.
	 *
	 * @since 0.1.0
	 */
	public function test_a_tick_runs_due_jobs_unless_another_tick_holds_the_lock_or_jobs_are_paused(): void {
		// No recurring handler, whose first run the tick would schedule and run too.
		$this->wire( array( RecordingJob::class ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		$second = new SecondDatabase( $this->reporter() );
		$lease  = ( new LockService( $second->db(), LockMode::Table, $this->sleeper() ) )->acquire( RunnerTriggers::TICK_LOCK, 60, 0 );

		$this->triggers->tick();

		$this->assertSame( array(), RecordingJob::$runs, 'Another request\'s tick holds the lock: this one runs nothing.' );

		$lease->release();
		$second->close();

		$this->paused = true;
		$this->triggers->tick();

		$this->assertSame( array(), RecordingJob::$runs, 'Paused: nothing runs.' );

		$this->paused = false;
		$this->triggers->tick();

		$this->assertCount( 1, RecordingJob::$runs );
		$this->assertSame( array( 'complete' ), array_column( $this->actions(), 'status' ) );
		$this->assertSame( array(), $this->reports, 'The tick reports nothing when all went well.' );
	}

	/**
	 * Tests that the tick and the command schedule a recurring job again when its next run was lost.
	 *
	 * A fatal error in a recurring run ends the process before the library schedules the next
	 * one, and a cancellation removes it; either way the job would never run again.
	 *
	 * Planted violations: in tick(), drop the call of ensureRecurring(); the lost run stays lost.
	 * In ActionSchedulerQueue::hasWork(), return `$work['due']` alone; the tick sees no work. In
	 * command(), drop the call of ensureRecurring(); the command runs nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tick_and_the_command_bring_back_a_lost_recurring_run(): void {
		$this->queue->ensureRecurring();
		$this->queue->cancel( RecurringJob::NAME );

		$this->assertSame( array( 'canceled' ), array_column( $this->actions(), 'status' ), 'The recurring run is lost.' );

		$this->triggers->tick();

		$this->assertSame( array( 'canceled', 'complete', 'pending' ), array_column( $this->actions(), 'status' ), 'The tick scheduled it again, and ran it, since a new schedule starts now.' );
		$this->assertCount( 1, RecurringJob::$runs );

		$this->queue->cancel( RecurringJob::NAME );

		$this->assertSame( JobsCommand::EXIT_OK, $this->command()->run( array( 'run' ), array( 'budget' => '30' ) ) );
		$this->assertSame( array( 'canceled', 'complete', 'canceled', 'complete', 'pending' ), array_column( $this->actions(), 'status' ), 'The command scheduled it again too.' );
	}

	/**
	 * Tests that the library's WP-Cron hooks bring back a recurring run lost to a fatal error, at one query when nothing is lost.
	 *
	 * When a run dies of a fatal error, the library's fatal-error monitor marks it failed and
	 * schedules no next run; on a site that runs only WP-Cron, no command or tick repairs it.
	 * The hooks are added as the kernel adds them, and hold nothing else, so the library's own
	 * runner does not run the restored job in the test.
	 *
	 * Planted violation: in repairRecurring(), return before ensureRecurring(); the run stays lost.
	 *
	 * @since 0.1.0
	 */
	public function test_the_librarys_cron_hooks_bring_back_a_recurring_run_lost_to_a_fatal_error(): void {
		foreach ( RunnerTriggers::CRON_REPAIR_HOOKS as $hook => $priority ) {
			remove_all_actions( $hook );
			add_action( $hook, array( $this->triggers, 'repairRecurring' ), $priority );
		}

		$this->queue->ensureRecurring();

		$lost = (int) $this->actions()[0]['id'];

		// What the library's fatal-error monitor does when the run dies: failed, and no next run.
		\ActionScheduler::store()->log_execution( $lost );
		\ActionScheduler::store()->mark_failure( $lost );

		$this->assertSame( array( 'failed' ), array_column( $this->actions(), 'status' ), 'The recurring job has no next run.' );

		do_action( 'action_scheduler_run_queue', 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The library's own hook, fired as its queue runner fires it.

		$this->assertSame( array( 'failed', 'pending' ), array_column( $this->actions(), 'status' ), 'The queue run scheduled it again.' );
		$this->assertSame( '[{"h":"test.recurring","r":60}]', $this->actions()[1]['args'] );

		$this->queue->cancel( RecurringJob::NAME );

		do_action( 'action_scheduler_ensure_recurring_actions' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The library's own hook, fired as its queue runner fires it.

		$this->assertSame( array( 'failed', 'canceled', 'pending' ), array_column( $this->actions(), 'status' ), 'The daily hook scheduled it again too.' );
		$this->assertQueryCount( 1, $this->captureQueries( static fn() => do_action( 'action_scheduler_run_queue', 'WP Cron' ) ), 'A queue run with nothing lost' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * Tests that a tick never throws: a failure is reported.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_tick_is_reported_not_thrown(): void {
		global $wpdb;

		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		// The tick lock table is gone, so taking the lock fails.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->db->table( 'locks' ) ) );

		$this->triggers->tick();

		$this->assertSame( array( 'jobs.tick_failed' ), array_values( array_intersect( $this->reportedCodes(), array( 'jobs.tick_failed' ) ) ) );
		$this->assertSame( array(), RecordingJob::$runs );
	}

	/**
	 * Tests that `wp seocart jobs run` drains the outbox without pruning it, runs the due jobs, and says so.
	 *
	 * Planted violation: in command(), drain with `prune: true`; the dispatched row older than
	 * the retention period is deleted by the drain.
	 *
	 * @since 0.1.0
	 */
	public function test_the_run_command_drains_the_outbox_without_pruning_and_runs_due_jobs(): void {
		$this->wire( array( RecordingJob::class ) );

		$publisher = new Publisher( $this->db, $this->outbox, new HookBridge( $this->reporter() ), new EventCatalog( array( ThingHappened::class, ThingNoticed::class ) ), $this->correlation, new RecordingWake() );

		$this->db->transaction(
			static function () use ( $publisher ): void {
				$publisher->publish( new ThingHappened( 1, 'old' ) );
				$publisher->publish( new ThingHappened( 2, 'new' ) );
			}
		);

		global $wpdb;

		$table = $this->db->table( 'outbox' );

		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 30 DAY WHERE aggregate_id = 1", $table ) );

		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		$command = $this->command();

		$this->assertSame( JobsCommand::EXIT_OK, $command->run( array( 'run' ), array( 'budget' => '30' ) ) );
		$this->assertSame( array( 'Outbox: dispatched 1, retried 0, parked as failed 0.', 'Jobs run: 1.' ), $this->printed() );
		$this->assertSame( '2', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'The drain of the command must not prune: the retention job does.' );
		$this->assertSame( Outbox::DISPATCHED, (string) $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE aggregate_id = 2', $table ) ) );

		$this->paused = true;
		$this->lines  = array();

		$this->assertSame( JobsCommand::EXIT_PAUSED, $command->run( array( 'run' ), array() ) );
		$this->assertSame( array( 'Background jobs are paused, so nothing ran.' ), $this->printed() );
	}

	/**
	 * Tests `wp seocart jobs status`: the runtime in control, the counts, the check-in and the failing handlers.
	 *
	 * @since 0.1.0
	 */
	public function test_the_status_command_prints_the_state_of_the_jobs_and_the_runner(): void {
		$command = $this->command();

		$this->assertSame( JobsCommand::EXIT_OK, $command->run( array( 'status' ), array() ) );
		$this->assertSame( 'Action Scheduler in control: 4.2.0 from SEOCart (registered: 4.2.0)', $this->printed()[0] );
		$this->assertSame( array( 'Due: 0', 'Pending: 0', 'In progress: 0', 'Failed: 0', 'Last runner check-in: never', 'Failing handlers: none' ), array_slice( $this->printed(), 1 ) );

		RecordingJob::$plan = array( 'throw', 'throw', 'throw' );

		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		for ( $i = 0; $i < 3; $i++ ) {
			$this->runner->runDue( 30, 'test' );

			$waiting = array_values( array_filter( $this->actions(), static fn( array $action ): bool => 'pending' === $action['status'] ) );

			if ( array() !== $waiting ) {
				$this->makeDue( $waiting[0]['id'] );
			}
		}

		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		$this->lines = array();

		$command->run( array( 'status' ), array() );

		$this->assertMatchesRegularExpression( '/^Due: 1 \(the oldest has waited \d+ seconds\)$/', $this->printed()[1] );
		$this->assertSame( array( 'Pending: 1', 'In progress: 0', 'Failed: 1' ), array_slice( $this->printed(), 2, 3 ) );
		$this->assertMatchesRegularExpression( '/^Last runner check-in: \d+ seconds ago$/', $this->printed()[5] );
		$this->assertSame( 'Failing handlers:', $this->printed()[6] );
		$this->assertStringStartsWith( '  test.recording: 1 failed, last error: ', $this->printed()[7] );
		$this->assertStringContainsString( 'Planned failure of run 3', $this->printed()[7] );

		$this->lines = array();

		$this->assertSame( JobsCommand::EXIT_FAILED, $command->run( array( 'purge' ), array() ) );
		$this->assertSame( array( 'Usage: wp seocart jobs <run|status> [--budget=<seconds>]' ), $this->printed() );
	}

	/**
	 * Builds the command, printing into `$this->lines`.
	 *
	 * @since 0.1.0
	 *
	 * @return JobsCommand The command.
	 */
	private function command(): JobsCommand {
		$this->lines = array();

		return new JobsCommand(
			$this->triggers,
			$this->queue,
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
	}

	/**
	 * Returns what the command printed since it was built or the lines were cleared.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The lines.
	 */
	private function printed(): array {
		return $this->lines;
	}
}
