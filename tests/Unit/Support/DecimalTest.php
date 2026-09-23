<?php
/**
 * Tests Decimal, the fixed-scale decimal arithmetic behind money
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Random\Randomizer;
use SEOCart\Support\ArithmeticOverflowException;
use SEOCart\Support\Decimal;
use SEOCart\Support\DigitArithmetic;
use SEOCart\Support\RoundingMode;
use SEOCart\Tests\Support\SeededCases;

/**
 * Proves that Decimal parses, prints, computes and rounds exactly.
 *
 * Expected values come from hand-computed tables, from exact PHP integer arithmetic on ranges
 * that integers represent exactly, and from algebraic identities over large numbers. Nothing
 * here uses a float, bcmath or gmp.
 *
 * @since 0.1.0
 */
final class DecimalTest extends TestCase {

	/**
	 * Tests that a decimal string is read and printed back in canonical form, keeping its scale.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_decimal_strings
	 *
	 * @param string $given     The string parsed.
	 * @param string $canonical The canonical form expected back.
	 * @param int    $scale     The scale expected.
	 */
	public function test_it_parses_and_prints_canonically( string $given, string $canonical, int $scale ): void {
		$decimal = Decimal::of( $given );

		$this->assertSame( $canonical, $decimal->toString() );
		$this->assertSame( $scale, $decimal->scale() );
		$this->assertSame( $canonical, Decimal::of( $canonical )->toString(), 'The canonical form reads back unchanged.' );
	}

	/**
	 * Provides decimal strings with their canonical forms and scales.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, int}> Test cases.
	 */
	public static function data_decimal_strings(): array {
		return array(
			'zero'                         => array( '0', '0', 0 ),
			'negative zero is zero'        => array( '-0', '0', 0 ),
			'negative zero keeps scale'    => array( '-0.00', '0.00', 2 ),
			'leading zeros are dropped'    => array( '007.50', '7.50', 2 ),
			'trailing zeros are the scale' => array( '1.500', '1.500', 3 ),
			'a fraction below one'         => array( '0.001', '0.001', 3 ),
			'negative fraction'            => array( '-0.050', '-0.050', 3 ),
			'negative integer'             => array( '-42', '-42', 0 ),
			'larger than 64 bits'          => array( '123456789012345678901234567890.123', '123456789012345678901234567890.123', 3 ),
		);
	}

	/**
	 * Tests that anything but a plain decimal number is refused.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_malformed_decimal_strings
	 *
	 * @param string $given A malformed string.
	 */
	public function test_a_malformed_string_is_refused( string $given ): void {
		$this->expectException( \InvalidArgumentException::class );

		Decimal::of( $given );
	}

	/**
	 * Provides strings that are not plain decimal numbers.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> Test cases.
	 */
	public static function data_malformed_decimal_strings(): array {
		return array(
			'empty'                => array( '' ),
			'sign only'            => array( '-' ),
			'plus sign'            => array( '+1' ),
			'dot without fraction' => array( '1.' ),
			'dot without integer'  => array( '.5' ),
			'exponent'             => array( '1e5' ),
			'leading space'        => array( ' 1' ),
			'trailing space'       => array( '1 ' ),
			'trailing line feed'   => array( "1\n" ),
			'decimal comma'        => array( '1,5' ),
			'two dots'             => array( '1.2.3' ),
			'two signs'            => array( '--1' ),
			'hexadecimal digits'   => array( '1F' ),
			'non-ASCII digit'      => array( "\u{0661}" ),
			'grouping separator'   => array( '1_000' ),
		);
	}

	/**
	 * Tests that an integer counted in units of a scale becomes the right decimal.
	 *
	 * @since 0.1.0
	 */
	public function test_of_unscaled_places_the_decimal_point(): void {
		$this->assertSame( '12.34', Decimal::ofUnscaled( 1234, 2 )->toString() );
		$this->assertSame( '-0.005', Decimal::ofUnscaled( -5, 3 )->toString() );
		$this->assertSame( '0.0000', Decimal::ofUnscaled( 0, 4 )->toString() );
		$this->assertSame( '7', Decimal::ofUnscaled( 7, 0 )->toString() );
		$this->assertSame( '-9223372036854775808', Decimal::ofUnscaled( PHP_INT_MIN, 0 )->toString(), 'The smallest integer has no positive counterpart, and must not be negated as an integer.' );
		$this->assertSame( '9.223372036854775807', Decimal::ofUnscaled( PHP_INT_MAX, 18 )->toString() );
	}

	/**
	 * Tests that a negative scale is refused wherever a scale is given.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_scale_is_refused(): void {
		$refusals = 0;
		$attempts = array(
			static fn() => Decimal::ofUnscaled( 1, -1 ),
			static fn() => Decimal::of( '1' )->divide( Decimal::of( '3' ), -1, RoundingMode::HalfUp ),
			static fn() => Decimal::of( '1' )->rescale( -1, RoundingMode::HalfUp ),
		);

		foreach ( $attempts as $attempt ) {
			try {
				$attempt();
			} catch ( \InvalidArgumentException $refused ) {
				++$refusals;
			}
		}

		$this->assertSame( count( $attempts ), $refusals );
	}

	/**
	 * Tests the scale rules of add, subtract and multiply on worked examples.
	 *
	 * @since 0.1.0
	 */
	public function test_add_subtract_and_multiply_are_exact_at_their_declared_scales(): void {
		$this->assertSame( '3.75', Decimal::of( '1.5' )->add( Decimal::of( '2.25' ) )->toString() );
		$this->assertSame( '-0.75', Decimal::of( '1.5' )->subtract( Decimal::of( '2.25' ) )->toString() );
		$this->assertSame( '0.0', Decimal::of( '-1.5' )->add( Decimal::of( '1.5' ) )->toString(), 'Zero has no sign and keeps the larger scale.' );
		$this->assertSame( '3.375', Decimal::of( '1.5' )->multiply( Decimal::of( '2.25' ) )->toString() );
		$this->assertSame( '-3.375', Decimal::of( '-1.5' )->multiply( Decimal::of( '2.25' ) )->toString() );
		$this->assertSame( '3.375', Decimal::of( '-1.5' )->multiply( Decimal::of( '-2.25' ) )->toString() );
		$this->assertSame( '0.000', Decimal::of( '-1.5' )->multiply( Decimal::of( '0.00' ) )->toString() );
		$this->assertSame(
			'15241578753238836750931260721575979.2834050',
			Decimal::of( '123456789012345678.901' )->multiply( Decimal::of( '123456789012345678.9050' ) )->toString(),
			'A product far beyond 64 bits.'
		);
	}

	/**
	 * Tests division to a stated scale on worked examples, both modes, both signs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_divisions
	 *
	 * @param string $dividend     The dividend.
	 * @param string $divisor      The divisor.
	 * @param int    $scale        The scale of the quotient.
	 * @param string $half_up      The quotient rounded half up.
	 * @param string $toward_zero  The quotient rounded toward zero.
	 */
	public function test_divide_rounds_the_quotient_once_at_the_stated_scale( string $dividend, string $divisor, int $scale, string $half_up, string $toward_zero ): void {
		$this->assertSame( $half_up, Decimal::of( $dividend )->divide( Decimal::of( $divisor ), $scale, RoundingMode::HalfUp )->toString() );
		$this->assertSame( $toward_zero, Decimal::of( $dividend )->divide( Decimal::of( $divisor ), $scale, RoundingMode::TowardZero )->toString() );
	}

	/**
	 * Provides divisions with their quotients in both modes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, int, string, string}> Test cases.
	 */
	public static function data_divisions(): array {
		return array(
			'one third'                     => array( '1', '3', 4, '0.3333', '0.3333' ),
			'two thirds'                    => array( '2', '3', 4, '0.6667', '0.6666' ),
			'minus two thirds'              => array( '-2', '3', 4, '-0.6667', '-0.6666' ),
			'a tie rounds away from zero'   => array( '10', '4', 0, '3', '2' ),
			'a negative tie too'            => array( '-10', '4', 0, '-3', '-2' ),
			'an eighth, negative divisor'   => array( '1', '-8', 2, '-0.13', '-0.12' ),
			'both negative'                 => array( '-1', '-8', 2, '0.13', '0.12' ),
			'exact'                         => array( '7.5', '2.5', 3, '3.000', '3.000' ),
			'zero dividend'                 => array( '0', '5', 2, '0.00', '0.00' ),
			'rounds to zero without a sign' => array( '-1', '1000', 2, '0.00', '0.00' ),
			'scales on both sides'          => array( '0.075', '1.2', 5, '0.06250', '0.06250' ),
			'divisor of more than 9 digits' => array( '123456789012345678901234567890', '9876543210987', 5, '12499999886094578.12656', '12499999886094578.12655' ),
		);
	}

	/**
	 * Tests that dividing by zero is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_division_by_zero_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );

		Decimal::of( '1' )->divide( Decimal::of( '0.000' ), 2, RoundingMode::HalfUp );
	}

	/**
	 * Tests rounding to a smaller scale, ties and negative values included.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider data_rescalings
	 *
	 * @param string $value       The value.
	 * @param int    $scale       The new scale.
	 * @param string $half_up     The value rounded half up.
	 * @param string $toward_zero The value rounded toward zero.
	 */
	public function test_rescale_rounds_ties_away_from_zero_or_truncates( string $value, int $scale, string $half_up, string $toward_zero ): void {
		$this->assertSame( $half_up, Decimal::of( $value )->rescale( $scale, RoundingMode::HalfUp )->toString(), 'half_up' );
		$this->assertSame( $toward_zero, Decimal::of( $value )->rescale( $scale, RoundingMode::TowardZero )->toString(), 'toward_zero' );
	}

	/**
	 * Provides rescalings, with ties on both sides of zero.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, int, string, string}> Test cases.
	 */
	public static function data_rescalings(): array {
		return array(
			'tie'                            => array( '2.5', 0, '3', '2' ),
			'negative tie'                   => array( '-2.5', 0, '-3', '-2' ),
			'just below a tie'               => array( '2.4999', 0, '2', '2' ),
			'just below a negative tie'      => array( '-2.4999', 0, '-2', '-2' ),
			'just above a tie'               => array( '2.5001', 0, '3', '2' ),
			'half'                           => array( '0.5', 0, '1', '0' ),
			'negative half'                  => array( '-0.5', 0, '-1', '0' ),
			'tie at two places'              => array( '1.005', 2, '1.01', '1.00' ),
			'negative tie at two places'     => array( '-1.005', 2, '-1.01', '-1.00' ),
			'rounds to zero, no sign left'   => array( '-0.004', 2, '0.00', '0.00' ),
			'carries into a new digit'       => array( '9.995', 2, '10.00', '9.99' ),
			'carries, negative'              => array( '-9.995', 2, '-10.00', '-9.99' ),
			'carries to a power of ten'      => array( '99.5', 0, '100', '99' ),
			'a larger scale appends zeros'   => array( '1.5', 3, '1.500', '1.500' ),
			'the same scale changes nothing' => array( '-1.25', 2, '-1.25', '-1.25' ),
		);
	}

	/**
	 * Tests comparison, equality and sign, across scales.
	 *
	 * @since 0.1.0
	 */
	public function test_compare_equals_and_sign_ignore_the_scale(): void {
		$this->assertTrue( Decimal::of( '1.50' )->equals( Decimal::of( '1.5' ) ) );
		$this->assertSame( 0, Decimal::of( '0' )->compare( Decimal::of( '-0.000' ) ) );
		$this->assertSame( -1, Decimal::of( '-2' )->compare( Decimal::of( '1' ) ) );
		$this->assertSame( 1, Decimal::of( '0' )->compare( Decimal::of( '-0.001' ) ) );
		$this->assertSame( -1, Decimal::of( '0' )->compare( Decimal::of( '0.001' ) ) );
		$this->assertSame( -1, Decimal::of( '-10' )->compare( Decimal::of( '-9.99' ) ) );
		$this->assertSame( 1, Decimal::of( '10.01' )->compare( Decimal::of( '10.001' ) ) );
		$this->assertSame( -1, Decimal::of( '-3.2' )->sign() );
		$this->assertSame( 0, Decimal::of( '-0.0' )->sign() );
		$this->assertSame( 1, Decimal::of( '0.01' )->sign() );
		$this->assertTrue( Decimal::of( '0.000' )->isZero() );
		$this->assertTrue( Decimal::of( '-0.1' )->isNegative() );
		$this->assertFalse( Decimal::of( '-0' )->isNegative() );
	}

	/**
	 * Tests negation, zero included.
	 *
	 * @since 0.1.0
	 */
	public function test_negate_flips_the_sign_and_keeps_the_scale(): void {
		$this->assertSame( '1.20', Decimal::of( '-1.20' )->negate()->toString() );
		$this->assertSame( '-1.20', Decimal::of( '1.20' )->negate()->toString() );
		$this->assertSame( '0.00', Decimal::of( '0.00' )->negate()->toString(), 'Zero stays unsigned.' );
	}

	/**
	 * Tests the handover to a PHP integer at both edges of the 64-bit range.
	 *
	 * @since 0.1.0
	 */
	public function test_to_unscaled_int_is_exact_and_refuses_overflow(): void {
		$this->assertSame( 1234, Decimal::of( '12.34' )->toUnscaledInt() );
		$this->assertSame( -5, Decimal::of( '-0.005' )->toUnscaledInt() );
		$this->assertSame( PHP_INT_MAX, Decimal::of( '9223372036854775807' )->toUnscaledInt() );
		$this->assertSame( PHP_INT_MIN, Decimal::of( '-9223372036854775808' )->toUnscaledInt() );

		foreach ( array( '9223372036854775808', '-9223372036854775809', '922337203685477580.80' ) as $too_large ) {
			try {
				Decimal::of( $too_large )->toUnscaledInt();
				$this->fail( $too_large . ' was handed to PHP as an integer.' );
			} catch ( ArithmeticOverflowException $overflow ) {
				$this->assertStringContainsString( 'Decimal::toUnscaledInt()', $overflow->getMessage() );
			}
		}
	}

	/**
	 * Tests that adding a value and subtracting it again gives back the original, for large values.
	 *
	 * @since 0.1.0
	 */
	public function test_property_adding_then_subtracting_is_the_identity(): void {
		SeededCases::check(
			20260922,
			400,
			static fn( Randomizer $random ): array => array(
				SeededCases::decimal( $random, 30, 12 ),
				SeededCases::decimal( $random, 30, 12 ),
			),
			function ( string $a, string $b ): void {
				$left  = Decimal::of( $a );
				$round = $left->add( Decimal::of( $b ) )->subtract( Decimal::of( $b ) );

				$this->assertTrue( $round->equals( $left ) );
				$this->assertSame( $left->toString(), $round->rescale( $left->scale(), RoundingMode::TowardZero )->toString() );
			}
		);
	}

	/**
	 * Tests that one is the identity of multiplication and does not change the scale.
	 *
	 * @since 0.1.0
	 */
	public function test_property_multiplying_by_one_is_the_identity(): void {
		SeededCases::check(
			20260923,
			300,
			static fn( Randomizer $random ): array => array( SeededCases::decimal( $random, 30, 12 ) ),
			function ( string $a ): void {
				$value = Decimal::of( $a );

				$this->assertSame( $value->toString(), $value->multiply( Decimal::of( '1' ) )->toString() );
				$this->assertTrue( $value->multiply( Decimal::of( '1.000' ) )->equals( $value ) );
			}
		);
	}

	/**
	 * Tests that addition and multiplication are commutative, to the last digit and scale.
	 *
	 * @since 0.1.0
	 */
	public function test_property_addition_and_multiplication_commute(): void {
		SeededCases::check(
			20260924,
			400,
			static fn( Randomizer $random ): array => array(
				SeededCases::decimal( $random, 25, 10 ),
				SeededCases::decimal( $random, 25, 10 ),
			),
			function ( string $a, string $b ): void {
				$left  = Decimal::of( $a );
				$right = Decimal::of( $b );

				$this->assertSame( $left->add( $right )->toString(), $right->add( $left )->toString() );
				$this->assertSame( $left->multiply( $right )->toString(), $right->multiply( $left )->toString() );
			}
		);
	}

	/**
	 * Tests that dividing a product by one factor, at the other's scale, gives back the other exactly.
	 *
	 * @since 0.1.0
	 */
	public function test_property_dividing_a_product_by_a_factor_gives_back_the_other_factor(): void {
		SeededCases::check(
			20260925,
			300,
			static fn( Randomizer $random ): array => array(
				SeededCases::decimal( $random, 25, 10 ),
				SeededCases::decimal( $random, 25, 10 ),
			),
			function ( string $a, string $b ): void {
				$left  = Decimal::of( $a );
				$right = Decimal::of( $b );

				if ( $right->isZero() ) {
					$right = Decimal::of( '7.3' );
				}

				$product = $left->multiply( $right );

				foreach ( RoundingMode::cases() as $mode ) {
					$this->assertSame( $left->toString(), $product->divide( $right, $left->scale(), $mode )->toString(), $mode->value );
				}
			}
		);
	}

	/**
	 * Tests addition, subtraction, multiplication and comparison against exact integer arithmetic.
	 *
	 * @since 0.1.0
	 */
	public function test_property_arithmetic_agrees_with_exact_integer_arithmetic(): void {
		SeededCases::check(
			20260926,
			500,
			static fn( Randomizer $random ): array => array(
				$random->getInt( -2147483648, 2147483648 ),
				$random->getInt( 0, 6 ),
				$random->getInt( -2147483648, 2147483648 ),
				$random->getInt( 0, 6 ),
			),
			function ( int $a, int $a_scale, int $b, int $b_scale ): void {
				$left  = Decimal::ofUnscaled( $a, $a_scale );
				$right = Decimal::ofUnscaled( $b, $b_scale );
				$scale = max( $a_scale, $b_scale );

				// Aligned to the larger scale, each operand stays below 2^31 × 10^6, far inside 64 bits.
				$a_aligned = $a * self::powerOfTen( $scale - $a_scale );
				$b_aligned = $b * self::powerOfTen( $scale - $b_scale );

				$this->assertSame( self::written( $a_aligned + $b_aligned, $scale ), $left->add( $right )->toString(), 'add' );
				$this->assertSame( self::written( $a_aligned - $b_aligned, $scale ), $left->subtract( $right )->toString(), 'subtract' );
				$this->assertSame( self::written( $a * $b, $a_scale + $b_scale ), $left->multiply( $right )->toString(), 'multiply' );
				$this->assertSame( $a_aligned <=> $b_aligned, $left->compare( $right ), 'compare' );
			}
		);
	}

	/**
	 * Tests division in both modes against exact integer division and remainder.
	 *
	 * @since 0.1.0
	 */
	public function test_property_division_agrees_with_exact_integer_arithmetic(): void {
		SeededCases::check(
			20260927,
			600,
			static fn( Randomizer $random ): array => array(
				$random->getInt( -1000000, 1000000 ),
				$random->getInt( 0, 4 ),
				$random->getInt( -1000000, 1000000 ),
				$random->getInt( 0, 4 ),
				$random->getInt( 0, 4 ),
			),
			function ( int $a, int $a_scale, int $b, int $b_scale, int $scale ): void {
				if ( 0 === $b ) {
					$b = 3;
				}

				// (a / 10^sa) / (b / 10^sb) × 10^s = a × 10^(sb - sa + s) / b, below 10^14 here.
				$shift       = $b_scale - $a_scale + $scale;
				$numerator   = abs( $a ) * self::powerOfTen( max( 0, $shift ) );
				$denominator = abs( $b ) * self::powerOfTen( max( 0, -$shift ) );
				$quotient    = intdiv( $numerator, $denominator );
				$remainder   = $numerator % $denominator;
				$sign        = ( $a < 0 ) !== ( $b < 0 ) ? -1 : 1;
				$half_up     = ( 2 * $remainder >= $denominator ) ? $quotient + 1 : $quotient;

				$dividend = Decimal::ofUnscaled( $a, $a_scale );
				$divisor  = Decimal::ofUnscaled( $b, $b_scale );

				$this->assertSame( self::written( $sign * $half_up, $scale ), $dividend->divide( $divisor, $scale, RoundingMode::HalfUp )->toString(), 'half_up' );
				$this->assertSame( self::written( $sign * $quotient, $scale ), $dividend->divide( $divisor, $scale, RoundingMode::TowardZero )->toString(), 'toward_zero' );
			}
		);
	}

	/**
	 * Tests rescaling in both modes against exact integer division and remainder.
	 *
	 * @since 0.1.0
	 */
	public function test_property_rescale_agrees_with_exact_integer_arithmetic(): void {
		SeededCases::check(
			20260928,
			600,
			static fn( Randomizer $random ): array => array(
				// At most 10^10, so that scaling up by 10^8 stays below 2^63.
				$random->getInt( -10000000000, 10000000000 ),
				$random->getInt( 0, 8 ),
				$random->getInt( 0, 8 ),
			),
			function ( int $a, int $from, int $to ): void {
				$value = Decimal::ofUnscaled( $a, $from );

				if ( $to >= $from ) {
					$expected_half_up     = self::written( $a * self::powerOfTen( $to - $from ), $to );
					$expected_toward_zero = $expected_half_up;
				} else {
					$divisor   = self::powerOfTen( $from - $to );
					$quotient  = intdiv( abs( $a ), $divisor );
					$remainder = abs( $a ) % $divisor;
					$sign      = $a < 0 ? -1 : 1;

					$expected_half_up     = self::written( $sign * ( 2 * $remainder >= $divisor ? $quotient + 1 : $quotient ), $to );
					$expected_toward_zero = self::written( $sign * $quotient, $to );
				}

				$this->assertSame( $expected_half_up, $value->rescale( $to, RoundingMode::HalfUp )->toString(), 'half_up' );
				$this->assertSame( $expected_toward_zero, $value->rescale( $to, RoundingMode::TowardZero )->toString(), 'toward_zero' );
			}
		);
	}

	/**
	 * Tests long division on large natural numbers: quotient × divisor + remainder = dividend.
	 *
	 * Divisors of up to nine digits take the limb-by-limb path; scaling the same division by
	 * 10^10 on both sides sends it down the digit-by-digit path, which must agree.
	 *
	 * @since 0.1.0
	 */
	public function test_property_long_division_is_exact_on_both_paths(): void {
		SeededCases::check(
			20260929,
			300,
			static fn( Randomizer $random ): array => array(
				SeededCases::digits( $random, 60 ),
				SeededCases::digits( $random, 40 ),
			),
			function ( string $dividend, string $divisor ): void {
				if ( '0' === $divisor ) {
					$divisor = '97';
				}

				list( $quotient, $remainder ) = DigitArithmetic::divide( $dividend, $divisor );

				$this->assertMatchesRegularExpression( '/^(?:0|[1-9][0-9]*)$/', $quotient, 'The quotient is a canonical digit string.' );
				$this->assertMatchesRegularExpression( '/^(?:0|[1-9][0-9]*)$/', $remainder, 'The remainder is a canonical digit string.' );
				$this->assertSame( $dividend, DigitArithmetic::add( DigitArithmetic::multiply( $quotient, $divisor ), $remainder ) );
				$this->assertSame( -1, DigitArithmetic::compare( $remainder, $divisor ), 'The remainder is smaller than the divisor.' );

				list( $long_quotient ) = DigitArithmetic::divide( DigitArithmetic::shift( $dividend, 10 ), DigitArithmetic::shift( $divisor, 10 ) );

				$this->assertSame( $quotient, $long_quotient, 'Both division paths give the same quotient.' );
			}
		);
	}

	/**
	 * Tests the natural-number operations on their edges: carries, borrows and zero.
	 *
	 * @since 0.1.0
	 */
	public function test_digit_arithmetic_carries_and_borrows_across_limbs(): void {
		$this->assertSame( '10000000', DigitArithmetic::add( '9999999', '1' ) );
		$this->assertSame( '100000000000000000000', DigitArithmetic::add( '99999999999999999999', '1' ) );
		$this->assertSame( '9999999', DigitArithmetic::subtract( '10000000', '1' ) );
		$this->assertSame( '0', DigitArithmetic::subtract( '123456789012345', '123456789012345' ) );
		$this->assertSame( '99999980000001', DigitArithmetic::multiply( '9999999', '9999999' ) );
		$this->assertSame( '0', DigitArithmetic::multiply( '0', '123' ) );
		$this->assertSame( array( '0', '5' ), DigitArithmetic::divide( '5', '12345678901' ) );
		$this->assertSame( array( '1', '0' ), DigitArithmetic::divide( '12345678901', '12345678901' ) );
		$this->assertSame( -1, DigitArithmetic::compare( '99', '100' ) );
		$this->assertSame( 1, DigitArithmetic::compare( '100', '99' ) );
		$this->assertSame( 0, DigitArithmetic::compare( '42', '42' ) );

		$this->expectException( \InvalidArgumentException::class );

		DigitArithmetic::subtract( '1', '2' );
	}

	/**
	 * Writes an integer counted in units of 10 to the minus scale as a decimal string.
	 *
	 * @since 0.1.0
	 *
	 * @param int $unscaled The integer.
	 * @param int $scale    The scale.
	 * @return string The decimal string.
	 */
	private static function written( int $unscaled, int $scale ): string {
		$digits = ltrim( (string) $unscaled, '-' );

		if ( $scale > 0 ) {
			$digits = str_pad( $digits, $scale + 1, '0', STR_PAD_LEFT );
			$digits = substr( $digits, 0, -$scale ) . '.' . substr( $digits, -$scale );
		}

		return ( $unscaled < 0 ? '-' : '' ) . $digits;
	}

	/**
	 * Returns 10 to a small power as an integer.
	 *
	 * @since 0.1.0
	 *
	 * @param int $exponent The exponent, from 0 to 18.
	 * @return int The power.
	 */
	private static function powerOfTen( int $exponent ): int {
		$power = 1;

		for ( $step = 0; $step < $exponent; $step++ ) {
			$power *= 10;
		}

		return $power;
	}
}
