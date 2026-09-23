<?php
/**
 * Tests the job queue on the real Action Scheduler
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobEnvelope;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;
use SEOCart\Tests\Support\Jobs\RecurringJob;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The report test ages an action directly.

/**
 * What the queue stores, when it refuses, how it keeps a keyed job unique, and that it touches only its own group.
 *
 * Each test names its planted violation, in src/Platform/Jobs/ActionSchedulerQueue.php.
 *
 * @since 0.1.0
 */
final class ActionSchedulerQueueTest extends JobsTestCase {

	/**
	 * Tests that a queued job is one waiting action of the plugin's hook and group, carrying the request's correlation id.
	 *
	 * @since 0.1.0
	 */
	public function test_a_queued_job_is_one_waiting_action_carrying_the_request_correlation_id(): void {
		$this->correlation->accept( SequentialIdGenerator::nth( 77 ) );

		$this->assertTrue( $this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 42 ) ) ) );

		$actions = $this->actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( JobRunner::HOOK, $actions[0]['hook'] );
		$this->assertSame( 'pending', $actions[0]['status'] );
		$this->assertLessThanOrEqual( 0, $actions[0]['due_in'], 'A job queued with enqueue() is due at once.' );

		$stored = self::envelopeOf( $actions[0] );

		$this->assertSame( RecordingJob::NAME, $stored->job->handler );
		$this->assertSame( array( 'order_id' => 42 ), $stored->job->payload );
		$this->assertSame( SequentialIdGenerator::nth( 77 ), $stored->correlationId );
		$this->assertSame( 1, $stored->attempt );
		$this->assertSame( array(), $this->actions( self::OTHER_GROUP ) );
	}

	/**
	 * Tests that nothing is queued or cancelled inside a transaction.
	 *
	 * Planted violation: delete the depth check at the top of assertReady(); the job is stored,
	 * and the rollback of the unit of work does not remove it.
	 *
	 * @since 0.1.0
	 */
	public function test_nothing_is_queued_or_cancelled_inside_a_transaction(): void {
		$refused = array();

		foreach ( array( 'enqueue', 'cancel', 'ensureRecurring' ) as $operation ) {
			try {
				$this->db->transaction(
					function () use ( $operation ): void {
						match ( $operation ) {
							'enqueue'         => $this->queue->enqueue( new Job( RecordingJob::NAME ) ),
							'cancel'          => $this->queue->cancel(),
							'ensureRecurring' => $this->queue->ensureRecurring(),
						};
					}
				);
			} catch ( \Throwable $thrown ) {
				$refused[ $operation ] = get_class( $thrown ) . ': ' . $thrown->getMessage();
			}
		}

		$this->assertSame( array( 'enqueue', 'cancel', 'ensureRecurring' ), array_keys( $refused ), 'Each operation must be refused inside a transaction; these were: ' . print_r( $refused, true ) );

		foreach ( $refused as $operation => $message ) {
			$this->assertStringStartsWith( 'LogicException: Jobs are queued and cancelled outside any transaction', $message, $operation );
		}

		$this->assertSame( array(), $this->actions(), 'Nothing may be stored from inside a transaction.' );
	}

	/**
	 * Tests that a job for a handler that is not registered is refused, since it could never run.
	 *
	 * @since 0.1.0
	 */
	public function test_a_job_for_an_unregistered_handler_is_refused(): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'No job handler is registered as test.nobody' );

		$this->queue->enqueue( new Job( 'test.nobody' ) );
	}

	/**
	 * Tests that a keyed job is queued once while its latest run waits, runs or has completed, and again once it failed or was cancelled.
	 *
	 * Planted violation: in queue(), skip the latestStatus() check; the second enqueue returns
	 * true and a second action is stored.
	 *
	 * @since 0.1.0
	 */
	public function test_a_keyed_job_is_queued_once_while_its_latest_run_waits_runs_or_completed(): void {
		$job = new Job( RecordingJob::NAME, array( 'order_id' => 1 ), 'outbox:1:test.recording' );

		$this->assertTrue( $this->queue->enqueue( $job ) );
		$this->assertFalse( $this->queue->enqueue( $job ), 'The job is waiting: a redelivered event must not queue it again.' );
		$this->assertCount( 1, $this->actions() );

		$first = $this->actions()[0]['id'];

		$this->setStatus( $first, 'in-progress' );
		$this->assertFalse( $this->queue->enqueue( $job ), 'The job is running.' );

		$this->setStatus( $first, 'complete' );
		$this->assertFalse( $this->queue->enqueue( $job ), 'The job has completed.' );

		$this->setStatus( $first, 'failed' );
		$this->assertTrue( $this->queue->enqueue( $job ), 'The job failed for the last time; it may be queued again.' );

		$second = $this->actions()[1]['id'];

		$this->assertSame( 1, $this->queue->cancel( RecordingJob::NAME, 'outbox:1:test.recording' ) );
		$this->assertTrue( $this->queue->enqueue( $job ), 'The job was cancelled; it may be queued again.' );
		$this->assertSame( 'canceled', $this->actions()[1]['status'] );
		$this->assertNotSame( $first, $second );

		$this->assertTrue( $this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'outbox:12:test.recording' ) ), 'Another key is another job, even when one key starts the other.' );
		$this->assertTrue( $this->queue->enqueue( new Job( RecurringJob::NAME, array(), 'outbox:1:test.recording' ) ), 'The key is unique per handler.' );
		$this->assertTrue( $this->queue->enqueue( new Job( RecordingJob::NAME ) ) );
		$this->assertTrue( $this->queue->enqueue( new Job( RecordingJob::NAME ) ), 'A job without a key is queued every time.' );
		$this->assertCount( 7, $this->actions() );
	}

	/**
	 * Tests that the key lookup never sees another group's actions, even with identical arguments.
	 *
	 * @since 0.1.0
	 */
	public function test_the_key_lookup_never_sees_another_groups_actions(): void {
		$job = new Job( RecordingJob::NAME, array(), 'outbox:5:test.recording' );

		$this->otherPluginAction( JobRunner::HOOK, ( new JobEnvelope( $job, SequentialIdGenerator::nth( 1 ) ) )->toArguments() );

		$this->assertTrue( $this->queue->enqueue( $job ) );
		$this->assertCount( 1, $this->actions() );
	}

	/**
	 * Tests that a scheduled job waits for its time, and is counted as waiting but not due.
	 *
	 * @since 0.1.0
	 */
	public function test_a_scheduled_job_waits_for_its_time(): void {
		$this->wire( array( RecordingJob::class ) );

		$this->assertTrue( $this->queue->schedule( new Job( RecordingJob::NAME ), new \DateTimeImmutable( '+1 hour' ) ) );

		$actions = $this->actions();
		$report  = $this->queue->report();

		$this->assertCount( 1, $actions );
		$this->assertEqualsWithDelta( 3600, $actions[0]['due_in'], 5 );
		$this->assertSame( 1, $report->pending );
		$this->assertSame( 0, $report->due );
		$this->assertFalse( $this->queue->hasWork() );
	}

	/**
	 * Tests that cancelling touches only the plugin's own waiting jobs.
	 *
	 * Planted violation: in ownIds(), drop `g.slug = %s` (and its argument); the other plugin's
	 * waiting action is cancelled too.
	 *
	 * @since 0.1.0
	 */
	public function test_cancel_touches_only_the_plugins_waiting_jobs(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'key-a' ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'key-b' ) );
		$this->queue->enqueue( new Job( RecurringJob::NAME ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'key-running' ) );

		$running = $this->actions()[3]['id'];
		$other   = $this->otherPluginAction();

		$this->setStatus( $running, 'in-progress' );

		$this->assertSame( 1, $this->queue->cancel( RecordingJob::NAME, 'key-a' ) );
		$this->assertSame( array( 'canceled', 'pending', 'pending', 'in-progress' ), array_column( $this->actions(), 'status' ) );

		$this->assertSame( 1, $this->queue->cancel( RecordingJob::NAME ), 'Only key-b waits; the running job finishes.' );
		$this->assertSame( 1, $this->queue->cancel() );
		$this->assertSame( array( 'canceled', 'canceled', 'canceled', 'in-progress' ), array_column( $this->actions(), 'status' ) );

		$this->assertSame( array( 'pending' ), array_column( $this->actions( self::OTHER_GROUP ), 'status' ), 'Another plugin\'s action must never be cancelled.' );
		$this->assertSame( $other, $this->actions( self::OTHER_GROUP )[0]['id'] );

		$this->expectException( \InvalidArgumentException::class );

		$this->queue->cancel( null, 'key-a' );
	}

	/**
	 * Tests that cleanup deletes only the plugin's finished jobs past retention, in bounded batches.
	 *
	 * Planted violation: in ownIds(), drop `g.slug = %s` (and its argument); the other plugin's
	 * old completed and failed actions are deleted too. A second plant: `COMPLETE, CANCELED`
	 * replaced by `COMPLETE, CANCELED, PENDING`; the old waiting job is deleted. A third: the
	 * `job_history` policy's finished period changed to P9D in the retention catalog; the job
	 * completed 8 days ago stays, because the queue reads its periods from there.
	 *
	 * @since 0.1.0
	 */
	public function test_cleanup_deletes_only_the_plugins_finished_jobs_past_retention(): void {
		$seeds = array(
			'complete 8 days'  => array( 'complete', 8 ),
			'complete 6 days'  => array( 'complete', 6 ),
			'canceled 8 days'  => array( 'canceled', 8 ),
			'failed 91 days'   => array( 'failed', 91 ),
			'failed 89 days'   => array( 'failed', 89 ),
			'pending 100 days' => array( 'pending', 100 ),
		);
		$ids   = array();

		foreach ( $seeds as $name => $seed ) {
			$this->queue->enqueue( new Job( RecordingJob::NAME ) );

			$ids[ $name ] = (int) array_column( $this->actions(), 'id' )[ count( $ids ) ];

			$this->setStatus( $ids[ $name ], $seed[0], $seed[1] );
		}

		$otherComplete = $this->otherPluginAction();
		$otherFailed   = $this->otherPluginAction();

		$this->setStatus( $otherComplete, 'complete', 30 );
		$this->setStatus( $otherFailed, 'failed', 100 );

		$this->assertSame( 3, $this->queue->cleanup( 100 ) );

		$left = array_column( $this->actions(), 'id' );

		$this->assertSame( array( $ids['complete 6 days'], $ids['failed 89 days'], $ids['pending 100 days'] ), $left );
		$this->assertSame( array( $otherComplete, $otherFailed ), array_column( $this->actions( self::OTHER_GROUP ), 'id' ), 'Another plugin\'s actions are never deleted, whatever their age.' );
		$this->assertSame( 0, $this->queue->cleanup( 100 ) );
	}

	/**
	 * Tests that a cancelled job is kept for the retention period, counted from its cancellation.
	 *
	 * The library records no time for a cancellation, and leaves a never-run action's last
	 * attempt at a zero date, which is older than any period; cancel() records the time. A job
	 * cancelled some other way that never ran counts from when it was due.
	 *
	 * Planted violations: in cancelOwn(), skip recording the cancellation time; the job due 8 days
	 * ago and cancelled now is deleted at once. In ownIds(), age by `a.last_attempt_gmt` alone;
	 * the job the library cancelled, due 8 days ago, stays.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cancelled_job_is_kept_for_the_retention_period_from_its_cancellation(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 1 ) ) );
		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 2 ) ) );

		list( $recent, $old ) = array_column( $this->actions(), 'id' );

		$this->reschedule( $recent, -DAY_IN_SECONDS );
		$this->reschedule( $old, -8 * DAY_IN_SECONDS );

		$this->assertSame( 2, $this->queue->cancel( RecordingJob::NAME ) );

		foreach ( $this->actions() as $action ) {
			$this->assertNotNull( $action['attempted_ago'], 'The cancellation time is recorded.' );
			$this->assertLessThan( 60, (int) $action['attempted_ago'] );
		}

		$this->assertNull( $this->queue->report()->secondsSinceCheckIn, 'A cancellation is not a runner\'s check-in.' );
		$this->assertSame( 0, $this->queue->cleanup( 100 ), 'Both jobs were cancelled just now, the one due 8 days ago too.' );

		// Eight days on, the older cancellation is past retention.
		$this->setStatus( $old, 'canceled', 8 );

		$this->assertSame( 1, $this->queue->cleanup( 100 ) );
		$this->assertSame( array( $recent ), array_column( $this->actions(), 'id' ) );

		// A job the library cancelled itself, which never ran, counts from when it was due.
		$other = $this->storeAction( JobRunner::HOOK, ( new JobEnvelope( new Job( RecordingJob::NAME ), SequentialIdGenerator::nth( 5 ) ) )->toArguments(), JobQueue::GROUP );

		$this->reschedule( $other, -8 * DAY_IN_SECONDS );
		\ActionScheduler::store()->cancel_action( $other );

		$this->assertSame( 1, $this->queue->cleanup( 100 ) );
		$this->assertSame( array( $recent ), array_column( $this->actions(), 'id' ) );
	}

	/**
	 * Tests that cleanup deletes at most its limit of each kind per call.
	 *
	 * @since 0.1.0
	 */
	public function test_cleanup_is_bounded_per_call(): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->queue->enqueue( new Job( RecordingJob::NAME ) );
			$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		}

		foreach ( $this->actions() as $index => $action ) {
			$this->setStatus( $action['id'], 0 === $index % 2 ? 'complete' : 'failed', 120 );
		}

		$this->assertSame( 2, $this->queue->cleanup( 1 ), 'One finished and one failed job per call.' );
		$this->assertCount( 4, $this->actions() );
	}

	/**
	 * Tests that each recurring handler is scheduled once, and rescheduled when its interval changes.
	 *
	 * Planted violation: in scheduleRecurring(), skip the lookup of the current action; the
	 * second call schedules a second recurring action.
	 *
	 * @since 0.1.0
	 */
	public function test_recurring_handlers_are_scheduled_once_and_rescheduled_when_their_interval_changes(): void {
		$this->assertSame( array( RecurringJob::NAME ), $this->queue->ensureRecurring() );
		$this->assertSame( array(), $this->queue->ensureRecurring(), 'Already scheduled at its interval: nothing to do.' );
		$this->assertQueryCount( 1, $this->captureQueries( fn() => $this->queue->ensureRecurring() ), 'Nothing to schedule' );

		$actions = $this->actions();

		$this->assertCount( 1, $actions );
		$this->assertSame( '[{"h":"test.recurring","r":60}]', $actions[0]['args'] );
		$this->assertSame( 'pending', $actions[0]['status'] );

		RecurringJob::$every = 120;

		$this->wire( array( RecordingJob::class, RecurringJob::class ) );

		$this->assertSame( array( RecurringJob::NAME ), $this->queue->ensureRecurring() );

		$actions = $this->actions();

		$this->assertSame( array( 'canceled', 'pending' ), array_column( $actions, 'status' ) );
		$this->assertSame( '[{"h":"test.recurring","r":120}]', $actions[1]['args'] );
	}

	/**
	 * Tests that no queue-wide retention setting is ever changed, whatever the queue does.
	 *
	 * The retention filters govern every plugin's actions on the site, so setting one would change
	 * another plugin's retention. The test site loads no other plugin, so neither filter has a
	 * callback before, and none may have one after every operation of the module has run.
	 *
	 * Planted violation: `add_filter( 'action_scheduler_retention_period', static fn() => DAY_IN_SECONDS );`
	 * at the top of cleanup().
	 *
	 * @since 0.1.0
	 */
	public function test_no_queue_wide_retention_setting_is_ever_changed(): void {
		$filters = array( 'action_scheduler_retention_period', 'action_scheduler_retention_period_for_failed' );

		foreach ( $filters as $filter ) {
			$this->assertFalse( has_filter( $filter ), "Something set {$filter} before the test; the check would prove nothing." );
		}

		$this->queue->enqueue( new Job( RecordingJob::NAME, array(), 'k' ) );
		$this->queue->schedule( new Job( RecordingJob::NAME ), new \DateTimeImmutable( '+1 day' ) );
		$this->queue->ensureRecurring();
		$this->runner->runDue( 30, 'test' );
		$this->triggers->command( 30 );
		$this->triggers->tick();
		$this->queue->cancel();
		$this->queue->cleanup( 100 );
		$this->queue->report();

		foreach ( $filters as $filter ) {
			$this->assertFalse( has_filter( $filter ), "The jobs module set the queue-wide filter {$filter}." );
		}
	}

	/**
	 * Tests the report: the plugin's counts only, the oldest due job, the last check-in, the failing handlers, and the runtime.
	 *
	 * @since 0.1.0
	 */
	public function test_the_report_counts_the_plugins_jobs_and_names_the_runtime_in_control(): void {
		for ( $i = 0; $i < 6; $i++ ) {
			$this->queue->enqueue( new Job( RecordingJob::NAME ) );
		}

		$ids = array_column( $this->actions(), 'id' );

		$this->setStatus( $ids[2], 'in-progress' );
		$this->setStatus( $ids[3], 'failed', 1 );
		$this->setStatus( $ids[4], 'failed' );
		$this->setStatus( $ids[5], 'complete', 2 );

		$this->logToAction( $ids[3], 'first failure' );
		$this->logToAction( $ids[4], 'latest failure' );

		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET scheduled_date_gmt = UTC_TIMESTAMP() - INTERVAL 90 SECOND WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $ids[0] ) );

		$this->queue->schedule( new Job( RecordingJob::NAME ), new \DateTimeImmutable( '+1 hour' ) );
		$this->setStatus( $this->otherPluginAction(), 'failed' );
		$this->otherPluginAction();

		$report = $this->queue->report();

		$this->assertSame( 2, $report->due );
		$this->assertSame( 3, $report->pending );
		$this->assertSame( 1, $report->inProgress );
		$this->assertSame( 2, $report->failed );
		$this->assertGreaterThanOrEqual( 89, (int) $report->oldestDueSeconds );
		$this->assertLessThan( 60, (int) $report->secondsSinceCheckIn, 'The running job checked in just now.' );
		$this->assertFalse( $report->runnerStale() );
		$this->assertSame(
			array(
				array(
					'handler'    => RecordingJob::NAME,
					'failures'   => 2,
					'last_error' => 'latest failure',
				),
			),
			$report->failingHandlers
		);
		$this->assertSame( '4.2.0', $report->runtimeVersion );
		$this->assertSame( 'SEOCart', $report->runtimeSource );
		$this->assertContains( '4.2.0', $report->registeredVersions );
		$this->assertTrue( $report->runtimeSupported );
		$this->assertTrue( $this->queue->hasWork() );
	}

	/**
	 * Tests that a site whose runner never ran a job reports no check-in, and a stale runner.
	 *
	 * @since 0.1.0
	 */
	public function test_a_runner_that_never_ran_a_job_is_stale(): void {
		$this->queue->enqueue( new Job( RecordingJob::NAME ) );

		$report = $this->queue->report();

		$this->assertNull( $report->secondsSinceCheckIn );
		$this->assertTrue( $report->runnerStale() );
	}
}
