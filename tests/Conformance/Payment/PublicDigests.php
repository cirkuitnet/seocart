<?php
/**
 * PublicDigests: the digests a gateway's test declares public in a field, and the values each declaration admits
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Reads the fields whose digests a gateway's test declares public in publicDigestFields(), such as a delivery's signature header.
 *
 * Owns one fact: what a public digest is. A digest is a whole value of 64 or 128 hexadecimal
 * digits, in either case, bare or after a label that names its length: `sha256=` before 64 and
 * `sha512=` before 128, in lower case. Another label, or none of the right length, and 127 or 129
 * digits, are no digest.
 *
 * The tape scrubber reads a field against the declaration for the token rule (passes()) and for
 * the card rule (namesNoCard()); rule 13's logger reads it for the card rule, through the same
 * test as the scrubber (TapeScrubber::namesNoCardIn()).
 *
 * @since 0.2.0
 */
final class PublicDigests {

	/**
	 * A digest: 64 or 128 hexadecimal digits, after `sha256=` or `sha512=` as its length is, or bare; the digits captured.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGEST = '/^(?:sha256=(?=[0-9A-Fa-f]{64}$)|sha512=(?=[0-9A-Fa-f]{128}$))?([0-9A-Fa-f]{64}|[0-9A-Fa-f]{128})$/D';

	/**
	 * A run of 64 hexadecimal digits, which passes the token rule in a declared field wherever it stands in the value, as a signature header such as `t=…,v1=<digest>` holds one.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SHORT_DIGEST = '/^[0-9a-f]{64}$/iD';

	/**
	 * Cannot be called: the class is used through its static functions only.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {
	}

	/**
	 * Returns the digits of a whole value that is a digest.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string|null The 64 or 128 digits; null when the value, as a whole, is no digest.
	 */
	public static function digitsOf( string $value ): ?string {
		return 1 === preg_match( self::DIGEST, $value, $digest ) ? $digest[1] : null;
	}

	/**
	 * Tells whether an opaque token in a field's value passes the token rule as a public digest: in a declared field, a run of 64 hexadecimal digits, or the 128 digits, with a letter among them, of a value that is a digest as a whole.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $fields The fields the test declares.
	 * @param string   $token  The token, as the token rule found it.
	 * @param string   $value  The whole value the token is in.
	 * @param string   $field  The field's name; empty for none.
	 * @return bool True when it passes.
	 *
	 * @phpstan-param list<string> $fields
	 */
	public static function passes( array $fields, string $token, string $value, string $field ): bool {
		if ( ! TapeScrubber::declares( $fields, $field ) ) {
			return false;
		}

		return 1 === preg_match( self::SHORT_DIGEST, $token ) || ( 128 === strlen( $token ) && self::digitsOf( $value ) === $token && self::hasALetter( $token ) );
	}

	/**
	 * Tells whether a whole value in a field names no card as a public digest: a digest in a declared field with a letter among its digits, where no public shape is refused.
	 *
	 * The letter is looked for among the digits, never in the label, which always has one. A value
	 * of digits alone is read as a card number, and so is any value in a field named like a
	 * credential (TapeScrubber::CREDENTIAL_FIELD) or with a secret's prefix
	 * (TapeScrubber::SECRET_PREFIX).
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $fields The fields the test declares.
	 * @param string   $value  The value.
	 * @param string   $field The field's name; empty for none.
	 * @return bool True when the card rule does not read it.
	 *
	 * @phpstan-param list<string> $fields
	 */
	public static function namesNoCard( array $fields, string $value, string $field ): bool {
		$digits = self::digitsOf( $value );

		return null !== $digits
			&& self::hasALetter( $digits )
			&& 1 !== preg_match( TapeScrubber::CREDENTIAL_FIELD, $field )
			&& 1 !== preg_match( TapeScrubber::SECRET_PREFIX, $value )
			&& TapeScrubber::declares( $fields, $field );
	}

	/**
	 * Tells whether hexadecimal digits hold a letter.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when one of them is a to f, in either case.
	 */
	private static function hasALetter( string $digits ): bool {
		return 1 === preg_match( '/[a-f]/i', $digits );
	}
}
