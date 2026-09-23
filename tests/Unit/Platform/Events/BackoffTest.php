<?php
/**
 * Tests the retry schedule of stored events
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Events;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Events\Backoff;

/**
 * One, four, sixteen and sixty minutes, then sixty minutes again.
 *
 * Planted violation: in Backoff::seconds(), return max( … ) instead of min( … ).
 *
 * @since 0.1.0
 */
final class BackoffTest extends TestCase {

	/**
	 * The delays after one to six failed attempts.
	 *
	 * @since 0.1.0
	 */
	public function test_the_schedule(): void {
		$this->assertSame(
			array( 60, 240, 960, 3600, 3600, 3600 ),
			array_map( array( Backoff::class, 'seconds' ), array( 1, 2, 3, 4, 5, 6 ) )
		);
	}

	/**
	 * A very large number of attempts still waits the longest delay, without overflowing.
	 *
	 * @since 0.1.0
	 */
	public function test_the_delay_is_capped_for_any_number_of_attempts(): void {
		$this->assertSame( Backoff::MAX_DELAY_SECONDS, Backoff::seconds( 1000 ) );
		$this->assertSame( Backoff::MAX_DELAY_SECONDS, Backoff::seconds( PHP_INT_MAX ) );
	}

	/**
	 * A delay follows at least one failed attempt.
	 *
	 * @since 0.1.0
	 */
	public function test_zero_attempts_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Backoff::seconds( 0 );
	}
}
