<?php
/**
 * Tests the retry schedule of a deadlocked unit of work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\RetryPolicy;

/**
 * Proves the bounds and the exponential ceiling of the full-jitter schedule.
 *
 * @since 0.1.0
 */
final class RetryPolicyTest extends TestCase {

	/**
	 * Tests that none() runs once.
	 *
	 * @since 0.1.0
	 */
	public function test_none_runs_once(): void {
		$this->assertSame( 1, RetryPolicy::none()->attempts() );
	}

	/**
	 * Tests the defaults: three runs, a first pause of at most 50 ms, doubling after each failure.
	 *
	 * @since 0.1.0
	 */
	public function test_the_deadlock_policy_doubles_its_ceiling(): void {
		$policy = RetryPolicy::deadlocks();

		$this->assertSame( 3, $policy->attempts() );
		$this->assertSame( array( 50, 100, 200 ), array( $policy->ceilingMs( 1 ), $policy->ceilingMs( 2 ), $policy->ceilingMs( 3 ) ) );
		$this->assertSame( array( 5, 10 ), array( RetryPolicy::deadlocks( 4, 5 )->ceilingMs( 1 ), RetryPolicy::deadlocks( 4, 5 )->ceilingMs( 2 ) ) );
	}

	/**
	 * Tests that a policy without an attempt cannot exist.
	 *
	 * @since 0.1.0
	 */
	public function test_zero_attempts_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		RetryPolicy::deadlocks( 0 );
	}

	/**
	 * Tests that a negative pause cannot exist.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_pause_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		RetryPolicy::deadlocks( 3, -1 );
	}
}
