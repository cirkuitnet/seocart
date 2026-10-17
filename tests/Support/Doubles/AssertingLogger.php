<?php
/**
 * AssertingLogger: a gateway's logger that keeps every line and notes each one that holds what no log may
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Contracts\Logger;
use SEOCart\Platform\Logging\CardNumbers;

/**
 * The logger a gateway is given under test: it keeps every line and notes why any of them offends.
 *
 * Owns one fact: whether a gateway logged a provider's answer or card data. A line offends when its
 * message or context holds a response body the gateway was served (as the text it arrived as, or
 * decoded, as any part of the context: an answer decoded and logged is no longer the same bytes),
 * the provider's words, a card field's name (`exp_month`, `exp_year`, `last4`, `accountNumber`,
 * `cvc`), or a card number.
 *
 * The provider's words are each string value of a JSON answer that is text: one with whitespace,
 * or one word of letters, such as an error's message or a one-word status, of SHORTEST_TEXT
 * characters or more; an identifier, a code with `_` or a digit, or a URL is not. A word the test
 * declares as one of the provider's enumerated codes (GatewayConformanceCase::publicCodes()) passes.
 * Each is looked for as whole words in the line read as one text: the message, then each value of
 * the context in order, URL-decoded, HTML-decoded, case-folded and with its whitespace collapsed,
 * so a message logged encoded, in another case, or split across values is found.
 *
 * It never throws: a gateway could catch the exception and carry on, so a test asks violations().
 *
 * @since 0.2.0
 */
final class AssertingLogger implements Logger {

	/**
	 * The names of card fields no log line may carry, as a key or in its text.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	public const CARD_FIELDS = array( 'exp_month', 'exp_year', 'last4', 'accountNumber', 'cvc' );

	/**
	 * What rule 13's reading of a log line cannot see, as the reference page states it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LIMIT = 'Rule 13 takes for the provider\'s words a value of its answers that has whitespace in it or is one word of letters: a one-word value with `_` or a digit in it is taken for a code or an id, which a log line may carry, and two spellings that differ only by Unicode normalisation are not taken for the same words.';

	/**
	 * Response bodies shorter than this are not looked for: `{}` or `[]` would be found everywhere.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SHORTEST_BODY = 16;

	/**
	 * The provider's words shorter than this, in characters, are not looked for: a word of one or two letters may be found in any line.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const SHORTEST_TEXT = 3;

	/**
	 * Returns the response bodies the gateway was served so far.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): list<string>
	 */
	private \Closure $answers;

	/**
	 * Every line logged, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<array{level: string, code: string, message: string, context: array<mixed>}>
	 */
	private array $lines = array();

	/**
	 * Why each offending line offends.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $violations = array();

	/**
	 * The provider's enumerated codes a line may carry, case-folded.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private array $codes;

	/**
	 * Keeps the source of the answers to look for, and the codes a line may carry.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $answers Returns the response bodies served so far.
	 * @param string[] $codes   Optional. The provider's enumerated codes a line may carry, such as a status. Default none.
	 *
	 * @phpstan-param \Closure(): list<string> $answers
	 * @phpstan-param list<string> $codes
	 */
	public function __construct( \Closure $answers, array $codes = array() ) {
		$this->answers = $answers;
		$this->codes   = array_map( static fn( string $code ): string => self::normalized( $code ), $codes );
	}

	/**
	 * Logs a line at the debug level.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function debug( string $code, string $message, array $context = array() ): void {
		$this->keep( 'debug', $code, $message, $context );
	}

	/**
	 * Logs a line at the info level.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function info( string $code, string $message, array $context = array() ): void {
		$this->keep( 'info', $code, $message, $context );
	}

	/**
	 * Logs a line at the warning level.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function warning( string $code, string $message, array $context = array() ): void {
		$this->keep( 'warning', $code, $message, $context );
	}

	/**
	 * Logs a line at the error level.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $code    The machine code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	public function error( string $code, string $message, array $context = array() ): void {
		$this->keep( 'error', $code, $message, $context );
	}

	/**
	 * Returns every line logged.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{level: string, code: string, message: string, context: array<mixed>}> The lines, in order.
	 */
	public function lines(): array {
		return $this->lines;
	}

	/**
	 * Returns why each offending line offends.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> One sentence per finding; none when no line offends.
	 */
	public function violations(): array {
		return $this->violations;
	}

	/**
	 * Keeps a line and notes what in it offends.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $level   The level.
	 * @param string       $code    The machine code.
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 */
	private function keep( string $level, string $code, string $message, array $context ): void {
		$this->lines[] = array(
			'level'   => $level,
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);

		foreach ( $this->findings( $message, $context ) as $finding ) {
			$this->violations[] = sprintf( 'the line %1$s holds %2$s', $code, $finding );
		}
	}

	/**
	 * Lists what a line holds that no log may.
	 *
	 * @since 0.2.0
	 *
	 * @param string       $message The message.
	 * @param array<mixed> $context The context.
	 * @return list<string> What was found, each as a short phrase.
	 */
	private function findings( string $message, array $context ): array {
		$text     = $message . ' ' . (string) wp_json_encode( $context );
		$raw      = implode( ' ', array_merge( array( $message ), self::valuesOf( $context ) ) );
		$findings = array();

		foreach ( ( $this->answers )() as $body ) {
			if ( strlen( $body ) >= self::SHORTEST_BODY && ( str_contains( $raw, $body ) || str_contains( $text, $body ) || self::holdsDecoded( $context, $body ) ) ) {
				$findings[] = 'a response body the gateway was served';
				break;
			}
		}

		$read = self::normalized( $raw );

		foreach ( ( $this->answers )() as $body ) {
			foreach ( self::wordsOf( json_decode( $body, true ), '' ) as $path => $words ) {
				if ( ! in_array( $words, $this->codes, true ) && 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $words, '/' ) . '(?![\p{L}\p{N}])/u', $read ) ) {
					$findings[] = sprintf( 'the provider\'s words at %s of a response the gateway was served, which are no code the test declares (publicCodes())', $path );
					break 2;
				}
			}
		}

		foreach ( self::CARD_FIELDS as $field ) {
			if ( false !== stripos( $text, $field ) ) {
				$findings[] = 'the card field ' . $field;
			}
		}

		if ( CardNumbers::contains( $text ) ) {
			$findings[] = 'a card number';
		}

		return $findings;
	}

	/**
	 * Returns the provider's words in a decoded response, normalised, by the dotted path of their field: each string value that is text.
	 *
	 * Text has whitespace in it, or is one word of letters, such as `Denied` or `succeeded`: an id,
	 * a code with `_` or a digit, or a URL is not, and a log line may carry those.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $value A decoded response, or a part of one.
	 * @param string $path  The dotted path of the part; empty for the response.
	 * @return array<string, string> The words, by path.
	 */
	private static function wordsOf( mixed $value, string $path ): array {
		if ( is_string( $value ) ) {
			$words = self::normalized( $value );
			$text  = 1 === preg_match( '/\s/u', $words ) || 1 === preg_match( '/^[\p{L}\p{M}\'’]+[.!?]*$/u', $words );

			return strlen( $words ) >= self::SHORTEST_TEXT && 1 === preg_match( '/\p{L}/u', $words ) && $text ? array( $path => $words ) : array();
		}

		$found = array();

		foreach ( is_array( $value ) ? $value : array() as $key => $part ) {
			$found += self::wordsOf( $part, '' === $path ? (string) $key : $path . '.' . $key );
		}

		return $found;
	}

	/**
	 * Returns the scalar values of a context, in order, its nested values included.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $context The context.
	 * @return list<string> The values, as text.
	 */
	private static function valuesOf( array $context ): array {
		$values = array();

		array_walk_recursive(
			$context,
			static function ( mixed $value ) use ( &$values ): void {
				if ( is_scalar( $value ) || $value instanceof \Stringable ) {
					$values[] = (string) $value;
				}
			}
		);

		return $values;
	}

	/**
	 * Normalises a text for comparison: URL-decoded, HTML-decoded, case-folded, its whitespace collapsed.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return string The text, normalised.
	 */
	private static function normalized( string $text ): string {
		$decoded = mb_strtolower( html_entity_decode( urldecode( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'UTF-8' );

		return trim( (string) ( preg_replace( '/[\s\x{00A0}]+/u', ' ', $decoded ) ?? $decoded ) );
	}

	/**
	 * Tells whether a log line's context holds a response body, decoded.
	 *
	 * @since 0.2.0
	 *
	 * @param array<mixed> $context The context.
	 * @param string       $body    The body.
	 * @return bool True when it does.
	 */
	private static function holdsDecoded( array $context, string $body ): bool {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || array() === $decoded ) {
			return false;
		}

		$stack = array( $context );

		while ( array() !== $stack ) {
			$value = array_pop( $stack );

			if ( $value === $decoded ) {
				return true;
			}

			foreach ( $value as $part ) {
				if ( is_array( $part ) ) {
					$stack[] = $part;
				}
			}
		}

		return false;
	}
}
