<?php
/**
 * SeededCases: property-style checks over inputs drawn from a fixed seed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use PHPUnit\Framework\Assert;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;

/**
 * Runs a property against many generated inputs, deterministically.
 *
 * The inputs come from a seeded Xoshiro256** engine, so every run draws the same cases and a
 * failure can be replayed. When the property fails — an assertion or any other exception —
 * the test fails with the seed, the case number and the input that broke it, so the case can
 * be turned into a fixed example.
 *
 *     SeededCases::check(
 *         20260922,
 *         500,
 *         static fn( Randomizer $random ): array => array( SeededCases::decimal( $random, 20, 6 ) ),
 *         function ( string $value ): void { ... assertions ... }
 *     );
 *
 * @since 0.1.0
 */
final class SeededCases {

	/**
	 * Checks a property against generated inputs.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $seed     The seed. Fixed in the test, so every run draws the same inputs.
	 * @param int      $cases    How many inputs to draw.
	 * @param callable $draw     Draws one input from the Randomizer, as a list of arguments.
	 * @param callable $property Receives the arguments and asserts the property.
	 *
	 * @phpstan-param callable(Randomizer): list<mixed> $draw
	 */
	public static function check( int $seed, int $cases, callable $draw, callable $property ): void {
		$random = new Randomizer( new Xoshiro256StarStar( $seed ) );

		for ( $case = 1; $case <= $cases; $case++ ) {
			$input = $draw( $random );

			try {
				$property( ...$input );
			} catch ( \Throwable $failure ) {
				Assert::fail(
					sprintf(
						"The property failed on case %d of seed %d.\nInput: %s\n%s: %s",
						$case,
						$seed,
						self::describe( $input ),
						get_class( $failure ),
						$failure->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Draws an integer.
	 *
	 * @since 0.1.0
	 *
	 * @param Randomizer $random  The source.
	 * @param int        $minimum The smallest value.
	 * @param int        $maximum The largest value.
	 * @return int The integer.
	 */
	public static function int( Randomizer $random, int $minimum, int $maximum ): int {
		return $random->getInt( $minimum, $maximum );
	}

	/**
	 * Draws a canonical digit string: no sign, no leading zero, '0' for zero.
	 *
	 * @since 0.1.0
	 *
	 * @param Randomizer $random         The source.
	 * @param int        $maximum_digits The most digits, one or more.
	 * @return string The digits.
	 */
	public static function digits( Randomizer $random, int $maximum_digits ): string {
		$digits = ltrim( $random->getBytesFromString( '0123456789', $random->getInt( 1, $maximum_digits ) ), '0' );

		return '' === $digits ? '0' : $digits;
	}

	/**
	 * Draws a decimal string, as Decimal::of() reads it.
	 *
	 * @since 0.1.0
	 *
	 * @param Randomizer $random                 The source.
	 * @param int        $maximum_integer_digits The most digits before the dot.
	 * @param int        $maximum_scale          The most digits after it.
	 * @param bool       $signed                 Optional. Whether a minus sign may be drawn. Default true.
	 * @return string The decimal string, for example '-1234.0560'.
	 */
	public static function decimal( Randomizer $random, int $maximum_integer_digits, int $maximum_scale, bool $signed = true ): string {
		$value = self::digits( $random, $maximum_integer_digits );
		$scale = $random->getInt( 0, $maximum_scale );

		if ( $scale > 0 ) {
			$value .= '.' . $random->getBytesFromString( '0123456789', $scale );
		}

		return ( $signed && 1 === $random->getInt( 0, 1 ) ? '-' : '' ) . $value;
	}

	/**
	 * Describes an input for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed[] $input The arguments of one case.
	 * @return string The arguments, readable.
	 */
	private static function describe( array $input ): string {
		return implode( ', ', array_map( array( self::class, 'describeValue' ), $input ) );
	}

	/**
	 * Describes one value for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @return string The value, readable.
	 */
	private static function describeValue( $value ): string {
		if ( $value instanceof Decimal ) {
			return 'Decimal(' . $value->toString() . ')';
		}

		if ( $value instanceof Money ) {
			return 'Money(' . $value->currency()->code() . ' ' . $value->minorUnits() . ')';
		}

		if ( is_array( $value ) ) {
			$items = array();

			foreach ( $value as $key => $item ) {
				$items[] = var_export( $key, true ) . ' => ' . self::describeValue( $item );
			}

			return 'array(' . implode( ', ', $items ) . ')';
		}

		if ( is_object( $value ) ) {
			return get_class( $value );
		}

		return var_export( $value, true );
	}
}
