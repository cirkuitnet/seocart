<?php
/**
 * Tests how jobs run: on WP-Cron, from the command, with retries, continuations and pauses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Events\Backoff;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Jobs\ReportCode;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;
use SEOCart\Tests\Support\Jobs\RecurringJob;

/**
 * A job runs once per run, under its request's correlation id, and ends done, continued, retried or failed.
 *
 * Each test names its planted violation, in src/Platform/Jobs/JobRunner.php.
 *
 * @since 0.1.0
 */
final class JobRunnerTest extends JobsTestCase {

	/**
	 * Tests that a job runs on WP-Cron, through Action Scheduler's own queue runner, under the correlation id of the request that queued it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_job_runs_on_wp_cron_under_the_correlation_id_of_the_request_that_queued_it(): void {
		$this->correlation->accept( SequentialIdGenerator::nth( 5 ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 7 ) ) );

		$this->assertNotFalse( wp_next_scheduled( 'action_scheduler_run_queue', array( 'WP Cron' ) ), 'Action Scheduler schedules its WP-Cron runner; WP-Cron is the first trigger.' );

		$this->correlation->accept( SequentialIdGenerator::nth( 6 ) );

		// What WP-Cron does when the event comes due.
		do_action( 'action_scheduler_run_queue', 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's WP-Cron hook, fired as WP-Cron fires it.

		$action = $this->actions()[0];

		$this->assertCount( 1, RecordingJob::$runs );
		$this->assertSame( array( 'order_id' => 7 ), RecordingJob::$runs[0]['payload'] );
		$this->assertSame( SequentialIdGenerator::nth( 5 ), RecordingJob::$runs[0]['correlation_id'], 'The job runs under the id of the request that queued it.' );
		$this->assertSame( SequentialIdGenerator::nth( 6 ), $this->correlation->current(), 'The id in force is put back after the job.' );
		$this->assertSame( 'complete', $action['status'] );
		$this->assertStringContainsString( 'WP Cron', $this->lastLog( $action['id'] ) );
	}

	/**
	 * Tests that `jobs run` runs the plugin's due jobs, and only the plugin's.
	 *
	 * Planted violation: in runDue(), claim with `$store->stake_claim( self::CLAIM_SIZE )`; the
	 * other plugin's due action runs too.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_trigger_runs_only_the_plugins_due_jobs(): void {
		// No recurring handler, whose first run the command would schedule and run too.
		$this->wire( array( RecordingJob::class ) );

		$ran = 0;

		add_action(
			self::OTHER_HOOK,
			static function () use ( &$ran ): void {
				++$ran;
			}
		);

		$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		$this->queue->schedule( new Job( RecordingJob::NAME, array( 'later' => true ) ), new \DateTimeImmutable( '+1 hour' ) );
		$this->otherPluginAction();

		$result = $this->triggers->command( 30 );

		$this->assertSame( 1, $result['ran'] );
		$this->assertFalse( $result['exhausted'] );
		$this->assertCount( 1, RecordingJob::$runs, 'Only the due job runs.' );
		$this->assertSame( 0, $ran, 'Another plugin\'s action must not run from SEOCart\'s trigger.' );
		$this->assertSame( array( 'pending' ), array_column( $this->actions( self::OTHER_GROUP ), 'status' ) );
		$this->assertSame( array( 'complete', 'pending' ), array_column( $this->actions(), 'status' ) );

		$this->otherPluginAction();
		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		// A later claim in the same request is not limited to the plugin's group.
		$this->runLibraryQueue();

		$this->assertSame( 2, $ran, 'The library\'s own runner must still see every group after SEOCart ran its jobs.' );
	}

	/**
	 * Tests that a failing job is retried after the backoff, and lands in failed, counted, once its attempts are spent.
	 *
	 * Planted violation: in fail(), `return;` instead of throwing after the last attempt; the last
	 * action is complete, not failed, and the report counts no failure.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_job_is_retried_and_lands_in_failed_when_its_attempts_are_spent(): void {
		RecordingJob::$plan = array( 'throw', 'throw', 'throw' );

		$this->correlation->accept( SequentialIdGenerator::nth( 9 ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 3 ), 'outbox:3:test.recording' ) );
		$this->runner->runDue( 30, 'test' );

		$actions = $this->actions();

		$this->assertSame( array( 'complete', 'pending' ), array_column( $actions, 'status' ) );
		$this->assertEqualsWithDelta( Backoff::seconds( 1 ), $actions[1]['due_in'], 5 );

		$retry = self::envelopeOf( $actions[1] );

		$this->assertSame( 2, $retry->attempt );
		$this->assertSame( 'outbox:3:test.recording', $retry->job->uniqueKey );
		$this->assertSame( SequentialIdGenerator::nth( 9 ), $retry->correlationId );

		$this->makeDue( $actions[1]['id'] );
		$this->runner->runDue( 30, 'test' );

		$actions = $this->actions();

		$this->assertEqualsWithDelta( Backoff::seconds( 2 ), $actions[2]['due_in'], 5 );

		$this->makeDue( $actions[2]['id'] );
		$this->runner->runDue( 30, 'test' );

		$actions = $this->actions();
		$report  = $this->queue->report();

		$this->assertSame( array( 'complete', 'complete', 'failed' ), array_column( $actions, 'status' ), 'The last attempt lands in failed, and nothing more is queued.' );
		$this->assertCount( 3, RecordingJob::$runs );
		$this->assertStringContainsString( 'jobs.failed: test.recording failed on attempt 3', $this->lastLog( $actions[2]['id'] ) );
		$this->assertSame( 1, $report->failed );
		$this->assertSame( RecordingJob::NAME, $report->failingHandlers[0]['handler'] );
		$this->assertStringContainsString( 'Planned failure of run 3', $report->failingHandlers[0]['last_error'] );
		$this->assertSame( array( ReportCode::RetryScheduled->value, ReportCode::RetryScheduled->value, ReportCode::Failed->value ), $this->reportedCodes() );
		$this->assertTrue( $this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 3 ), 'outbox:3:test.recording' ) ), 'A job whose last attempt failed may be queued again.' );
	}

	/**
	 * Tests that a job that asks to run again continues as a new job with the same key, and a recurring one does not.
	 *
	 * Planted violation: in run(), drop the requeue after the handler; the migration-style job
	 * stops after its first slice.
	 *
	 * @since 0.1.0
	 */
	public function test_a_job_that_asks_to_run_again_continues_as_a_new_job_with_the_same_key(): void {
		RecordingJob::$plan = array( 5, 0, 'ok' );

		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'slice' ) );
		$this->runner->runDue( 30, 'test' );

		$actions = $this->actions();

		$this->assertCount( 1, RecordingJob::$runs );
		$this->assertSame( array( 'complete', 'pending' ), array_column( $actions, 'status' ) );
		$this->assertEqualsWithDelta( 5, $actions[1]['due_in'], 3 );
		$this->assertSame( 'slice', self::envelopeOf( $actions[1] )->job->uniqueKey );
		$this->assertSame( 1, self::envelopeOf( $actions[1] )->attempt, 'A continuation is not a retry.' );
		$this->assertFalse( $this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'slice' ) ), 'The continuation holds the key.' );

		$this->makeDue( $actions[1]['id'] );
		$this->runner->runDue( 30, 'test' );

		$this->assertCount( 3, RecordingJob::$runs, 'The continuation asked to go on at once, and ran in the same pass.' );
		$this->assertSame( array( 'complete', 'complete', 'complete' ), array_column( $this->actions(), 'status' ) );

		$this->queue->ensureRecurring();
		$this->runner->runDue( 30, 'test' );

		$this->assertCount( 1, RecurringJob::$runs );
		$this->assertSame( array( 'complete', 'pending' ), array_column( array_slice( $this->actions(), 3 ), 'status' ), 'A recurring run\'s delay is ignored: the only new action is the library\'s next occurrence.' );
		$this->assertSame( '[{"h":"test.recurring","r":60}]', $this->actions()[4]['args'] );
	}

	/**
	 * Tests that nothing runs while jobs are paused: a queued job is put back for later, a recurring run skipped.
	 *
	 * Planted violation: in run(), drop the isPaused() check; the handlers run.
	 *
	 * @since 0.1.0
	 */
	public function test_nothing_runs_while_jobs_are_paused(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'paused-job' ) );
		$this->queue->ensureRecurring();

		$this->paused = true;

		$this->assertTrue( $this->runner->runDue( 30, 'test' )['paused'], 'The plugin\'s own triggers run nothing while paused.' );

		// WP-Cron still fires the plugin's actions; the runner must not run them.
		$this->runLibraryQueue();

		$this->assertSame( array(), RecordingJob::$runs );
		$this->assertSame( array(), RecurringJob::$runs );

		$requeued = array_values( array_filter( $this->actions(), static fn( array $action ): bool => 'pending' === $action['status'] && str_contains( $action['args'], 'paused-job' ) ) );

		$this->assertCount( 1, $requeued );
		$this->assertEqualsWithDelta( JobRunner::PAUSED_DELAY_SECONDS, $requeued[0]['due_in'], 5 );
		$this->assertSame( 1, self::envelopeOf( $requeued[0] )->attempt, 'A paused job is put back unchanged.' );
		$this->assertSame( array( ReportCode::Paused->value, ReportCode::Paused->value ), $this->reportedCodes() );
		$this->assertEqualsCanonicalizing( array( 'requeued', 'skipped' ), array_column( array_column( $this->reports, 'context' ), 'outcome' ) );
	}

	/**
	 * Tests that a recurring run gets a correlation id of its own, and fails without a retry.
	 *
	 * @since 0.1.0
	 */
	public function test_a_recurring_run_gets_its_own_correlation_id_and_is_never_retried(): void {
		RecurringJob::$fail = true;

		$this->correlation->accept( SequentialIdGenerator::nth( 3 ) );
		$this->queue->ensureRecurring();
		$this->runner->runDue( 30, 'test' );

		$this->assertSame( array( SequentialIdGenerator::nth( 1000 ) ), RecurringJob::$runs, 'A recurring run is caused by no request: it gets a fresh id.' );
		$this->assertSame( array( 'failed', 'pending' ), array_column( $this->actions(), 'status' ), 'The failed run is recorded; the next occurrence is the retry.' );
		$this->assertSame( array( ReportCode::Failed->value ), $this->reportedCodes() );
	}

	/**
	 * Tests that a recurring job that fails time after time keeps its next run.
	 *
	 * Action Scheduler stops rescheduling a recurring action once the last few actions of its
	 * hook (five by default) have all failed, and every SEOCart job shares one hook.
	 *
	 * Planted violation: in fail(), do not add the keepRescheduling() filter; after the fifth
	 * failure there is no next run.
	 *
	 * @since 0.1.0
	 */
	public function test_a_recurring_job_that_keeps_failing_keeps_its_next_run(): void {
		RecurringJob::$fail = true;

		$this->queue->ensureRecurring();

		for ( $failure = 1; $failure <= 6; $failure++ ) {
			$next = array_values( array_filter( $this->actions(), static fn( array $action ): bool => 'pending' === $action['status'] ) );

			$this->assertCount( 1, $next, sprintf( 'After %d failures the recurring job must still have its next run.', $failure - 1 ) );

			// Each run due a little later than the one before, as the library's schedule would have it.
			$this->reschedule( $next[0]['id'], $failure - 100 );
			$this->runner->runDue( 30, 'test' );
		}

		$this->assertCount( 6, RecurringJob::$runs );
		$this->assertSame( array_merge( array_fill( 0, 6, 'failed' ), array( 'pending' ) ), array_column( $this->actions(), 'status' ) );
		$this->assertTrue( JobRunner::keepRescheduling( true, new \ActionScheduler_Action( 'another_plugin_task' ) ), 'Another hook keeps the library\'s verdict.' );
	}

	/**
	 * Tests that a stored job that cannot be read, or names no registered handler, fails at once.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unreadable_or_unknown_job_fails_at_once(): void {
		$this->storeAction( JobRunner::HOOK, array( array( 'h' => 'Not a handler' ) ), JobQueue::GROUP );
		$this->storeAction( JobRunner::HOOK, array( array( 'h' => 'test.nobody' ) ), JobQueue::GROUP );

		$this->runner->runDue( 30, 'test' );

		$this->assertSame( array( 'failed', 'failed' ), array_column( $this->actions(), 'status' ) );
		$this->assertSame( array( ReportCode::Unreadable->value, ReportCode::UnknownHandler->value ), $this->reportedCodes() );
	}

	/**
	 * Tests that the runner stops starting jobs once its budget is spent, and leaves the rest waiting.
	 *
	 * Planted violation: in runDue(), drop the deadline check inside the loop over claimed ids; all three jobs run.
	 *
	 * @since 0.1.0
	 */
	public function test_the_runner_stops_starting_jobs_when_its_budget_is_spent(): void {
		$calls = 0;

		// The deadline, the loop's first check and the first job's check read 0; later reads are past the one-second budget.
		$this->wire(
			array( RecordingJob::class, RecurringJob::class ),
			null,
			static function () use ( &$calls ): int {
				return ++$calls <= 3 ? 0 : 2000000000;
			}
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		}

		$result = $this->runner->runDue( 1, 'test' );

		$this->assertSame( 1, $result['ran'] );
		$this->assertTrue( $result['exhausted'] );
		$this->assertSame( array( 'complete', 'pending', 'pending' ), array_column( $this->actions(), 'status' ) );
		$this->assertTrue( $this->queue->hasWork(), 'The released jobs are due again, for the next runner.' );
	}
}
