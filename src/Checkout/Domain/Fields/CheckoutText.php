<?php
/**
 * CheckoutText: the characters no value a shopper types into the checkout may hold
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain\Fields;

use SEOCart\Contracts\Payment\BillingContact;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether a value is plain text the checkout accepts: valid UTF-8 without a control character or an invisible format character.
 *
 * Owns one fact: which characters the checkout refuses. They are the billing contact's control
 * characters (BillingContact::CONTROL_CHARACTER: every Unicode control character, and the line and
 * paragraph separators), and the format characters (Unicode category Cf), which show nothing and
 * can reorder or hide what a person reads, such as the right-to-left override. Two format
 * characters are accepted: the zero width non-joiner and joiner (U+200C, U+200D), which Persian
 * and Indic writing need and which reorder nothing. A multi-line value, a textarea's, may also hold
 * a line feed (U+000A); a carriage return is refused everywhere.
 *
 * The contact's own folding of a control character into a space stays, as the gateway's backstop
 * for an order placed before this rule.
 *
 * @since 0.2.0
 */
final class CheckoutText {

	/**
	 * The characters a value may hold although their class is refused: the zero width non-joiner and joiner.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const JOINERS = '\x{200C}\x{200D}';

	/**
	 * Cannot be called: the class is a static function.
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	/**
	 * Tells whether a value is plain text the checkout accepts.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value     The value.
	 * @param bool   $multiline Whether the value may run over several lines: true for a textarea.
	 * @return bool True for valid UTF-8 holding no refused character (refused()).
	 */
	public static function accepts( string $value, bool $multiline ): bool {
		// preg_match() gives 0 for plain text, 1 for a refused character, and false for text that is not valid UTF-8.
		return 0 === preg_match( self::refused( $multiline ), $value );
	}

	/**
	 * Returns the regular expression that finds a refused character.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $multiline Whether a line feed is accepted.
	 * @return string The expression, read as UTF-8: a control or format character, but the joiners
	 *                and, for a multi-line value, the line feed.
	 */
	public static function refused( bool $multiline ): string {
		$accepted = self::JOINERS . ( $multiline ? '\x{000A}' : '' );

		return '/(?![' . $accepted . '])(?:' . BillingContact::CONTROL_CHARACTER . '|\p{Cf})/u';
	}
}
