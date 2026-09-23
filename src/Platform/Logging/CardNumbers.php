<?php
/**
 * CardNumbers: finds and removes card-shaped numbers in text
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * The Luhn detector: what counts as a card number in text, and what replaces it.
 *
 * Owns one fact: which runs of digits look like a payment card number. A run is card-shaped
 * when it holds 13 to 19 digits that pass the Luhn checksum.
 *
 * Text is read the way a person would read a card number out of it. A digit is any Unicode
 * decimal digit, read by its value, so full-width or Arabic-Indic digits count. Between two
 * groups of digits, a run of up to MAX_SEPARATOR separator characters joins them: any Unicode
 * space (the non-breaking one included), a tab or line break, any dash, and the invisible
 * format characters such as a zero-width space. The detector reads each chain of groups joined
 * that way and checks every sequence of whole groups holding 13 to 19 digits, so a card number
 * next to another group of digits, as in "qty 2 4111 1111 1111 1111", is still found. Groups
 * further apart than the bound are separate numbers.
 *
 * A dot never joins groups. Dots are how ordinary numbers are written, in IP addresses,
 * dates, versions and decimals, and two of those side by side can hold a run of digits that
 * passes the checksum; a card number written with dots is rare. So a card number written with
 * dots is kept, which is accepted.
 *
 * A single run of 13 to 19 digits that fails the checksum is kept, so an order or reference
 * number survives. A run of more than 19 digits without a separator is not a card number and
 * is kept too, by design: that includes a card number fused to more digits, such as one
 * followed directly by its expiry date. Card data never reaches PHP, so this detector is
 * defence in depth, not the boundary. Nothing else is exempt: an identifier whose digit groups happen to pass the
 * checksum is replaced as well, which is rare for a UUID and accepted, and so is a date range
 * written with only dashes and spaces between two dates.
 *
 * The plugin never accepts, stores or logs a card number on purpose; this is the net under
 * that rule for text that arrives by accident, for example in an exception message. It makes
 * the careless path visible, it cannot make it impossible.
 *
 * Pure: no I/O and no WordPress function, so it runs anywhere text leaves the plugin.
 *
 * @since 0.1.0
 */
final class CardNumbers {

	/**
	 * What a card-shaped run is replaced with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const MARKER = '[card number removed]';

	/**
	 * The fewest digits a card number has.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MIN_DIGITS = 13;

	/**
	 * The most digits a card number has.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_DIGITS = 19;

	/**
	 * The most separator characters that still join two groups of digits.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_SEPARATOR = 8;

	/**
	 * One separator character: Unicode white space and line breaks, any dash, the minus sign,
	 * and invisible format characters such as the zero-width space. Never a dot.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SEPARATOR = '[\s\p{Z}\p{Pd}\p{Cf}\x{2212}]';

	/**
	 * A chain: groups of decimal digits of any script, joined by short runs of separators.
	 *
	 * The quantifiers are possessive: nothing in a chain is ever given back, so the regular
	 * expression engine needs no memory per group, and a chain of thousands of groups is read
	 * instead of exhausting its stack.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CHAIN = '/\p{Nd}++(?:' . self::SEPARATOR . '{1,' . self::MAX_SEPARATOR . '}+\p{Nd}++)*+/u';

	/**
	 * What replaces text the regular expression engine could not scan.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNSCANNABLE = '[text removed: it could not be scanned for card numbers]';

	/**
	 * Replaces every card-shaped run in a text with the marker.
	 *
	 * Text that is not valid UTF-8 has every byte outside ASCII replaced by a question mark
	 * first, so it can be read at all.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string The text, with each card-shaped run, and the separators inside it, replaced by MARKER.
	 */
	public static function scrub( string $text ): string {
		$text = self::readable( $text );

		if ( preg_match_all( '/\p{Nd}/u', $text ) < self::MIN_DIGITS ) {
			return $text;
		}

		$scrubbed = preg_replace_callback(
			self::CHAIN,
			static fn( array $chain ): string => self::scrubChain( $chain[0] ),
			$text
		);

		return null === $scrubbed ? self::UNSCANNABLE : $scrubbed;
	}

	/**
	 * Tells whether a text holds a card-shaped run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return bool True when scrub() would replace something.
	 */
	public static function contains( string $text ): bool {
		$readable = self::readable( $text );

		return self::scrub( $readable ) !== $readable;
	}

	/**
	 * Removes the groups of digits, and the separators among and after them, that a text ends in.
	 *
	 * A text cut short can end in the first groups of a card number whose last ones were cut
	 * off: too few digits to be found, but still part of a card. Whatever follows the last
	 * character that is neither a digit nor a separator goes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Valid UTF-8.
	 * @return string The text up to and including its last character outside a chain; empty when there is none.
	 */
	public static function withoutTrailingChain( string $text ): string {
		// A greedy prefix backs off from the end one character at a time, so this is linear.
		$outside = '[^\p{Nd}' . substr( self::SEPARATOR, 1 );

		return 1 === preg_match( '/^.*' . $outside . '/su', $text, $prefix ) ? $prefix[0] : '';
	}

	/**
	 * Tells whether a string of ASCII digits passes the Luhn checksum.
	 *
	 * @since 0.1.0
	 *
	 * @param string $digits ASCII digits only.
	 * @return bool True when the checksum holds.
	 */
	public static function passesLuhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		for ( $position = strlen( $digits ) - 1; $position >= 0; $position-- ) {
			$digit = ord( $digits[ $position ] ) - 48;

			if ( $double ) {
				$digit *= 2;

				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Replaces the card-shaped sequences of groups in one chain.
	 *
	 * Every sequence of whole groups holding 13 to 19 digits is checked; the spans of those that
	 * pass the checksum are merged where they overlap, and each merged span becomes one marker.
	 *
	 * @since 0.1.0
	 *
	 * @param string $chain Groups of digits joined by separators, valid UTF-8.
	 * @return string The chain with each card-shaped span replaced.
	 */
	private static function scrubChain( string $chain ): string {
		preg_match_all( '/\p{Nd}+/u', $chain, $found, PREG_OFFSET_CAPTURE );

		$groups = array();

		foreach ( $found[0] as $group ) {
			$groups[] = array( self::asciiDigits( $group[0] ), $group[1], strlen( $group[0] ) );
		}

		$count = count( $groups );
		$spans = array();

		for ( $first = 0; $first < $count; $first++ ) {
			$digits = '';

			for ( $last = $first; $last < $count; $last++ ) {
				$digits .= $groups[ $last ][0];
				$length  = strlen( $digits );

				if ( $length > self::MAX_DIGITS ) {
					break;
				}

				if ( $length >= self::MIN_DIGITS && self::passesLuhn( $digits ) ) {
					$spans[] = array( $groups[ $first ][1], $groups[ $last ][1] + $groups[ $last ][2] );
				}
			}
		}

		if ( array() === $spans ) {
			return $chain;
		}

		// The spans are found in order of their start; merge the ones that overlap.
		$merged = array();

		foreach ( $spans as $span ) {
			$top = count( $merged ) - 1;

			if ( $top >= 0 && $span[0] <= $merged[ $top ][1] ) {
				$merged[ $top ][1] = max( $merged[ $top ][1], $span[1] );
			} else {
				$merged[] = $span;
			}
		}

		foreach ( array_reverse( $merged ) as $span ) {
			$chain = substr_replace( $chain, self::MARKER, $span[0], $span[1] - $span[0] );
		}

		return $chain;
	}

	/**
	 * Writes a group of decimal digits of any script as ASCII digits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $group Decimal digits, valid UTF-8.
	 * @return string The same digits, 0 to 9.
	 */
	private static function asciiDigits( string $group ): string {
		if ( 1 === preg_match( '/^[0-9]+$/D', $group ) ) {
			return $group;
		}

		$ascii = '';

		foreach ( mb_str_split( $group, 1, 'UTF-8' ) as $digit ) {
			$ascii .= 1 === preg_match( '/^[0-9]$/D', $digit ) ? $digit : (string) self::digitValue( (int) mb_ord( $digit, 'UTF-8' ) );
		}

		return $ascii;
	}

	/**
	 * Returns the value of a Unicode decimal digit.
	 *
	 * Unicode encodes every set of decimal digits as ten consecutive code points, zero first,
	 * and sets that follow one another stay aligned on ten. So a digit's value is its distance
	 * from the start of the run of decimal digits it belongs to, modulo ten.
	 *
	 * @since 0.1.0
	 *
	 * @param int $codePoint A code point of the Nd category.
	 * @return int 0 to 9.
	 */
	private static function digitValue( int $codePoint ): int {
		$start = $codePoint;

		while ( $start > 0 && $codePoint - $start < 100 && 1 === preg_match( '/^\p{Nd}$/u', (string) mb_chr( $start - 1, 'UTF-8' ) ) ) {
			--$start;
		}

		return ( $codePoint - $start ) % 10;
	}

	/**
	 * Makes a text valid UTF-8, so that it can be scanned.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return string The text; when it was not valid UTF-8, every byte outside ASCII became a question mark.
	 */
	private static function readable( string $text ): string {
		return 1 === preg_match( '//u', $text ) ? $text : (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
	}
}
