<?php
/**
 * Decimal: an exact decimal number of any size, at a declared scale
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable, exact decimal number with a fixed number of fractional digits.
 *
 * This class owns one fact: the value of an intermediate amount before it is rounded to minor
 * units, and exact arithmetic on it. Percentages, inclusive-tax extraction, currency
 * conversion and allocation all need more precision than a minor unit before their declared
 * rounding point (ADR-0004); they compute with Decimal and turn the result into Money only
 * where the pipeline declares a rounding boundary.
 *
 * The scale is the number of fractional digits and is part of the value's identity as a
 * string: '1.50' has scale 2 and prints as '1.50'. The rules:
 *
 * - add() and subtract() are exact at the larger of the two scales.
 * - multiply() is exact at the sum of the two scales.
 * - divide() and rescale() to a smaller scale must name a RoundingMode; nothing rounds
 *   implicitly. rescale() to a larger scale is exact and ignores the mode.
 * - compare() and equals() compare values, so '1.50' equals '1.5'.
 *
 * The digits are kept as a string of any length, so no operation can overflow, and nothing
 * here uses a float, bcmath, gmp or intl. Only toUnscaledInt(), which hands the value to PHP
 * as an integer, can overflow; it checks and throws.
 *
 * @since 0.1.0
 */
final class Decimal {

	/**
	 * The absolute value without the decimal point, as a canonical digit string.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $magnitude;

	/**
	 * Whether the value is below zero. Zero is never negative.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $negative;

	/**
	 * The number of fractional digits.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $scale;

	/**
	 * Creates a decimal from its parts.
	 *
	 * @since 0.1.0
	 *
	 * @param string $magnitude A canonical digit string: the absolute value times 10 to the scale.
	 * @param bool   $negative  Whether the value is below zero; ignored for zero.
	 * @param int    $scale     The number of fractional digits, zero or more.
	 */
	private function __construct( string $magnitude, bool $negative, int $scale ) {
		$this->magnitude = $magnitude;
		$this->negative  = $negative && '0' !== $magnitude;
		$this->scale     = $scale;
	}

	/**
	 * Parses a decimal string.
	 *
	 * The scale is the number of digits written after the dot, so '2.50' has scale 2. Leading
	 * zeros are allowed and dropped; '-0' is zero.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the string is not a plain decimal number.
	 *
	 * @param string $value ASCII digits with an optional leading minus sign and an optional
	 *                      fraction after a dot, for example '-12.345'. No plus sign, exponent,
	 *                      grouping or white space.
	 * @return self The decimal.
	 */
	public static function of( string $value ): self {
		if ( 1 !== preg_match( '/^(-?)([0-9]+)(?:\.([0-9]+))?\z/', $value, $parts ) ) {
			throw new \InvalidArgumentException( 'A decimal is written as ASCII digits with an optional leading minus sign and an optional fraction after a dot, for example -12.345.' );
		}

		$fraction = $parts[3] ?? '';

		return new self( DigitArithmetic::canonical( $parts[2] . $fraction ), '-' === $parts[1], strlen( $fraction ) );
	}

	/**
	 * Creates the decimal that an integer counts in units of 10 to the minus scale.
	 *
	 * Decimal::ofUnscaled( 1234, 2 ) is 12.34, and Decimal::ofUnscaled( 5, 0 ) is 5.
	 *
	 * @since 0.1.0
	 *
	 * @param int $unscaled The value times 10 to the scale.
	 * @param int $scale    The number of fractional digits, zero or more.
	 * @return self The decimal.
	 */
	public static function ofUnscaled( int $unscaled, int $scale ): self {
		self::assertScale( $scale );

		$digits   = (string) $unscaled;
		$negative = $unscaled < 0;

		return new self( $negative ? substr( $digits, 1 ) : $digits, $negative, $scale );
	}

	/**
	 * Returns the number of fractional digits.
	 *
	 * @since 0.1.0
	 *
	 * @return int The scale, zero or more.
	 */
	public function scale(): int {
		return $this->scale;
	}

	/**
	 * Returns the sign of the value.
	 *
	 * @since 0.1.0
	 *
	 * @return int -1 below zero, 0 for zero, 1 above zero.
	 */
	public function sign(): int {
		if ( '0' === $this->magnitude ) {
			return 0;
		}

		return $this->negative ? -1 : 1;
	}

	/**
	 * Tells whether the value is zero, at any scale.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True for zero.
	 */
	public function isZero(): bool {
		return '0' === $this->magnitude;
	}

	/**
	 * Tells whether the value is below zero.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True below zero.
	 */
	public function isNegative(): bool {
		return $this->negative;
	}

	/**
	 * Compares two values, regardless of their scales.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $other The value to compare with.
	 * @return int -1, 0 or 1 as this value is smaller than, equal to or larger than the other.
	 */
	public function compare( Decimal $other ): int {
		if ( $this->negative !== $other->negative ) {
			return $this->negative ? -1 : 1;
		}

		$scale      = max( $this->scale, $other->scale );
		$comparison = DigitArithmetic::compare( $this->magnitudeAt( $scale ), $other->magnitudeAt( $scale ) );

		return $this->negative ? -$comparison : $comparison;
	}

	/**
	 * Tells whether two values are equal, regardless of their scales.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $other The value to compare with.
	 * @return bool True when the values are equal: '1.50' equals '1.5'.
	 */
	public function equals( Decimal $other ): bool {
		return 0 === $this->compare( $other );
	}

	/**
	 * Adds a value, exactly.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $other The value to add.
	 * @return self The sum, at the larger of the two scales.
	 */
	public function add( Decimal $other ): self {
		$scale = max( $this->scale, $other->scale );

		list( $magnitude, $negative ) = self::signedSum(
			$this->magnitudeAt( $scale ),
			$this->negative,
			$other->magnitudeAt( $scale ),
			$other->negative
		);

		return new self( $magnitude, $negative, $scale );
	}

	/**
	 * Subtracts a value, exactly.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $other The value to subtract.
	 * @return self The difference, at the larger of the two scales.
	 */
	public function subtract( Decimal $other ): self {
		return $this->add( $other->negate() );
	}

	/**
	 * Multiplies by a value, exactly.
	 *
	 * @since 0.1.0
	 *
	 * @param Decimal $other The value to multiply by.
	 * @return self The product, at the sum of the two scales.
	 */
	public function multiply( Decimal $other ): self {
		return new self(
			DigitArithmetic::multiply( $this->magnitude, $other->magnitude ),
			$this->negative !== $other->negative,
			$this->scale + $other->scale
		);
	}

	/**
	 * Divides by a value and rounds the quotient once, to a stated scale.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the divisor is zero or the scale is negative.
	 *
	 * @param Decimal      $divisor The value to divide by; not zero.
	 * @param int          $scale   The number of fractional digits of the quotient.
	 * @param RoundingMode $mode    How the quotient loses the digits beyond that scale.
	 * @return self The quotient.
	 */
	public function divide( Decimal $divisor, int $scale, RoundingMode $mode ): self {
		self::assertScale( $scale );

		if ( $divisor->isZero() ) {
			throw new \InvalidArgumentException( 'Division by zero.' );
		}

		// Quotient digits = this / divisor * 10^scale, computed as one integer division.
		$shift       = $divisor->scale - $this->scale + $scale;
		$numerator   = DigitArithmetic::shift( $this->magnitude, max( 0, $shift ) );
		$denominator = DigitArithmetic::shift( $divisor->magnitude, max( 0, -$shift ) );

		return new self(
			self::roundedQuotient( $numerator, $denominator, $mode ),
			$this->negative !== $divisor->negative,
			$scale
		);
	}

	/**
	 * Changes the number of fractional digits.
	 *
	 * A larger scale appends zeros and never rounds. A smaller scale rounds once, by the mode.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $scale The new number of fractional digits, zero or more.
	 * @param RoundingMode $mode  How the value loses digits when the scale shrinks.
	 * @return self The value at the new scale.
	 */
	public function rescale( int $scale, RoundingMode $mode ): self {
		self::assertScale( $scale );

		if ( $scale >= $this->scale ) {
			return new self( DigitArithmetic::shift( $this->magnitude, $scale - $this->scale ), $this->negative, $scale );
		}

		$divisor = DigitArithmetic::powerOfTen( $this->scale - $scale );

		return new self( self::roundedQuotient( $this->magnitude, $divisor, $mode ), $this->negative, $scale );
	}

	/**
	 * Returns the value with the opposite sign.
	 *
	 * @since 0.1.0
	 *
	 * @return self The negated value, at the same scale. Zero stays zero.
	 */
	public function negate(): self {
		return new self( $this->magnitude, ! $this->negative, $this->scale );
	}

	/**
	 * Returns the value times 10 to its scale, as a PHP integer.
	 *
	 * Decimal::of( '12.34' )->toUnscaledInt() is 1234. Rescale first to choose the unit.
	 *
	 * @since 0.1.0
	 *
	 * @throws ArithmeticOverflowException When the integer does not fit in 64 bits.
	 *
	 * @return int The unscaled value.
	 */
	public function toUnscaledInt(): int {
		$limit = $this->negative ? '9223372036854775808' : '9223372036854775807';

		if ( DigitArithmetic::compare( $this->magnitude, $limit ) > 0 ) {
			throw ArithmeticOverflowException::in( 'Decimal::toUnscaledInt()' );
		}

		return (int) ( $this->negative ? '-' . $this->magnitude : $this->magnitude );
	}

	/**
	 * Returns the canonical string form.
	 *
	 * A minus sign only below zero, no leading zeros beyond one before the dot, and exactly
	 * `scale` digits after it: '-0.050', '12', '0.00'.
	 *
	 * @since 0.1.0
	 *
	 * @return string The decimal, as Decimal::of() reads it back.
	 */
	public function toString(): string {
		$digits = $this->magnitude;

		if ( $this->scale > 0 ) {
			$digits = str_pad( $digits, $this->scale + 1, '0', STR_PAD_LEFT );
			$digits = substr( $digits, 0, -$this->scale ) . '.' . substr( $digits, -$this->scale );
		}

		return ( $this->negative ? '-' : '' ) . $digits;
	}

	/**
	 * Returns the magnitude expressed at a scale no smaller than the current one.
	 *
	 * @since 0.1.0
	 *
	 * @param int $scale The scale, at least $this->scale.
	 * @return string The magnitude times 10 to the difference in scale.
	 */
	private function magnitudeAt( int $scale ): string {
		return DigitArithmetic::shift( $this->magnitude, $scale - $this->scale );
	}

	/**
	 * Adds two signed magnitudes at the same scale.
	 *
	 * @since 0.1.0
	 *
	 * @param string $left          A canonical digit string.
	 * @param bool   $left_negative Whether the left operand is negative.
	 * @param string $right         A canonical digit string.
	 * @param bool   $right_negative Whether the right operand is negative.
	 * @return array{0: string, 1: bool} The magnitude and the sign of the sum.
	 */
	private static function signedSum( string $left, bool $left_negative, string $right, bool $right_negative ): array {
		if ( $left_negative === $right_negative ) {
			return array( DigitArithmetic::add( $left, $right ), $left_negative );
		}

		$comparison = DigitArithmetic::compare( $left, $right );

		if ( 0 === $comparison ) {
			return array( '0', false );
		}

		if ( $comparison > 0 ) {
			return array( DigitArithmetic::subtract( $left, $right ), $left_negative );
		}

		return array( DigitArithmetic::subtract( $right, $left ), $right_negative );
	}

	/**
	 * Divides two magnitudes and rounds the quotient's magnitude by a mode.
	 *
	 * Every mode here is symmetric: it acts on the magnitude, so the sign is applied after
	 * rounding and rounding a negated value gives the negated result.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $numerator   A canonical digit string.
	 * @param string       $denominator A canonical digit string other than '0'.
	 * @param RoundingMode $mode        The rounding mode.
	 * @return string The rounded magnitude of the quotient.
	 */
	private static function roundedQuotient( string $numerator, string $denominator, RoundingMode $mode ): string {
		list( $quotient, $remainder ) = DigitArithmetic::divide( $numerator, $denominator );

		if ( '0' === $remainder ) {
			return $quotient;
		}

		$away_from_zero = match ( $mode ) {
			RoundingMode::TowardZero => false,
			RoundingMode::HalfUp     => DigitArithmetic::compare( DigitArithmetic::add( $remainder, $remainder ), $denominator ) >= 0,
		};

		return $away_from_zero ? DigitArithmetic::add( $quotient, '1' ) : $quotient;
	}

	/**
	 * Refuses a negative scale.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the scale is negative.
	 *
	 * @param int $scale The scale to check.
	 */
	private static function assertScale( int $scale ): void {
		if ( $scale < 0 ) {
			throw new \InvalidArgumentException( 'A decimal scale is the number of fractional digits and cannot be negative.' );
		}
	}
}
