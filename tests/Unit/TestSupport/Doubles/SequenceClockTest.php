<?php
/**
 * Tests the SequenceClock test double
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport\Doubles;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\Doubles\SequenceClock;

/**
 * Proves that a sequence clock replays its script in order, in UTC, and refuses to improvise.
 *
 * @since 0.1.0
 */
final class SequenceClockTest extends TestCase {

	/**
	 * Tests that the double can stand in for the port.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_a_clock(): void {
		$this->assertInstanceOf( Clock::class, new SequenceClock() );
	}

	/**
	 * Tests that each reading returns the next scripted instant, converted to UTC.
	 *
	 * @since 0.1.0
	 */
	public function test_each_reading_returns_the_next_scripted_instant_in_utc(): void {
		$clock = new SequenceClock(
			new \DateTimeImmutable( '2026-01-15 12:00:00', new \DateTimeZone( 'UTC' ) ),
			new \DateTimeImmutable( '2026-01-15 14:00:01', new \DateTimeZone( 'Europe/Berlin' ) ),
			new \DateTimeImmutable( '2026-01-15 12:00:02', new \DateTimeZone( 'UTC' ) )
		);

		$readings = array();

		for ( $reading = 0; $reading < 3; $reading++ ) {
			$now = $clock->now();

			$this->assertSame( 'UTC', $now->getTimezone()->getName() );

			$readings[] = $now->format( 'Y-m-d H:i:s' );
		}

		$this->assertSame( array( '2026-01-15 12:00:00', '2026-01-15 13:00:01', '2026-01-15 12:00:02' ), $readings );
	}

	/**
	 * Tests that one reading too many is an error instead of a repeat of the last instant.
	 *
	 * @since 0.1.0
	 */
	public function test_reading_past_the_end_of_the_script_is_an_error(): void {
		$clock = new SequenceClock( new \DateTimeImmutable( '2026-01-15 12:00:00' ) );

		$clock->now();

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'read 2 times but only 1 instants were scripted' );

		$clock->now();
	}
}
