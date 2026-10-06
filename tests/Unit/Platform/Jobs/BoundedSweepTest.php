<?php
/**
 * Tests when a sweep of bounded batches stops
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Jobs;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Jobs\BoundedSweep;

/**
 * A batch that comes back short ends the run, and so does the budget; the first batch always runs.
 *
 * Each test hands the sweep a batch that records the limit it was given and answers from a
 * script, and a clock that answers from another, so what the sweep did is the list of limits.
 *
 * Planted violations, each shown red and removed:
 * - in BoundedSweep::run(), go on only while a batch deleted more than the batch size (`>`
 *   for `>=`): a full batch ends the run;
 * - in BoundedSweep::run(), go on while the clock is at the deadline or before it (`<=` for
 *   `<`): the run takes one batch past its budget.
 *
 * @since 0.1.0
 */
final class BoundedSweepTest extends TestCase {

	/**
	 * The limits each batch was given, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	private array $limits = array();

	/**
	 * Tests that the run goes on while batches come back full, and a batch short of the size ends it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_batch_short_of_the_size_ends_the_run(): void {
		( new BoundedSweep( 3, 20, self::stoppedClock() ) )->run( $this->batches( 3, 3, 2, 3 ) );

		$this->assertSame( array( 3, 3, 3 ), $this->limits, 'Two full batches, then a short one, which ends the run; the fourth never starts.' );
	}

	/**
	 * Tests that a first batch that deletes nothing ends the run.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_first_batch_ends_the_run(): void {
		( new BoundedSweep( 3, 20, self::stoppedClock() ) )->run( $this->batches( 0, 3 ) );

		$this->assertSame( array( 3 ), $this->limits );
	}

	/**
	 * Tests that the budget bounds a run of full batches: with each batch taking a second, a budget of five seconds runs five.
	 *
	 * @since 0.1.0
	 */
	public function test_the_budget_bounds_the_run(): void {
		$reads = 0;
		$clock = static function () use ( &$reads ): int {
			$second = $reads;
			++$reads;

			return $second * 1000000000;
		};

		( new BoundedSweep( 2, 5, $clock ) )->run( $this->batches( ...array_fill( 0, 10, 2 ) ) );

		$this->assertSame( array( 2, 2, 2, 2, 2 ), $this->limits, 'At the fifth second the budget is spent, and no sixth batch starts.' );
	}

	/**
	 * Tests that the first batch runs even when the budget is already spent once it is read.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_batch_always_runs(): void {
		$calls = 0;
		$clock = static function () use ( &$calls ): int {
			return ++$calls <= 1 ? 0 : PHP_INT_MAX;
		};

		( new BoundedSweep( 2, 20, $clock ) )->run( $this->batches( 2, 2 ) );

		$this->assertSame( array( 2 ), $this->limits );
		$this->assertSame( 2, $calls, 'The clock is read before the first batch and after it.' );
	}

	/**
	 * Tests that a batch size below one is raised to one, so a batch always deletes something.
	 *
	 * @since 0.1.0
	 */
	public function test_a_batch_size_below_one_is_one(): void {
		( new BoundedSweep( 0, 20, self::stoppedClock() ) )->run( $this->batches( 1, 0 ) );

		$this->assertSame( array( 1, 1 ), $this->limits );
	}

	/**
	 * Returns a batch that records each limit it is given and answers with the next count of the script.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$counts How many rows each batch says it deleted, in order.
	 * @return \Closure(int): int The batch.
	 */
	private function batches( int ...$counts ): \Closure {
		return function ( int $limit ) use ( &$counts ): int {
			$this->limits[] = $limit;

			return (int) array_shift( $counts );
		};
	}

	/**
	 * Returns a clock that never moves, so the budget is never spent.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(): int The clock.
	 */
	private static function stoppedClock(): \Closure {
		return static fn(): int => 0;
	}
}
