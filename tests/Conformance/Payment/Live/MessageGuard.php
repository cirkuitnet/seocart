<?php
/**
 * MessageGuard: keeps credentials and card numbers out of what a live workflow prints
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment\Live;

/**
 * Builds every message the live tests fail with, refusing an argument shaped like a credential or a card number, and blanks such text out of a log.
 *
 * A live workflow runs in a public repository, so its log is public. A failure message is built
 * from ids, counts and the provider's one-word codes; an argument that looks like a credential is
 * refused instead of printed, and the message says which kind it refused, never the value.
 * redact() applies the same shapes to text the workflow did not build, such as PHPUnit's output.
 *
 * The shapes that are no provider's apply to every extension: an address with a query, an
 * authorization header, and a card number, which is a run of 13 to 19 digits that passes the Luhn
 * check, as SEOCart's own log reads it. A provider's ids may hold a long run of digits; most do not
 * pass the check, and the rare one that does is withheld (shown()). The extension's declaration
 * adds the shapes of its provider's credentials. A guard is built from a declaration, so there is
 * no guard that lacks a provider's shapes.
 *
 * @since 0.2.0
 */
final class MessageGuard {

	/**
	 * The shapes refused for every extension, each a regular expression, by what it is; a card number is found apart (holdsCard()).
	 *
	 * An address with a query comes first: redact() blanks it whole before it blanks a secret its
	 * query may hold. Each slash after the scheme may carry the backslash JSON puts before it.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	public const SHAPES = array(
		'an address with a query' => '#[a-z][a-z0-9+.\-]*:(?:\\\\?/){2}[^\s?\#"\'<>]*\?[^\s"\'<>]*#i',
		'an authorization header' => '/\b(?:Bearer|Basic)\s+[A-Za-z0-9+\/=._\-]{8,}/',
	);

	/**
	 * What a card number is called in a refusal and a redaction.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CARD = 'a card number';

	/**
	 * A run of digits long enough to hold a card number, spaces allowed between them.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DIGIT_RUN = '/(?<![0-9])[0-9](?: ?[0-9]){12,}(?![0-9])/';

	/**
	 * The shapes this guard refuses: SHAPES, then the declaration's.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private array $shapes;

	/**
	 * Builds the guard of an extension's live workflow.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When a shape the declaration states is no regular expression: a guard that cannot apply it would print what it should refuse.
	 *
	 * @param LiveDeclaration $declaration The extension's declaration, whose shapes are added.
	 */
	public function __construct( LiveDeclaration $declaration ) {
		$this->shapes = self::SHAPES + $declaration->shapes();

		foreach ( $this->shapes as $shape => $pattern ) {
			if ( ! self::compiles( $pattern ) ) {
				throw new \LogicException( sprintf( 'The shape of %s is no regular expression, so it would be printed.', $shape ) );
			}
		}
	}

	/**
	 * Formats a message, refusing any argument shaped like a credential or a card number.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When an argument matches a shape: the message names the shape, never the value.
	 *
	 * @param string           $format    The format, as sprintf() takes it; it is the test's own text.
	 * @param string|int|float ...$values The values.
	 * @return string The message.
	 */
	public function say( string $format, string|int|float ...$values ): string {
		foreach ( $values as $position => $value ) {
			$shape = $this->shapeOf( (string) $value );

			if ( null !== $shape ) {
				throw new \LogicException( sprintf( 'A message of the live tests was refused: its argument %1$d is shaped like %2$s, which is never printed.', $position + 1, $shape ) );
			}
		}

		return vsprintf( $format, $values );
	}

	/**
	 * Returns a value as a message may show it: the value, or a note that it was withheld and why.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value, such as a provider's id.
	 * @return string The value, or `(withheld: shaped like …)`.
	 */
	public function shown( string $value ): string {
		$shape = $this->shapeOf( $value );

		return null === $shape ? $value : '(withheld: shaped like ' . $shape . ')';
	}

	/**
	 * Returns the shape a value matches, if any.
	 *
	 * @since 0.2.0
	 *
	 * @param string $value The value.
	 * @return string|null What it is shaped like; null for none.
	 */
	public function shapeOf( string $value ): ?string {
		foreach ( $this->shapes as $shape => $pattern ) {
			if ( 1 === preg_match( $pattern, $value ) ) {
				return $shape;
			}
		}

		preg_match_all( self::DIGIT_RUN, $value, $runs );

		foreach ( $runs[0] as $run ) {
			if ( self::holdsCard( str_replace( ' ', '', $run ) ) ) {
				return self::CARD;
			}
		}

		return null;
	}

	/**
	 * Blanks out every value shaped like a credential or a card number in a text, leaving the rest as it was.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text, such as a line of PHPUnit's output.
	 * @return string The text, each such value replaced by `[redacted: <what it is>]`.
	 */
	public function redact( string $text ): string {
		foreach ( $this->shapes as $shape => $pattern ) {
			$text = (string) preg_replace( $pattern, '[redacted: ' . $shape . ']', $text );
		}

		return (string) preg_replace_callback(
			self::DIGIT_RUN,
			static fn( array $run ): string => self::holdsCard( str_replace( ' ', '', $run[0] ) ) ? '[redacted: ' . self::CARD . ']' : $run[0],
			$text
		);
	}

	/**
	 * Tells whether a run of digits holds 13 to 19 consecutive digits that pass the Luhn check.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when it does.
	 */
	private static function holdsCard( string $digits ): bool {
		$total = strlen( $digits );

		for ( $start = 0; $start + 13 <= $total; $start++ ) {
			for ( $length = 13; $length <= 19 && $start + $length <= $total; $length++ ) {
				if ( self::passesLuhn( substr( $digits, $start, $length ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Tells whether digits pass the Luhn check.
	 *
	 * @since 0.2.0
	 *
	 * @param string $digits The digits.
	 * @return bool True when they do.
	 */
	private static function passesLuhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];

			if ( $double ) {
				$digit = $digit * 2 > 9 ? $digit * 2 - 9 : $digit * 2;
			}

			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Tells whether a pattern is a regular expression PCRE compiles, without the warning PHP gives for one it cannot.
	 *
	 * @since 0.2.0
	 *
	 * @param string $pattern The pattern.
	 * @return bool True when it compiles.
	 */
	private static function compiles( string $pattern ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the warning of a pattern that does not compile is the answer, not an error.
		set_error_handler( static fn(): bool => true );

		try {
			return false !== preg_match( $pattern, '' );
		} finally {
			restore_error_handler();
		}
	}
}
