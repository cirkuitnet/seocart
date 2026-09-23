<?php
/**
 * Tests SystemIdGenerator, the production version 7 UUID generator
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\SystemIdGenerator;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\SequenceClock;

/**
 * Proves the RFC 9562 layout and that identifiers only ever grow, within and across milliseconds.
 *
 * Every test uses a frozen or scripted clock and a seeded or fixed random engine, so every
 * identifier is reproducible.
 *
 * @since 0.1.0
 */
final class SystemIdGeneratorTest extends TestCase {

	/**
	 * A lowercase UUID with the version nibble 7 and the RFC 9562 variant bits 10 (8, 9, a or b).
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UUID_V7_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	/**
	 * 2026-09-22 10:00:00.123 UTC in Unix milliseconds, 0x01a0c88ed97b.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MILLISECONDS = 1790071200123;

	/**
	 * Tests the layout on identifiers whose random bits are pinned, spelled out in full.
	 *
	 * @since 0.1.0
	 */
	public function test_known_answers_with_pinned_random_bits(): void {
		$zeros = new SystemIdGenerator( self::frozenClock(), new Randomizer( new FixedBytesEngine( "\x00" ) ) );

		$this->assertInstanceOf( IdGenerator::class, $zeros );
		$this->assertSame( '01a0c88e-d97b-7000-8000-000000000000', $zeros->generate(), 'Timestamp, version 7, variant 10, and zero random bits.' );
		$this->assertSame( '01a0c88e-d97b-7000-8000-000000000001', $zeros->generate(), 'The same millisecond: the counter grows by the smallest step, one.' );
	}

	/**
	 * Tests that when the 74-bit counter runs out within a millisecond, the timestamp moves ahead.
	 *
	 * @since 0.1.0
	 */
	public function test_an_exhausted_counter_moves_to_the_next_millisecond(): void {
		$ones = new SystemIdGenerator( self::frozenClock(), new Randomizer( new FixedBytesEngine( "\xFF" ) ) );

		$first  = $ones->generate();
		$second = $ones->generate();

		$this->assertSame( '01a0c88e-d97b-7fff-bfff-ffffffffffff', $first, 'Every random bit set.' );
		$this->assertSame( '01a0c88e-d97c-7fff-bfff-ffffffffffff', $second, 'One millisecond ahead of the clock rather than a repeat.' );
		$this->assertGreaterThan( $first, $second );
	}

	/**
	 * Tests format, version, variant and timestamp on many identifiers.
	 *
	 * @since 0.1.0
	 */
	public function test_every_identifier_has_the_version_7_layout_and_the_clock_timestamp(): void {
		$generator = new SystemIdGenerator( self::frozenClock(), self::seeded( 7 ) );

		for ( $count = 0; $count < 1000; $count++ ) {
			$id = $generator->generate();

			$this->assertMatchesRegularExpression( self::UUID_V7_PATTERN, $id );
			$this->assertSame( self::MILLISECONDS, self::timestampOf( $id ) );
		}
	}

	/**
	 * Tests strict ordering across many identifiers minted in one millisecond.
	 *
	 * @since 0.1.0
	 */
	public function test_identifiers_minted_in_one_millisecond_strictly_increase(): void {
		$generator = new SystemIdGenerator( self::frozenClock(), self::seeded( 11 ) );
		$minted    = array();

		for ( $count = 0; $count < 10000; $count++ ) {
			$minted[] = $generator->generate();
		}

		$sorted = $minted;
		sort( $sorted, SORT_STRING );

		$this->assertSame( $minted, $sorted, 'Minting order is sort order.' );
		$this->assertCount( 10000, array_unique( $minted ) );
		$this->assertSame( self::MILLISECONDS, self::timestampOf( $minted[9999] ), 'Ten thousand steps do not exhaust the counter.' );
	}

	/**
	 * Tests ordering across milliseconds, and that each identifier carries its own millisecond.
	 *
	 * @since 0.1.0
	 */
	public function test_identifiers_increase_across_milliseconds(): void {
		$instants  = array( '10:00:00.123', '10:00:00.124', '10:00:00.999', '10:00:01.000', '10:07:30.500' );
		$clock     = new SequenceClock(
			...array_map( static fn( string $time ): \DateTimeImmutable => new \DateTimeImmutable( '2026-09-22 ' . $time, new \DateTimeZone( 'UTC' ) ), $instants )
		);
		$generator = new SystemIdGenerator( $clock, self::seeded( 13 ) );
		$previous  = '';

		foreach ( $instants as $time ) {
			$id       = $generator->generate();
			$expected = (int) ( new \DateTimeImmutable( '2026-09-22 ' . $time, new \DateTimeZone( 'UTC' ) ) )->format( 'Uv' );

			$this->assertSame( $expected, self::timestampOf( $id ), $time );
			$this->assertGreaterThan( $previous, $id );

			$previous = $id;
		}
	}

	/**
	 * Tests that a clock stepping backwards cannot make an identifier go backwards.
	 *
	 * @since 0.1.0
	 */
	public function test_a_clock_that_steps_back_does_not_reorder_identifiers(): void {
		$clock     = new SequenceClock(
			new \DateTimeImmutable( '2026-09-22 10:00:00.123', new \DateTimeZone( 'UTC' ) ),
			new \DateTimeImmutable( '2026-09-22 10:00:00.113', new \DateTimeZone( 'UTC' ) )
		);
		$generator = new SystemIdGenerator( $clock, self::seeded( 17 ) );

		$first  = $generator->generate();
		$second = $generator->generate();

		$this->assertGreaterThan( $first, $second );
		$this->assertSame( self::MILLISECONDS, self::timestampOf( $second ), 'The earlier reading reuses the last timestamp.' );
	}

	/**
	 * Tests that a time a version 7 UUID cannot hold is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_time_before_1970_is_refused(): void {
		$this->expectException( \LogicException::class );

		( new SystemIdGenerator( FrozenClock::at( '1969-12-31 23:59:59' ), self::seeded( 19 ) ) )->generate();
	}

	/**
	 * Tests that the default, cryptographically secure randomness produces the same layout.
	 *
	 * @since 0.1.0
	 */
	public function test_the_default_randomness_produces_the_same_layout(): void {
		$id = ( new SystemIdGenerator( self::frozenClock() ) )->generate();

		$this->assertMatchesRegularExpression( self::UUID_V7_PATTERN, $id );
		$this->assertSame( self::MILLISECONDS, self::timestampOf( $id ) );
	}

	/**
	 * Returns a clock frozen at 2026-09-22 10:00:00.123 UTC.
	 *
	 * @since 0.1.0
	 *
	 * @return FrozenClock The clock.
	 */
	private static function frozenClock(): FrozenClock {
		return FrozenClock::at( '2026-09-22 10:00:00.123' );
	}

	/**
	 * Returns a seeded Randomizer.
	 *
	 * @since 0.1.0
	 *
	 * @param int $seed The seed.
	 * @return Randomizer The Randomizer.
	 */
	private static function seeded( int $seed ): Randomizer {
		return new Randomizer( new Xoshiro256StarStar( $seed ) );
	}

	/**
	 * Reads the 48-bit timestamp out of an identifier.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id A version 7 UUID.
	 * @return int The Unix time in milliseconds.
	 */
	private static function timestampOf( string $id ): int {
		return (int) hexdec( substr( $id, 0, 8 ) . substr( $id, 9, 4 ) );
	}
}
