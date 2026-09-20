<?php
/**
 * Tests the FrozenClock test double
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport\Doubles;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * Proves that a frozen clock honours the Clock contract and moves only when told to.
 *
 * @since 0.1.0
 */
final class FrozenClockTest extends TestCase {

	/**
	 * Tests that the double can stand in for the port.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_a_clock(): void {
		$this->assertInstanceOf( Clock::class, FrozenClock::at( '2026-01-15 12:00:00' ) );
	}

	/**
	 * Tests that the instant is reported in UTC, whatever zone it was given in.
	 *
	 * @since 0.1.0
	 */
	public function test_now_is_in_utc_whatever_zone_the_instant_was_given_in(): void {
		$in_new_york = new \DateTimeImmutable( '2026-03-02 09:00:00', new \DateTimeZone( 'America/New_York' ) );

		$now = ( new FrozenClock( $in_new_york ) )->now();

		$this->assertSame( 'UTC', $now->getTimezone()->getName() );
		$this->assertSame( '2026-03-02 14:00:00', $now->format( 'Y-m-d H:i:s' ) );
		$this->assertSame( $in_new_york->getTimestamp(), $now->getTimestamp(), 'Converting to UTC must not change the instant.' );
	}

	/**
	 * Tests that a string without a zone is read as UTC, and that a string with an offset keeps its instant.
	 *
	 * @since 0.1.0
	 */
	public function test_at_reads_a_string_as_utc(): void {
		$this->assertSame( '2026-01-15T12:00:00+00:00', FrozenClock::at( '2026-01-15 12:00:00' )->now()->format( DATE_ATOM ) );
		$this->assertSame( '2026-01-15T10:00:00+00:00', FrozenClock::at( '2026-01-15T12:00:00+02:00' )->now()->format( DATE_ATOM ) );
	}

	/**
	 * Tests that time does not pass between two readings.
	 *
	 * @since 0.1.0
	 */
	public function test_time_does_not_pass_on_its_own(): void {
		$clock = FrozenClock::at( '2026-01-15 12:00:00.123456' );

		$first = $clock->now();
		usleep( 2000 );

		$this->assertSame( $first->format( 'Y-m-d H:i:s.u' ), $clock->now()->format( 'Y-m-d H:i:s.u' ) );
	}

	/**
	 * Tests that advance() moves the clock forward by the interval.
	 *
	 * @since 0.1.0
	 */
	public function test_advance_moves_the_clock_forward(): void {
		$clock = FrozenClock::at( '2026-01-15 23:50:00' );

		$clock->advance( new \DateInterval( 'PT15M' ) );

		$this->assertSame( '2026-01-16T00:05:00+00:00', $clock->now()->format( DATE_ATOM ) );
	}

	/**
	 * Tests that setTo() moves the clock to another instant, again in UTC.
	 *
	 * @since 0.1.0
	 */
	public function test_set_to_moves_the_clock_and_converts_to_utc(): void {
		$clock = FrozenClock::at( '2026-01-15 12:00:00' );

		$clock->setTo( new \DateTimeImmutable( '2027-06-01 08:30:00', new \DateTimeZone( 'Europe/Berlin' ) ) );

		$this->assertSame( '2027-06-01T06:30:00+00:00', $clock->now()->format( DATE_ATOM ) );
	}
}
