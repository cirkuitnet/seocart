<?php
/**
 * Tests how the state of background work is judged
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Jobs\JobsReport;

/**
 * The runner is stale without a recent check-in, and the runtime is supported from the minimum version on.
 *
 * @since 0.1.0
 */
final class JobsReportTest extends TestCase {

	/**
	 * Tests the staleness rule at its edges.
	 *
	 * Planted violation: `>=` instead of `>` in runnerStale(); a check-in exactly at the threshold turns stale.
	 *
	 * @since 0.1.0
	 */
	public function test_the_runner_is_stale_without_a_check_in_or_after_the_threshold(): void {
		$this->assertTrue( self::report( null, '4.2.0' )->runnerStale(), 'A runner that never checked in is stale.' );
		$this->assertFalse( self::report( JobsReport::STALE_AFTER_SECONDS, '4.2.0' )->runnerStale() );
		$this->assertTrue( self::report( JobsReport::STALE_AFTER_SECONDS + 1, '4.2.0' )->runnerStale() );
		$this->assertFalse( self::report( 120, '4.2.0' )->runnerStale( 300 ) );
		$this->assertTrue( self::report( 301, '4.2.0' )->runnerStale( 300 ) );
	}

	/**
	 * Tests that a runner is missing only once something needs one: a stale runner that checked
	 * in before, or no check-in ever and a job overdue past the stale window.
	 *
	 * Planted violation: `>=` instead of `>` in runnerMissing(); a job due for exactly the
	 * window, with no runner ever checked in, is reported missing one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_runner_is_missing_only_once_something_needs_one(): void {
		$this->assertFalse( self::dueReport( null, null )->runnerMissing(), 'Nothing has ever been due, and no runner ever checked in.' );
		$this->assertFalse( self::dueReport( JobsReport::STALE_AFTER_SECONDS, null )->runnerMissing(), 'Due for exactly the window, but no longer, and no runner ever checked in.' );
		$this->assertTrue( self::dueReport( JobsReport::STALE_AFTER_SECONDS + 1, null )->runnerMissing(), 'Due for longer than the window, and no runner ever checked in.' );
		$this->assertFalse( self::dueReport( null, JobsReport::STALE_AFTER_SECONDS )->runnerMissing(), 'A runner checked in within the window.' );
		$this->assertTrue( self::dueReport( null, JobsReport::STALE_AFTER_SECONDS + 1 )->runnerMissing(), 'A runner checked in before, but has gone stale.' );
	}

	/**
	 * Tests the minimum-version rule.
	 *
	 * @since 0.1.0
	 */
	public function test_the_runtime_is_supported_from_the_minimum_version_on(): void {
		$this->assertTrue( self::report( 0, JobsReport::MINIMUM_VERSION )->runtimeSupported );
		$this->assertTrue( self::report( 0, '4.2.0' )->runtimeSupported );
		$this->assertFalse( self::report( 0, '3.5.4' )->runtimeSupported );
		$this->assertFalse( self::report( 0, '' )->runtimeSupported, 'No runtime loaded is not supported.' );
	}

	/**
	 * Builds a report with the parts under test.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $sinceCheckIn Seconds since the last check-in.
	 * @param string   $version      The runtime version.
	 * @return JobsReport The report.
	 */
	private static function report( ?int $sinceCheckIn, string $version ): JobsReport {
		return new JobsReport( 0, 0, 0, 0, null, $sinceCheckIn, array(), $version, 'SEOCart', array( $version ) );
	}

	/**
	 * Builds a report with the oldest due job's age and the last check-in under test.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $oldestDueSeconds How long the oldest due job has waited.
	 * @param int|null $sinceCheckIn     Seconds since the last check-in.
	 * @return JobsReport The report.
	 */
	private static function dueReport( ?int $oldestDueSeconds, ?int $sinceCheckIn ): JobsReport {
		return new JobsReport( 0, 0, 0, 0, $oldestDueSeconds, $sinceCheckIn, array(), '4.2.0', 'SEOCart', array( '4.2.0' ) );
	}
}
