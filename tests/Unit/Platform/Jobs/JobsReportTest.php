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
}
