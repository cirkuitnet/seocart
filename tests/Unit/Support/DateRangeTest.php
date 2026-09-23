<?php
/**
 * Tests DateRange: half-open windows of time in UTC
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SEOCart\Support\DateRange;

/**
 * Proves that a range contains its start, not its end, runs forever without an end, and is kept in UTC.
 *
 * @since 0.1.0
 */
final class DateRangeTest extends TestCase {

	/**
	 * Tests which instants a closed range contains.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_instants
	 *
	 * @param string $instant  An instant in UTC.
	 * @param bool   $expected Whether the range contains it.
	 */
	public function test_a_range_contains_its_start_and_not_its_end( string $instant, bool $expected ): void {
		$range = DateRange::between( self::utc( '2026-11-27 00:00:00' ), self::utc( '2026-12-01 00:00:00' ) );

		$this->assertSame( $expected, $range->contains( self::utc( $instant ) ) );
	}

	/**
	 * Provides instants around a four-day window.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}> Test cases.
	 */
	public static function data_instants(): array {
		return array(
			'a microsecond before the start' => array( '2026-11-26 23:59:59.999999', false ),
			'the start'                      => array( '2026-11-27 00:00:00', true ),
			'inside'                         => array( '2026-11-29 12:00:00', true ),
			'a microsecond before the end'   => array( '2026-11-30 23:59:59.999999', true ),
			'the end'                        => array( '2026-12-01 00:00:00', false ),
			'after the end'                  => array( '2027-01-01 00:00:00', false ),
		);
	}

	/**
	 * Tests that two ranges that meet never both contain the instant where they meet.
	 *
	 * @since 0.1.0
	 */
	public function test_adjacent_ranges_do_not_overlap(): void {
		$first  = DateRange::between( self::utc( '2026-01-01 00:00:00' ), self::utc( '2026-02-01 00:00:00' ) );
		$second = DateRange::between( self::utc( '2026-02-01 00:00:00' ), self::utc( '2026-03-01 00:00:00' ) );
		$meet   = self::utc( '2026-02-01 00:00:00' );

		$this->assertFalse( $first->contains( $meet ) );
		$this->assertTrue( $second->contains( $meet ) );
	}

	/**
	 * Tests a range without an end.
	 *
	 * @since 0.1.0
	 */
	public function test_a_range_without_an_end_runs_forever(): void {
		$range = DateRange::startingAt( self::utc( '2026-01-01 00:00:00' ) );

		$this->assertNull( $range->end() );
		$this->assertFalse( $range->contains( self::utc( '2025-12-31 23:59:59' ) ) );
		$this->assertTrue( $range->contains( self::utc( '9999-12-31 23:59:59' ) ) );
	}

	/**
	 * Tests that instants given in another time zone are kept, and compared, in UTC.
	 *
	 * @since 0.1.0
	 */
	public function test_instants_are_kept_in_utc(): void {
		$berlin = new \DateTimeZone( 'Europe/Berlin' );
		$range  = DateRange::between(
			new \DateTimeImmutable( '2026-07-01 00:00:00', $berlin ),
			new \DateTimeImmutable( '2026-07-02 00:00:00', $berlin )
		);

		$this->assertSame( '2026-06-30 22:00:00 UTC', $range->start()->format( 'Y-m-d H:i:s T' ) );
		$this->assertSame( '2026-07-01 22:00:00 UTC', $range->end() ? $range->end()->format( 'Y-m-d H:i:s T' ) : '' );
		$this->assertTrue( $range->contains( self::utc( '2026-06-30 22:30:00' ) ), 'Half past midnight in Berlin is inside.' );
		$this->assertFalse( $range->contains( new \DateTimeImmutable( '2026-06-30 23:30:00', $berlin ) ), 'Half past eleven the evening before, Berlin time, is outside.' );
	}

	/**
	 * Tests that a range that would contain nothing is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_or_reversed_range_is_refused(): void {
		$refused = 0;

		foreach ( array( '2026-01-01 00:00:00', '2025-12-31 00:00:00' ) as $end ) {
			try {
				DateRange::between( self::utc( '2026-01-01 00:00:00' ), self::utc( $end ) );
			} catch ( \InvalidArgumentException $empty ) {
				++$refused;
			}
		}

		$this->assertSame( 2, $refused );
	}

	/**
	 * Reads an instant in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @param string $instant A date and time.
	 * @return \DateTimeImmutable The instant.
	 */
	private static function utc( string $instant ): \DateTimeImmutable {
		return new \DateTimeImmutable( $instant, new \DateTimeZone( 'UTC' ) );
	}
}
