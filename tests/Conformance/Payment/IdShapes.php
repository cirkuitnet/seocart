<?php
/**
 * IdShapes: the shapes of a provider's public ids that a gateway's test declares, and the values each one admits
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Reads the shapes of a provider's public ids that a gateway's test declares in publicIdShapes().
 *
 * Owns one fact: what a declared id shape is, and which values are ids of it. A shape is a prefix
 * with the number of letters and digits after it, such as `'pi_' => 24`, or the least and the
 * most, such as `'cus_' => array( 14, 24 )`. A shape may have no prefix, such as `'' => 17`, only
 * while it is shorter than TapeScrubber::TOKEN_LENGTH: such an id is too short to be read as an
 * opaque token, so the shape lets no token pass and serves the card rule alone. A shape of 20
 * characters or more with no prefix would let any opaque token of its length pass, and is refused.
 *
 * The tape scrubber reads a value against the shapes for the token rule (matches()) and for the
 * card rule (namesNoCard()); rule 13's logger reads them for the card rule.
 *
 * @since 0.2.0
 */
final class IdShapes {

	/**
	 * Returns why a declaration of shapes is refused, in one sentence; null when it is not.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, int|array<int>> $shapes The shapes, by prefix.
	 * @return string|null The sentence; null for shapes that each bound an id.
	 *
	 * @phpstan-param array<int|string, int|array{int, int}> $shapes
	 */
	public static function refusal( array $shapes ): ?string {
		foreach ( $shapes as $prefix => $length ) {
			list( $least, $most ) = self::bounds( $length );

			if ( $least < 1 || $most < $least ) {
				return sprintf( 'A public id\'s shape is a prefix with the number of letters and digits after it, or the least and the most, each 1 or more; the shape of %s is not.', $prefix );
			}

			if ( '' === (string) $prefix && $most >= TapeScrubber::TOKEN_LENGTH ) {
				return sprintf( 'A public id\'s shape with no prefix holds fewer than %1$d letters and digits, as one of %1$d or more would let any opaque token of its length pass, and the shape of %2$d to %3$d without a prefix does not.', TapeScrubber::TOKEN_LENGTH, $least, $most );
			}
		}

		return null;
	}

	/**
	 * Tells whether a whole value is an id of one of the shapes: its prefix, then exactly a declared number of letters and digits.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, int|array<int>> $shapes The shapes, by prefix.
	 * @param string                            $value  The value.
	 * @return bool True when the value, as a whole, is of one of them.
	 *
	 * @phpstan-param array<int|string, int|array{int, int}> $shapes
	 */
	public static function matches( array $shapes, string $value ): bool {
		foreach ( $shapes as $prefix => $length ) {
			list( $least, $most ) = self::bounds( $length );

			if ( 1 === preg_match( '/^' . preg_quote( (string) $prefix, '/' ) . '[A-Za-z0-9]{' . $least . ',' . $most . '}$/D', $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tells whether a whole value is an id of one of the shapes with a letter in it, which the card rule does not read as a card number.
	 *
	 * A provider's id of letters and digits often holds a run of 13 to 16 digits, and one such run
	 * in ten passes the card number's checksum. An id of a declared shape with a letter in it is
	 * the provider's, not a card number; a value of digits alone is read as one, whatever shape it
	 * is of, and so is a value that holds an id among other text.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int|string, int|array<int>> $shapes The shapes, by prefix.
	 * @param string                            $value  The value.
	 * @return bool True for a whole id of a declared shape that holds a letter.
	 *
	 * @phpstan-param array<int|string, int|array{int, int}> $shapes
	 */
	public static function namesNoCard( array $shapes, string $value ): bool {
		return 1 === preg_match( '/[A-Za-z]/', $value ) && self::matches( $shapes, $value );
	}

	/**
	 * Returns the least and the most letters and digits of a shape.
	 *
	 * @since 0.2.0
	 *
	 * @param int|array<int> $length The number, or the least and the most.
	 * @return array{int, int} The least and the most.
	 */
	private static function bounds( int|array $length ): array {
		return is_int( $length ) ? array( $length, $length ) : array( (int) ( $length[0] ?? 0 ), (int) ( $length[1] ?? 0 ) );
	}
}
