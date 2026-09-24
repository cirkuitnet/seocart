<?php
/**
 * Tests that a run of the library's queue runner in a jobs test is the run of a fresh WP-Cron request
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use Action_Scheduler\Migration\Scheduler;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;

/**
 * A queue run in a jobs test depends neither on how long the test process has run nor on what the queue held before the test.
 *
 * Each test starts as a test late in a long run starts. The library's migration hook is due: the
 * test stores one, due a minute ago, before the test begins, as the library's own stands once
 * a minute has passed since the test site was installed. And the runner's time is spent: its
 * limit is filtered to 0, which is what the limit amounts to once the process has run for longer
 * than it, because the library counts from when its one runner was created.
 *
 * Planted violations, in JobsTestCase::set_up():
 *
 * - drop both the deferral of pending actions and the time filter, as the jobs tests were before:
 *   in the first test the job never runs, because the runner claims the migration hook first,
 *   starts it, and stops;
 * - drop the time filter only: in the second test the runner starts the other plugin's action,
 *   stored first, and stops, so the job never runs;
 * - drop the deferral only: in the first test the migration hook runs during the test.
 *
 * @since 0.1.0
 */
final class LibraryQueueRunTest extends JobsTestCase {

	/**
	 * The id of the due migration action the test stored.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $migration;

	/**
	 * Stores the library's migration hook, due, before the test begins, and spends the runner's time.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		$this->migration = (int) as_schedule_single_action( time() - MINUTE_IN_SECONDS, Scheduler::HOOK, array(), Scheduler::GROUP );

		parent::set_up();

		add_filter( 'action_scheduler_queue_runner_time_limit', '__return_zero' );
	}

	/**
	 * Gives the runner its time back and deletes the migration action the test stored.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		remove_filter( 'action_scheduler_queue_runner_time_limit', '__return_zero' );

		parent::tear_down();

		\ActionScheduler::store()->delete_action( (string) $this->migration );
	}

	/**
	 * Tests that WP-Cron's run starts the job, though the library's migration was due first and the runner's time is spent, and leaves the migration alone.
	 *
	 * @since 0.1.0
	 */
	public function test_a_run_starts_the_job_and_not_the_librarys_due_migration(): void {
		$this->assertGreaterThan( 0, $this->migration, 'The migration action was not stored, so the test would prove nothing.' );

		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 7 ) ) );

		do_action( 'action_scheduler_run_queue', 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's WP-Cron hook, fired as WP-Cron fires it.

		$this->assertCount( 1, RecordingJob::$runs, 'The job did not run.' );
		$this->assertSame( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler::store()->get_status( (string) $this->migration ), "The library's migration hook, due before the test, ran in the test." );
	}

	/**
	 * Tests that WP-Cron's run starts every action due, though the runner's time is spent.
	 *
	 * @since 0.1.0
	 */
	public function test_a_run_starts_every_due_action(): void {
		$ran = 0;

		add_action(
			self::OTHER_HOOK,
			static function () use ( &$ran ): void {
				++$ran;
			}
		);

		$this->otherPluginAction();
		$this->queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 7 ) ) );

		do_action( 'action_scheduler_run_queue', 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's WP-Cron hook, fired as WP-Cron fires it.

		$this->assertSame( 1, $ran, "The other plugin's action, stored first, did not run." );
		$this->assertCount( 1, RecordingJob::$runs, 'The job, due after the first action, did not run.' );
	}
}
