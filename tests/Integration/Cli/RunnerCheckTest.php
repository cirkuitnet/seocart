<?php
/**
 * Tests the runner check against the real Action Scheduler: each failure it reports is planted, found and removed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cli;

use SEOCart\Platform\Cli\Doctor\CheckResult;
use SEOCart\Platform\Cli\Doctor\RunnerCheck;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\LogRetentionJob;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\CustomStoreStub;
use SEOCart\Tests\Support\Jobs\PluginActions;

/**
 * The runner check reads the jobs module's report and fails on each state that needs a person.
 *
 * The queue is the real one over the real Action Scheduler, so a check-in, a stale runner,
 * failed jobs and a custom store are planted in the library itself. The version of the copy in
 * control cannot be planted in a loaded library, so for it, and for a copy that is not loaded,
 * the check reads a report built by hand. Every plant is removed and the check passes again.
 * No finding carries a last error or a path.
 *
 * @since 0.1.0
 */
final class RunnerCheckTest extends DatabaseTestCase {

	/**
	 * A last error that holds what must never be printed.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PRIVATE_ERROR = 'jane.doe@example.com 4111111111111111 planted-secret-value';

	/**
	 * The library's own store, while a test has swapped it.
	 *
	 * @since 0.1.0
	 *
	 * @var \ActionScheduler_Store|null
	 */
	private ?\ActionScheduler_Store $original = null;

	/**
	 * Clears the plugin's jobs.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		PluginActions::purge();
	}

	/**
	 * Puts the library's own store back and clears the plugin's jobs.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		if ( null !== $this->original ) {
			( new \ReflectionProperty( \ActionScheduler_Store::class, 'store' ) )->setValue( null, $this->original );

			$this->original = null;
		}

		PluginActions::purge();

		parent::tear_down();
	}

	/**
	 * Tests that a runner that checked in lately, on the library the plugin ships, with no failed job, passes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_working_runner_passes(): void {
		PluginActions::checkIn( 30 );

		$result = $this->check();

		$this->assertTrue( $result->passed, implode( "\n", $result->findings ) );
		$this->assertMatchesRegularExpression( '/^Action Scheduler [0-9.]+ from .+ is in control; a runner started a job \d+ seconds ago; 0 due, none failed\.$/', $result->summary );
	}

	/**
	 * Tests that a runner that never checked in, or not for too long, fails, and passes again once one checks in.
	 *
	 * Planted violation: in RunnerCheck::checkIn(), return nothing (both runners pass).
	 *
	 * @since 0.1.0
	 */
	public function test_a_runner_that_has_not_checked_in_fails(): void {
		$never = $this->check();

		$this->assertFalse( $never->passed );
		$this->assertSame( array( 'No runner has ever started one of SEOCart\'s jobs. Check that WP-Cron runs, or run `wp seocart jobs run` from the system cron.' ), $never->findings );

		$id = PluginActions::checkIn( JobsReport::STALE_AFTER_SECONDS + 600 );

		$stale = $this->check();

		$this->assertFalse( $stale->passed );
		$this->assertCount( 1, $stale->findings );
		$this->assertMatchesRegularExpression( '/^No runner has started one of SEOCart\'s jobs for 42\d\d seconds, more than 60 minutes\./', $stale->findings[0] );

		PluginActions::attempted( $id, 5 );

		$this->assertTrue( $this->check()->passed, 'A runner checked in again.' );
	}

	/**
	 * Tests that failed jobs fail the check with their count and their handlers, never their last errors.
	 *
	 * Planted violation: in RunnerCheck::failures(), print each handler's last_error (the planted
	 * email and card are printed).
	 *
	 * @since 0.1.0
	 */
	public function test_failed_jobs_fail_with_counts_and_handlers_only(): void {
		PluginActions::checkIn();
		PluginActions::failed( LogRetentionJob::name(), self::PRIVATE_ERROR );
		PluginActions::failed( LogRetentionJob::name(), self::PRIVATE_ERROR );
		PluginActions::failed( 'outbox.prune', self::PRIVATE_ERROR );

		$result = $this->check();

		$this->assertFalse( $result->passed );
		$this->assertSame(
			array(
				'3 of SEOCart\'s jobs failed for the last time; `wp seocart jobs status` shows their last errors.',
				'handler logs.prune: 2 failed',
				'handler outbox.prune: 1 failed',
			),
			$result->findings
		);
		$this->assertStringNotContainsString( 'jane.doe', implode( "\n", $result->findings ) . $result->summary );
		$this->assertStringNotContainsString( '4111', implode( "\n", $result->findings ) . $result->summary );

		PluginActions::purge();
		PluginActions::checkIn();

		$this->assertTrue( $this->check()->passed, 'The failed jobs are gone.' );
	}

	/**
	 * Tests that a store another plugin gave the library fails the check, naming the store's class, and nothing read from the library's own tables.
	 *
	 * In the library's own tables the one job attempted failed, over an hour ago: no runner has
	 * checked in lately, and a job failed. On the other plugin's store neither says anything, so
	 * neither is reported.
	 *
	 * Planted violations: in RunnerCheck::runtime(), ignore customStore (the check reports only
	 * the runner); in RunnerCheck::run(), also report the check-in and the failures on a custom
	 * store (a false "no runner" line and a failure line are added).
	 *
	 * @since 0.1.0
	 */
	public function test_a_custom_store_fails(): void {
		PluginActions::attempted( PluginActions::failed( LogRetentionJob::name() ), JobsReport::STALE_AFTER_SECONDS + 600 );

		$property       = new \ReflectionProperty( \ActionScheduler_Store::class, 'store' );
		$this->original = $property->getValue();

		$property->setValue( null, new CustomStoreStub() );

		$result = $this->check();

		$this->assertFalse( $result->passed );
		$this->assertSame( array( 'Action Scheduler keeps its actions in a store another plugin chose (' . CustomStoreStub::class . '): SEOCart\'s own triggers run none of its jobs, and these counts say nothing about them. Only WP-Cron runs them.' ), $result->findings );
		$this->assertStringNotContainsString( 'No runner', implode( "\n", $result->findings ), 'No runner line from tables the store does not use.' );

		$property->setValue( null, $this->original );

		$this->original = null;

		$this->assertCount( 3, $this->check()->findings, 'On the library\'s own store the check-in and the failure are read again.' );

		PluginActions::purge();
		PluginActions::checkIn();

		$this->assertTrue( $this->check()->passed, 'The library\'s own store is back.' );
	}

	/**
	 * Tests that an unsupported copy of the library in control, or none, fails, and that a path is never printed.
	 *
	 * The version cannot be planted in the loaded library, so these reports are built by hand,
	 * with everything else healthy.
	 *
	 * Planted violations: in RunnerCheck::runtime(), test runtimeVersion only for being empty
	 * (the old copy passes); in RunnerCheck::source(), return the source as given (the path is
	 * printed).
	 *
	 * @since 0.1.0
	 */
	public function test_an_unsupported_or_missing_runtime_fails(): void {
		$old = $this->checkOf( self::report( '3.5.0', 'plugin old-shop' ) );

		$this->assertFalse( $old->passed );
		$this->assertSame( array( 'The copy of Action Scheduler in control is version 3.5.0, from plugin old-shop; SEOCart needs ' . JobsReport::MINIMUM_VERSION . ' or newer. Update the plugin or theme that ships it.' ), $old->findings );

		$elsewhere = $this->checkOf( self::report( '3.5.0', '/home/jane.doe/private/action-scheduler' ) );

		$this->assertStringContainsString( 'from (a path, withheld)', $elsewhere->findings[0] );
		$this->assertStringNotContainsString( 'jane.doe', $elsewhere->findings[0] );

		$none = $this->checkOf( self::report( '', 'not loaded' ) );

		$this->assertSame( array( 'No copy of Action Scheduler is loaded, so no background job runs.' ), $none->findings );

		$current = $this->checkOf( self::report( JobsReport::MINIMUM_VERSION, 'theme storefront' ) );

		$this->assertTrue( $current->passed );
		$this->assertStringStartsWith( 'Action Scheduler ' . JobsReport::MINIMUM_VERSION . ' from theme storefront is in control;', $current->summary );
	}

	/**
	 * Runs the check over the real queue.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult The result.
	 */
	private function check(): CheckResult {
		return ( new RunnerCheck( new ActionSchedulerQueue( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new JobHandlers( JobHandlers::PRODUCTION, 'strval' ), new CorrelationId( new SequentialIdGenerator() ), $this->reporter() ) ) )->run();
	}

	/**
	 * Runs the check over a queue that reports what it is given.
	 *
	 * @since 0.1.0
	 *
	 * @param JobsReport $report The report.
	 * @return CheckResult The result.
	 */
	private function checkOf( JobsReport $report ): CheckResult {
		$queue = new class( $report ) implements JobQueue {

			/**
			 * The report it gives.
			 *
			 * @var JobsReport
			 */
			private JobsReport $report;

			/**
			 * Keeps the report.
			 *
			 * @param JobsReport $report The report.
			 */
			public function __construct( JobsReport $report ) {
				$this->report = $report;
			}

			/**
			 * Queues nothing.
			 *
			 * @param Job $job The job.
			 * @return bool False.
			 */
			public function enqueue( Job $job ): bool {
				return false;
			}

			/**
			 * Schedules nothing.
			 *
			 * @param Job                $job   The job.
			 * @param \DateTimeImmutable $runAt When.
			 * @return bool False.
			 */
			public function schedule( Job $job, \DateTimeImmutable $runAt ): bool {
				return false;
			}

			/**
			 * Cancels nothing.
			 *
			 * @param string|null $handler   The handler.
			 * @param string|null $uniqueKey The key.
			 * @return int 0.
			 */
			public function cancel( ?string $handler = null, ?string $uniqueKey = null ): int {
				return 0;
			}

			/**
			 * Cleans up nothing.
			 *
			 * @param int $limit The limit.
			 * @return int 0.
			 */
			public function cleanup( int $limit ): int {
				return 0;
			}

			/**
			 * Schedules nothing.
			 *
			 * @return list<string> None.
			 */
			public function ensureRecurring(): array {
				return array();
			}

			/**
			 * Returns the report it was given.
			 *
			 * @return JobsReport The report.
			 */
			public function report(): JobsReport {
				return $this->report;
			}
		};

		return ( new RunnerCheck( $queue ) )->run();
	}

	/**
	 * Builds a healthy report but for its runtime.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version The version in control.
	 * @param string $source  Where it comes from.
	 * @return JobsReport The report.
	 */
	private static function report( string $version, string $source ): JobsReport {
		return new JobsReport( 0, 3, 0, 0, null, 60, array(), $version, $source, '' === $version ? array() : array( $version ) );
	}
}
