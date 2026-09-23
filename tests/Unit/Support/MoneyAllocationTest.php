<?php
/**
 * Tests Money::allocate(): largest remainder with an ordinal tie-break
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\SeededCases;

/**
 * Proves the allocation rule of ADR-0004 on worked examples and on seeded random cases.
 *
 * The property oracle is exact integer arithmetic: for a total T and integer ratios r with sum
 * S, share i must be floor(|T| × r_i / S) or one more, and the extra units must go to the
 * largest remainders (|T| × r_i mod S), the earliest share winning a tie. Decimal ratios are
 * integer ratios scaled by a common power of ten, which changes no proportion.
 *
 * @since 0.1.0
 */
final class MoneyAllocationTest extends TestCase {

	/**
	 * Tests worked examples.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_allocations
	 *
	 * @param int                $total    The amount in minor units.
	 * @param array<int|Decimal> $ratios   The ratios.
	 * @param array<int>         $expected The shares in minor units.
	 */
	public function test_worked_examples( int $total, array $ratios, array $expected ): void {
		$this->assertSame( $expected, self::minorUnits( Money::of( $total, Currency::of( 'USD' ) )->allocate( $ratios ) ) );
	}

	/**
	 * Provides allocations with their expected shares.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{int, array<int|Decimal>, array<int>}> Test cases.
	 */
	public static function data_allocations(): array {
		return array(
			'a three-way tie goes to the first share'   => array( 100, array( 1, 1, 1 ), array( 34, 33, 33 ) ),
			'negative totals mirror positive ones'      => array( -100, array( 1, 1, 1 ), array( -34, -33, -33 ) ),
			'fewer units than shares'                   => array( 5, array( 1, 1, 1, 1, 1, 1 ), array( 1, 1, 1, 1, 1, 0 ) ),
			'a zero ratio gets zero'                    => array( 10, array( 0, 1, 1 ), array( 0, 5, 5 ) ),
			'a zero ratio gets zero even when first'    => array( 1, array( 0, 1 ), array( 0, 1 ) ),
			'exact proportions need no remainder'       => array( 7, array( 3, 0, 4 ), array( 3, 0, 4 ) ),
			'the largest remainder wins, not the first' => array( 10, array( 1, 2 ), array( 3, 7 ) ),
			'zero splits into zeros'                    => array( 0, array( 5, 3 ), array( 0, 0 ) ),
			'one share takes everything'                => array( 12345, array( 7 ), array( 12345 ) ),
			'decimal ratios'                            => array( 101, array( Decimal::of( '0.5' ), Decimal::of( '0.25' ), Decimal::of( '0.25' ) ), array( 51, 25, 25 ) ),
			'decimal ratios, larger remainder second'   => array( 10, array( Decimal::of( '1.1' ), Decimal::of( '2.2' ) ), array( 3, 7 ) ),
			'mixed integer and decimal ratios'          => array( 100, array( 1, Decimal::of( '1.000' ), Decimal::of( '0' ) ), array( 50, 50, 0 ) ),
		);
	}

	/**
	 * Tests that the ratios' keys are kept, in order.
	 *
	 * @since 0.1.0
	 */
	public function test_the_ratios_keys_are_kept(): void {
		$shares = Money::of( 3, Currency::of( 'EUR' ) )->allocate(
			array(
				'line-b' => 1,
				'line-a' => 1,
			)
		);

		$this->assertSame(
			array(
				'line-b' => 2,
				'line-a' => 1,
			),
			self::minorUnits( $shares ),
			'The first share by position wins the tie, whatever its key.'
		);
		$this->assertSame( 'EUR', $shares['line-a']->currency()->code() );
	}

	/**
	 * Tests the two ends of the 64-bit range, where the magnitude of the smallest total has no integer.
	 *
	 * @since 0.1.0
	 */
	public function test_the_edges_of_the_integer_range(): void {
		$usd = Currency::of( 'USD' );

		$this->assertSame( array( -4611686018427387904, -4611686018427387904 ), self::minorUnits( Money::of( PHP_INT_MIN, $usd )->allocate( array( 1, 1 ) ) ) );
		$this->assertSame( array( 4611686018427387904, 4611686018427387903 ), self::minorUnits( Money::of( PHP_INT_MAX, $usd )->allocate( array( 1, 1 ) ) ) );
		$this->assertSame( array( PHP_INT_MIN ), self::minorUnits( Money::of( PHP_INT_MIN, $usd )->allocate( array( 3 ) ) ) );
	}

	/**
	 * Tests that impossible ratios are refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_invalid_ratios
	 *
	 * @param array<int|Decimal> $ratios Ratios that cannot allocate anything.
	 */
	public function test_invalid_ratios_are_refused( array $ratios ): void {
		$this->expectException( \InvalidArgumentException::class );

		Money::of( 100, Currency::of( 'USD' ) )->allocate( $ratios );
	}

	/**
	 * Provides ratios that must be refused.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{array<int|Decimal>}> Test cases.
	 */
	public static function data_invalid_ratios(): array {
		return array(
			'no ratio'           => array( array() ),
			'every ratio zero'   => array( array( 0, 0, Decimal::of( '0.00' ) ) ),
			'a negative integer' => array( array( 1, -1 ) ),
			'a negative decimal' => array( array( 1, Decimal::of( '-0.5' ) ) ),
		);
	}

	/**
	 * Tests the allocation rule on seeded random totals and ratios, against exact integer arithmetic.
	 *
	 * Checked for every case: the shares sum to the total; each share is the floor of its exact
	 * proportion or one more; zero ratios get zero; the extra units went to the largest
	 * remainders with the earliest share winning a tie; the negated total allocates to the
	 * negated shares; and allocating twice gives the same result.
	 *
	 * @since 0.1.0
	 */
	public function test_property_largest_remainder_with_ordinal_tie_break(): void {
		SeededCases::check(
			20260930,
			400,
			static function ( Randomizer $random ): array {
				$count  = $random->getInt( 1, 12 );
				$ratios = array();

				for ( $share = 0; $share < $count; $share++ ) {
					// Small ratios make ties common; a zero ratio one time in four.
					$ratios[] = 0 === $random->getInt( 0, 3 ) ? 0 : $random->getInt( 1, 0 === $random->getInt( 0, 1 ) ? 5 : 1000 );
				}

				if ( 0 === array_sum( $ratios ) ) {
					$ratios[ $random->getInt( 0, $count - 1 ) ] = 1;
				}

				return array( $random->getInt( -1000000000000, 1000000000000 ), $ratios, $random->getInt( 0, 4 ) );
			},
			function ( int $total, array $ratios, int $scale ): void {
				$usd     = Currency::of( 'USD' );
				$decimal = array();

				foreach ( $ratios as $ratio ) {
					$decimal[] = Decimal::ofUnscaled( $ratio, $scale );
				}

				$shares = self::minorUnits( Money::of( $total, $usd )->allocate( $decimal ) );

				$this->assertSame( self::expectedShares( $total, $ratios ), $shares );
				$this->assertSame( $total, array_sum( $shares ) );
				$this->assertSame( self::negated( $shares ), self::minorUnits( Money::of( -$total, $usd )->allocate( $decimal ) ), 'A negative total mirrors the positive one.' );
				$this->assertSame( $shares, self::minorUnits( Money::of( $total, $usd )->allocate( $ratios ) ), 'Integer ratios and the same ratios as decimals allocate alike.' );
			}
		);
	}

	/**
	 * Computes the expected shares with integer arithmetic, as the class docblock describes.
	 *
	 * The asserted properties follow from this construction: every share is its floor or one
	 * more (so within one minor unit of its exact proportion), zero ratios have a floor and a
	 * remainder of zero and are never picked, and the extra units go to the largest remainders,
	 * the earliest first.
	 *
	 * @since 0.1.0
	 *
	 * @param int        $total  The amount in minor units, at most 10^12 in magnitude.
	 * @param array<int> $ratios The ratios, each at most 1000.
	 * @return array<int> The shares.
	 */
	private static function expectedShares( int $total, array $ratios ): array {
		$sum       = array_sum( $ratios );
		$magnitude = abs( $total );
		$floors    = array();
		$remainder = array();

		foreach ( $ratios as $index => $ratio ) {
			$floors[ $index ]    = intdiv( $magnitude * $ratio, $sum );
			$remainder[ $index ] = ( $magnitude * $ratio ) % $sum;
		}

		$left  = $magnitude - array_sum( $floors );
		$order = array_keys( $ratios );

		usort(
			$order,
			static function ( int $first, int $second ) use ( $remainder ): int {
				$by_remainder = $remainder[ $second ] <=> $remainder[ $first ];

				return 0 !== $by_remainder ? $by_remainder : $first <=> $second;
			}
		);

		foreach ( array_slice( $order, 0, $left ) as $index ) {
			++$floors[ $index ];
		}

		return $total < 0 ? self::negated( $floors ) : $floors;
	}

	/**
	 * Negates a list of integers.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, int> $values The integers.
	 * @return array<int|string, int> The negated integers, with the same keys.
	 */
	private static function negated( array $values ): array {
		return array_map( static fn( int $value ): int => -$value, $values );
	}

	/**
	 * Reads the minor units of a list of amounts.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int|string, Money> $shares The amounts.
	 * @return array<int|string, int> Their minor units, with the same keys.
	 */
	private static function minorUnits( array $shares ): array {
		return array_map( static fn( Money $share ): int => $share->minorUnits(), $shares );
	}
}
