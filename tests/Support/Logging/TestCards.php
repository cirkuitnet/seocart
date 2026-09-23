<?php
/**
 * TestCards: card numbers for tests, generated with a check digit computed independently of the code under test
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Logging;

use Random\Randomizer;
use SEOCart\Tests\Support\SeededCases;

/**
 * Draws card-shaped numbers that no card network issued.
 *
 * Owns one fact: how a test makes a number with a valid Luhn check digit. The check digit is
 * computed here, by the textbook rule, and never by CardNumbers, so a broken detector cannot
 * generate the very numbers it then fails to find. Every number starts with PREFIX: no card
 * issuer's numbers start with it, so a generated number is obviously fake wherever a failing
 * test prints it. The detector does not look at the prefix.
 *
 * @since 0.1.0
 */
final class TestCards {

	/**
	 * What every generated number starts with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PREFIX = '000';

	/**
	 * Draws a number of the given length range with a valid check digit, starting with PREFIX.
	 *
	 * @since 0.1.0
	 *
	 * @param Randomizer $random    The source.
	 * @param int        $minDigits The fewest digits.
	 * @param int        $maxDigits The most digits.
	 * @return string The digits.
	 */
	public static function draw( Randomizer $random, int $minDigits, int $maxDigits ): string {
		$length = SeededCases::int( $random, $minDigits, $maxDigits );
		$body   = self::PREFIX;

		for ( $position = strlen( self::PREFIX ) + 1; $position < $length; $position++ ) {
			$body .= (string) SeededCases::int( $random, 0, 9 );
		}

		return self::withCheckDigit( $body );
	}

	/**
	 * Appends the digit that makes a number pass the Luhn checksum.
	 *
	 * Doubling starts from the last digit of the body, because the check digit will stand to its
	 * right; the check digit is what brings the sum to a multiple of ten.
	 *
	 * @since 0.1.0
	 *
	 * @param string $body Every digit but the last.
	 * @return string The number.
	 */
	public static function withCheckDigit( string $body ): string {
		$sum     = 0;
		$reverse = array_reverse( str_split( $body ) );

		foreach ( $reverse as $index => $digit ) {
			$value = (int) $digit * ( 0 === $index % 2 ? 2 : 1 );
			$sum  += intdiv( $value, 10 ) + $value % 10;
		}

		return $body . (string) ( ( 10 - $sum % 10 ) % 10 );
	}

	/**
	 * Writes ASCII digits in another script's decimal digits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text with ASCII digits.
	 * @param int    $zero The code point of the script's zero: 0xFF10 for full-width digits, 0x0660 for Arabic-Indic.
	 * @return string The text with every ASCII digit replaced.
	 */
	public static function inScript( string $text, int $zero ): string {
		return (string) preg_replace_callback( '/[0-9]/', static fn( array $digit ): string => (string) mb_chr( $zero + (int) $digit[0], 'UTF-8' ), $text );
	}

	/**
	 * Returns every digit of a text as ASCII, whatever its script, and nothing else.
	 *
	 * An oracle independent of the detector: it knows the two scripts the tests write in.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string The digits, 0 to 9.
	 */
	public static function digitsOf( string $text ): string {
		$ascii = (string) preg_replace_callback(
			'/[\x{FF10}-\x{FF19}\x{0660}-\x{0669}]/u',
			static function ( array $digit ): string {
				$code = (int) mb_ord( $digit[0], 'UTF-8' );

				return (string) ( $code - ( $code >= 0xFF10 ? 0xFF10 : 0x0660 ) );
			},
			$text
		);

		return (string) preg_replace( '/[^0-9]/', '', $ascii );
	}

	/**
	 * Changes the check digit, so the number no longer passes the checksum.
	 *
	 * @since 0.1.0
	 *
	 * @param string $card A number that passes it.
	 * @return string The same number with its last digit increased by one, modulo ten.
	 */
	public static function breakCheckDigit( string $card ): string {
		return substr( $card, 0, -1 ) . (string) ( ( (int) substr( $card, -1 ) + 1 ) % 10 );
	}
}
