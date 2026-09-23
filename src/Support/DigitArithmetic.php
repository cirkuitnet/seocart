<?php
/**
 * DigitArithmetic: exact arithmetic on natural numbers written as decimal digit strings
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, subtracts, multiplies and divides natural numbers of any size.
 *
 * This class owns one fact: how to do exact integer arithmetic without floats and without
 * the bcmath, gmp or intl extensions, none of which a WordPress host is guaranteed to load.
 * Decimal is its only caller; nothing else should need it.
 *
 * Every operand and every result is a canonical digit string: ASCII digits, no sign, no
 * leading zero, and '0' for zero. The work is done in limbs of seven digits. A limb is below
 * 10^7, so the product of two limbs plus a limb and a carry stays below 10^15, far from
 * PHP_INT_MAX: no intermediate can overflow into a float.
 *
 * @internal
 *
 * @since 0.1.0
 */
final class DigitArithmetic {

	/**
	 * How many decimal digits one limb holds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LIMB_DIGITS = 7;

	/**
	 * The base of a limb: 10 to the power of LIMB_DIGITS.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const LIMB = 10000000;

	/**
	 * The longest divisor, in digits, that is divided limb by limb with PHP integers.
	 *
	 * A divisor below 10^9 leaves a remainder below 10^9, and a remainder times LIMB plus a limb
	 * stays below 10^16. Longer divisors take the digit-by-digit path.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SHORT_DIVISOR_DIGITS = 9;

	/**
	 * Tells whether a string is a canonical digit string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits The string to check.
	 * @return bool True for ASCII digits with no leading zero, or '0'.
	 */
	public static function isCanonical( string $digits ): bool {
		return 1 === preg_match( '/^(?:0|[1-9][0-9]*)\z/', $digits );
	}

	/**
	 * Removes leading zeros, keeping '0' for zero.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits ASCII digits, possibly with leading zeros.
	 * @return string The canonical digit string.
	 */
	public static function canonical( string $digits ): string {
		$stripped = ltrim( $digits, '0' );

		return '' === $stripped ? '0' : $stripped;
	}

	/**
	 * Compares two natural numbers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $left  A canonical digit string.
	 * @param string $right A canonical digit string.
	 * @return int -1, 0 or 1 as the left number is smaller than, equal to or larger than the right.
	 */
	public static function compare( string $left, string $right ): int {
		$by_length = strlen( $left ) <=> strlen( $right );

		if ( 0 !== $by_length ) {
			return $by_length;
		}

		return strcmp( $left, $right ) <=> 0;
	}

	/**
	 * Adds two natural numbers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $left  A canonical digit string.
	 * @param string $right A canonical digit string.
	 * @return string The sum.
	 */
	public static function add( string $left, string $right ): string {
		$augend = self::toLimbs( $left );
		$addend = self::toLimbs( $right );
		$count  = max( count( $augend ), count( $addend ) );
		$sum    = array();
		$carry  = 0;

		for ( $index = 0; $index < $count; $index++ ) {
			$limb  = ( $augend[ $index ] ?? 0 ) + ( $addend[ $index ] ?? 0 ) + $carry;
			$carry = intdiv( $limb, self::LIMB );
			$sum[] = $limb % self::LIMB;
		}

		if ( $carry > 0 ) {
			$sum[] = $carry;
		}

		return self::fromLimbs( $sum );
	}

	/**
	 * Subtracts a natural number from one at least as large.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the result would be negative.
	 *
	 * @param string $minuend    A canonical digit string.
	 * @param string $subtrahend A canonical digit string no larger than the minuend.
	 * @return string The difference.
	 */
	public static function subtract( string $minuend, string $subtrahend ): string {
		if ( self::compare( $minuend, $subtrahend ) < 0 ) {
			throw new \InvalidArgumentException( 'DigitArithmetic::subtract() works on natural numbers: the subtrahend may not exceed the minuend.' );
		}

		$from       = self::toLimbs( $minuend );
		$take       = self::toLimbs( $subtrahend );
		$difference = array();
		$borrow     = 0;

		foreach ( $from as $index => $limb ) {
			$limb  -= ( $take[ $index ] ?? 0 ) + $borrow;
			$borrow = $limb < 0 ? 1 : 0;

			$difference[] = $limb + $borrow * self::LIMB;
		}

		return self::fromLimbs( $difference );
	}

	/**
	 * Multiplies two natural numbers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $left  A canonical digit string.
	 * @param string $right A canonical digit string.
	 * @return string The product.
	 */
	public static function multiply( string $left, string $right ): string {
		if ( '0' === $left || '0' === $right ) {
			return '0';
		}

		$multiplicand = self::toLimbs( $left );
		$multiplier   = self::toLimbs( $right );
		$product      = array_fill( 0, count( $multiplicand ) + count( $multiplier ), 0 );

		foreach ( $multiplicand as $row => $factor ) {
			$carry = 0;

			foreach ( $multiplier as $column => $other ) {
				// Each cell is below 10^7 before this line, so the sum stays below 10^15.
				$cell                      = $product[ $row + $column ] + $factor * $other + $carry;
				$carry                     = intdiv( $cell, self::LIMB );
				$product[ $row + $column ] = $cell % self::LIMB;
			}

			for ( $position = $row + count( $multiplier ); $carry > 0; $position++ ) {
				$cell                 = ( $product[ $position ] ?? 0 ) + $carry;
				$carry                = intdiv( $cell, self::LIMB );
				$product[ $position ] = $cell % self::LIMB;
			}
		}

		return self::fromLimbs( $product );
	}

	/**
	 * Divides one natural number by another, returning the quotient and the remainder.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the divisor is zero.
	 *
	 * @param string $dividend A canonical digit string.
	 * @param string $divisor  A canonical digit string other than '0'.
	 * @return array{0: string, 1: string} The quotient, rounded toward zero, and the remainder.
	 */
	public static function divide( string $dividend, string $divisor ): array {
		if ( '0' === $divisor ) {
			throw new \InvalidArgumentException( 'Division by zero.' );
		}

		if ( self::compare( $dividend, $divisor ) < 0 ) {
			return array( '0', $dividend );
		}

		if ( strlen( $divisor ) <= self::SHORT_DIVISOR_DIGITS ) {
			return self::divideByShort( $dividend, (int) $divisor );
		}

		return self::divideByLong( $dividend, $divisor );
	}

	/**
	 * Multiplies a natural number by a power of ten.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits A canonical digit string.
	 * @param int    $places The power of ten, zero or more.
	 * @return string The product.
	 */
	public static function shift( string $digits, int $places ): string {
		if ( '0' === $digits || $places <= 0 ) {
			return $digits;
		}

		return $digits . str_repeat( '0', $places );
	}

	/**
	 * Returns a power of ten.
	 *
	 * @since 0.1.0
	 *
	 * @param int $exponent The exponent, zero or more.
	 * @return string 10 to the power of the exponent.
	 */
	public static function powerOfTen( int $exponent ): string {
		return '1' . str_repeat( '0', max( 0, $exponent ) );
	}

	/**
	 * Divides by a divisor that fits the short path, one limb at a time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dividend A canonical digit string.
	 * @param int    $divisor  A divisor from 1 to 999999999.
	 * @return array{0: string, 1: string} The quotient and the remainder.
	 */
	private static function divideByShort( string $dividend, int $divisor ): array {
		$limbs     = self::toLimbs( $dividend );
		$quotient  = array();
		$remainder = 0;

		for ( $index = count( $limbs ) - 1; $index >= 0; $index-- ) {
			$current    = $remainder * self::LIMB + $limbs[ $index ];
			$quotient[] = intdiv( $current, $divisor );
			$remainder  = $current % $divisor;
		}

		return array( self::fromLimbs( array_reverse( $quotient ) ), (string) $remainder );
	}

	/**
	 * Divides by a long divisor, one decimal digit of the quotient at a time.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dividend A canonical digit string.
	 * @param string $divisor  A canonical digit string longer than SHORT_DIVISOR_DIGITS.
	 * @return array{0: string, 1: string} The quotient and the remainder.
	 */
	private static function divideByLong( string $dividend, string $divisor ): array {
		$multiples = array( '0' );

		for ( $factor = 1; $factor <= 9; $factor++ ) {
			$multiples[ $factor ] = self::add( $multiples[ $factor - 1 ], $divisor );
		}

		$quotient  = '';
		$remainder = '0';
		$length    = strlen( $dividend );

		for ( $position = 0; $position < $length; $position++ ) {
			$digit     = $dividend[ $position ];
			$remainder = '0' === $remainder ? $digit : $remainder . $digit;
			$factor    = 9;

			while ( $factor > 0 && self::compare( $multiples[ $factor ], $remainder ) > 0 ) {
				--$factor;
			}

			if ( $factor > 0 ) {
				$remainder = self::subtract( $remainder, $multiples[ $factor ] );
			}

			$quotient .= (string) $factor;
		}

		return array( self::canonical( $quotient ), $remainder );
	}

	/**
	 * Splits a canonical digit string into limbs, least significant first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits A canonical digit string.
	 * @return list<int> The limbs.
	 */
	private static function toLimbs( string $digits ): array {
		$limbs = array();

		for ( $end = strlen( $digits ); $end > 0; $end -= self::LIMB_DIGITS ) {
			$start   = max( 0, $end - self::LIMB_DIGITS );
			$limbs[] = (int) substr( $digits, $start, $end - $start );
		}

		return $limbs;
	}

	/**
	 * Joins limbs, least significant first, into a canonical digit string.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, int> $limbs The limbs, each from 0 to LIMB - 1, in ascending key order.
	 * @return string The canonical digit string.
	 */
	private static function fromLimbs( array $limbs ): string {
		$digits = '';

		foreach ( $limbs as $limb ) {
			$digits = str_pad( (string) $limb, self::LIMB_DIGITS, '0', STR_PAD_LEFT ) . $digits;
		}

		return self::canonical( $digits );
	}
}
