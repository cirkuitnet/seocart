<?php
/**
 * Tests SystemClock, the production Clock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\Clock;
use SEOCart\Support\SystemClock;

/**
 * Proves that the production clock reads the system time in UTC, whatever PHP's default zone.
 *
 * The test reads the wall clock only to bracket the reading, so its outcome does not depend on
 * what time it is.
 *
 * @since 0.1.0
 */
final class SystemClockTest extends TestCase {

	/**
	 * Tests that the clock is a Clock and reads the current instant in UTC.
	 *
	 * @since 0.1.0
	 */
	public function test_it_reads_the_current_instant_in_utc_whatever_the_default_zone(): void {
		$default = date_default_timezone_get();

		try {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- The test proves SystemClock ignores PHP's default zone; the finally block restores it.
			date_default_timezone_set( 'Pacific/Kiritimati' );

			$before = new \DateTimeImmutable( 'now' );
			$now    = ( new SystemClock() )->now();
			$after  = new \DateTimeImmutable( 'now' );
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restores the zone the test changed.
			date_default_timezone_set( $default );
		}

		$this->assertInstanceOf( Clock::class, new SystemClock() );
		$this->assertSame( 'UTC', $now->getTimezone()->getName() );
		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( $after, $now );
	}
}
