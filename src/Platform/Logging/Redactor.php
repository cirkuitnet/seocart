<?php
/**
 * Redactor: makes a log line's text and context safe to store, from the plugin's privacy declarations
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\StatementDiagnostic;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Redacts what a log line carries, before it is written anywhere.
 *
 * Owns one fact: how a value becomes safe to log. The sensitive names are not listed here:
 * they are read from the declarations, once, when the redactor is built. A context key that
 * names a `pii` column, option or field anywhere in the plugin has its value replaced by
 * REDACTED; one that names a `secret` is dropped with its value. A name that is both is
 * dropped. Keys are compared without regard to case, at every depth of the context.
 *
 * Every string that passes through, the message included, has its card-shaped numbers
 * removed by CardNumbers, and so has every integer and float that would print as one. A
 * Throwable is written as its class, its code or message, its file and line and its previous
 * exceptions; a coded exception's context is redacted by the same rules. The statement and
 * the server's text a database failure carries are written with every literal replaced, since
 * both can quote a customer's data. Objects are written as their class name only. Nothing a
 * caller passes can opt out of any of this.
 *
 * Free text is every string: a message, a context value, an exception's message and those of
 * its previous exceptions, a statement and the server's text. The policy for it is
 * conservative and driven by the same declarations. An email address becomes REDACTED. A pair
 * is a name, then `=`, `:` or `=>`, then a value. The name is a bare word, a word in double or
 * single quotes, or one in square brackets as print_r() writes it, and it is read after
 * percent-decoding, so `name=value`, `name%5Fpart=value`, `"name":"value"`, `'name': 'value'`,
 * `'name' => 'value'` and `[name] => value` are all pairs; so is `outer[name]=value`, by its
 * last bracketed part. A pair whose name is a declared personal-data name keeps its name and
 * has its value replaced by REDACTED; one whose name is a declared secret name is removed
 * whole. A value is a quoted string or runs to the next white space, `&`, `;`, `,` or closing
 * bracket; a quoted value that is never closed runs to the end of the text. Card numbers go,
 * and so do SQL literals in a statement or the server's text. Everything else in free text is
 * kept: a person's name, a postal address, a phone number or any other personal data written
 * in prose is not guessed at and stays.
 *
 * A line reads at most READ_LENGTH characters of the text it keeps, its message and every
 * string of its context together, so no line can cost more than that much scanning. What the
 * budget does not reach is removed unread, never kept: a string it reaches only in part is
 * kept to that part, less the word the cut ran into, and ends in CUT; one it does not reach at
 * all becomes UNREAD. A context key it does not reach becomes UNREAD followed by the entry's
 * position, `[left out: …] #3`, so the entries after it keep their values apart; so does a
 * key that redacts to one already kept.
 *
 * A value that is replaced or dropped because of its declared name is checked for a card
 * number first, so its removal is still reported, and nothing of it is kept. That check has a
 * budget of its own, also READ_LENGTH characters for a whole line, and follows arrays no
 * deeper than MAX_DEPTH; a value it cannot finish is reported as unchecked instead, without
 * reading on.
 *
 * Output is bounded: strings, arrays, nesting and the total number of values have limits, so
 * a runaway context cannot fill the log.
 *
 * Building one from the declarations does no I/O and calls no WordPress function.
 *
 * @since 0.1.0
 */
final class Redactor {

	/**
	 * What a personal-data value is replaced with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const REDACTED = '[redacted]';

	/**
	 * The most characters a string value keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STRING_LENGTH = 1000;

	/**
	 * The most characters a context key keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const KEY_LENGTH = 100;

	/**
	 * The most characters one line reads of the text it keeps, every string together; and, apart from that, of the values it replaces or drops.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const READ_LENGTH = 65536;

	/**
	 * What replaces a text of which nothing is kept, because the line had read all it may before it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const UNREAD = '[left out: the line was read to its limit]';

	/**
	 * How deep arrays and previous exceptions are followed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_DEPTH = 6;

	/**
	 * The most entries one array keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_ENTRIES = 100;

	/**
	 * The most values one context keeps, at every depth together.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_VALUES = 1000;

	/**
	 * The most stack frames written for an unexpected exception.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const MAX_FRAMES = 10;

	/**
	 * What ends a string that was cut.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CUT = ' [cut]';

	/**
	 * What replaces a value nested too deep.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const TOO_DEEP = '[nested too deep]';

	/**
	 * The key under which an array says how many entries it left out.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LEFT_OUT = '[left out]';

	/**
	 * An email address in free text.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const EMAIL = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)*\.\p{L}{2,}/u';

	/**
	 * The value of a pair in free text: a quoted string, or everything up to the next white
	 * space, `&`, `;`, `,` or closing bracket.
	 *
	 * The quantifiers are possessive, so a long quoted value is read instead of exhausting the
	 * regular expression engine's stack.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PAIR_VALUE = '(?:' . self::QUOTED . '|[^\s&;,})\]]++)';

	/**
	 * A string in double or single quotes, with backslash escapes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const QUOTED = '"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'';

	/**
	 * A quoted string, closed, where a pair's value starts.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const QUOTED_AT = '/\G(?:' . self::QUOTED . ')/u';

	/**
	 * The start of a pair in free text: its name and its separator.
	 *
	 * The name is a bare word, which may hold percent-escapes, or a word in double or single
	 * quotes, neither starting inside a word; or one in square brackets, anywhere, as the last
	 * part of `outer[name]`. The value is read only after the name is found to be declared, so
	 * a pair that is kept costs no more than its name, and a pair written inside its value is
	 * still found.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PAIR_START = '/(?<name>\[[^\[\]\n]{1,200}\]|(?<![\p{L}\p{N}_%])(?:"[^"\\\\\n]{1,200}"|\'[^\'\\\\\n]{1,200}\'|(?:[\p{L}\p{N}_]|%[0-9A-Fa-f]{2}){1,200}))\s*(?:=>|[=:])\s*/u';

	/**
	 * The value of a pair, where a pair's start ends.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PAIR_VALUE_AT = '/\G' . self::PAIR_VALUE . '/u';

	/**
	 * What replaces text a regular expression could not scan.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UNSCANNABLE = '[text removed: it could not be scanned]';

	/**
	 * SQL literals and what replaces them: quoted strings, including one cut off at the end,
	 * hexadecimal literals, and numbers that are not part of a name.
	 *
	 * The quantifiers of a quoted string are possessive, so a long literal is replaced instead
	 * of exhausting the regular expression engine's stack.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const SQL_LITERALS = array(
		'/\'(?:[^\'\\\\]++|\\\\.|\'\')*+(?:\'|$)/s' => '?',
		'/"(?:[^"\\\\]++|\\\\.|"")*+(?:"|$)/s'      => '?',
		'/\b0x[0-9a-f]+\b/i'                        => '?',
		'/(?<![\w$.`])\d+(?:\.\d+)?(?:e[+-]?\d+)?(?![\w$`])/i' => '?',
	);

	/**
	 * Lowercase names whose values are replaced: the `pii` columns, options and fields.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $personal;

	/**
	 * Lowercase names whose entries are dropped: the `secret` columns, options and fields.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private array $secret;

	/**
	 * How many more values the context being redacted may keep.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $budget = self::MAX_VALUES;

	/**
	 * Whether a card number was removed since line() began redacting the current line.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $cardNumberRemoved = false;

	/**
	 * Whether a value replaced or dropped since line() began could not be checked for a card number in full.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private bool $cardNumberUnchecked = false;

	/**
	 * How many more characters the checks of replaced and dropped values may read in the context being redacted.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $checkBudget = self::READ_LENGTH;

	/**
	 * How many more characters of the text it keeps the current line may read.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $readBudget = self::READ_LENGTH;

	/**
	 * Keeps the names. Use fromDeclarations(): names never come from anywhere else.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, true> $personal Lowercase personal-data names.
	 * @param array<string, true> $secret   Lowercase secret names.
	 */
	private function __construct( array $personal, array $secret ) {
		$this->personal = array_diff_key( $personal, $secret );
		$this->secret   = $secret;
	}

	/**
	 * Builds the redactor from every privacy declaration the plugin has.
	 *
	 * The data registry gives its `pii` and `secret` columns and options. The fields are every
	 * other declared field, as the kernel collects them: each operation's input and output fields
	 * and each setting's field. This module reads FieldSpecs only, never the registries that
	 * hold them.
	 *
	 * @since 0.1.0
	 *
	 * @param DataRegistry $data      The tables and options the plugin owns.
	 * @param FieldSpec    ...$fields Every other declared field.
	 * @return self The redactor.
	 */
	public static function fromDeclarations( DataRegistry $data, FieldSpec ...$fields ): self {
		$personal = array();
		$secret   = array();

		foreach ( $data->columnsClassified( Classification::Pii ) as $columns ) {
			foreach ( $columns as $column ) {
				$personal[ strtolower( $column->name() ) ] = true;
			}
		}

		foreach ( $data->columnsClassified( Classification::Secret ) as $columns ) {
			foreach ( $columns as $column ) {
				$secret[ strtolower( $column->name() ) ] = true;
			}
		}

		foreach ( $data->options() as $option ) {
			if ( Classification::Pii === $option->classification() ) {
				$personal[ strtolower( $option->name() ) ] = true;
			} elseif ( Classification::Secret === $option->classification() ) {
				$secret[ strtolower( $option->name() ) ] = true;
			}
		}

		foreach ( $fields as $field ) {
			if ( Privacy::Pii === $field->privacy() ) {
				$personal[ strtolower( $field->name() ) ] = true;
			} elseif ( Privacy::Secret === $field->privacy() ) {
				$secret[ strtolower( $field->name() ) ] = true;
			}
		}

		return new self( $personal, $secret );
	}

	/**
	 * Redacts one log line, and says whether a card number had to be removed from it.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $message       The line's message.
	 * @param int          $messageLength The most characters the message keeps.
	 * @param array<mixed> $context       The line's context.
	 * @return array{message: string, context: array<mixed>, card_number_removed: bool, card_number_unchecked: bool} The
	 *         redacted message and context; whether a card-shaped number was found and removed in
	 *         either; and whether a value replaced or dropped was too large or too deeply nested
	 *         to be checked for one in full.
	 */
	public function line( string $message, int $messageLength, array $context ): array {
		$this->cardNumberRemoved   = false;
		$this->cardNumberUnchecked = false;

		$this->startReading();

		$message = $this->kept( $message, $messageLength );
		$context = $this->entries( $context, 0 );

		return array(
			'message'               => $message,
			'context'               => $context,
			'card_number_removed'   => $this->cardNumberRemoved,
			'card_number_unchecked' => $this->cardNumberUnchecked,
		);
	}

	/**
	 * Redacts a log line's context.
	 *
	 * The context is read as one line would read it: its strings share one budget of
	 * READ_LENGTH characters.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $context The context the caller passed.
	 * @return array<mixed> What may be stored: personal data replaced, secrets dropped, card
	 *                      numbers removed, objects summarized, sizes bounded.
	 */
	public function context( array $context ): array {
		$this->startReading();

		return $this->entries( $context, 0 );
	}

	/**
	 * Starts the budgets of one line: the values it keeps, the characters it reads of them, and those it reads of the values it replaces or drops.
	 *
	 * @since 0.1.0
	 */
	private function startReading(): void {
		$this->budget      = self::MAX_VALUES;
		$this->readBudget  = self::READ_LENGTH;
		$this->checkBudget = self::READ_LENGTH;
	}

	/**
	 * Makes one string of the line safe to keep, reading no more of it than the line's budget has left.
	 *
	 * What the budget does not reach is removed unread, with the last word that was read, and
	 * the digits it ends in: they may be the start of an address or a card number that runs on
	 * into the part not read. The rest ends in CUT. Of a string the budget does not reach at
	 * all, UNREAD is kept.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text      The text.
	 * @param int    $maxLength The most characters the result has.
	 * @return string The text.
	 */
	private function kept( string $text, int $maxLength = self::STRING_LENGTH ): string {
		if ( 1 !== preg_match( '//u', $text ) ) {
			// Not valid UTF-8: every byte outside ASCII becomes a question mark.
			$text = (string) preg_replace( '/[\x80-\xFF]/', '?', $text );
		}

		$length = mb_strlen( $text );
		$read   = min( $length, max( 0, $this->readBudget ) );

		$this->readBudget -= $read;

		if ( $read === $length ) {
			return $this->safe( $text, $maxLength );
		}

		// The greedy prefix ends at the last white space, so the word the cut ran into is dropped.
		$part = 1 === preg_match( '/^.*\s/su', mb_substr( $text, 0, $read ), $prefix ) ? $prefix[0] : '';
		$part = CardNumbers::withoutTrailingChain( $part );

		if ( '' === trim( $part ) ) {
			return mb_substr( self::UNREAD, 0, $maxLength );
		}

		$safe = $this->safe( $part, $maxLength );

		if ( str_ends_with( $safe, self::CUT ) ) {
			return $safe;
		}

		return mb_substr( $safe, 0, max( 0, $maxLength - mb_strlen( self::CUT ) ) ) . self::CUT;
	}

	/**
	 * Applies the free-text policy to a text that was read whole, removes its card numbers, and cuts it to a limit.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text      Valid UTF-8.
	 * @param int    $maxLength The most characters the result has.
	 * @return string The text.
	 */
	private function safe( string $text, int $maxLength ): string {
		$text = $this->freeText( $text );

		// First every card number goes, so a cut can never leave the beginning of one behind.
		$scrubbed = CardNumbers::scrub( $text );

		if ( $scrubbed !== $text ) {
			$this->cardNumberRemoved = true;
			$text                    = $scrubbed;
		}

		if ( mb_strlen( $text ) <= $maxLength ) {
			return $text;
		}

		// Room for the ending, and for the one marker a cut run of digits can still need.
		$keep = max( 0, $maxLength - mb_strlen( self::CUT ) - mb_strlen( CardNumbers::MARKER ) );

		return mb_substr( CardNumbers::scrub( mb_substr( $text, 0, $keep ) . self::CUT ), 0, $maxLength );
	}

	/**
	 * Redacts the entries of one array.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $entries The entries.
	 * @param int          $depth   How deep the array is nested.
	 * @return array<mixed> The redacted entries.
	 */
	private function entries( array $entries, int $depth ): array {
		$redacted = array();
		$kept     = 0;

		foreach ( $entries as $key => $value ) {
			$name = strtolower( trim( (string) $key ) );

			if ( isset( $this->secret[ $name ] ) ) {
				$this->noteCardNumberIn( $value, $depth );

				continue;
			}

			if ( $kept >= self::MAX_ENTRIES || $this->budget <= 0 ) {
				$redacted[ self::LEFT_OUT ] = sprintf( '%d more entries were left out', count( $entries ) - $kept );

				break;
			}

			++$kept;

			$safeKey = is_int( $key ) && ! CardNumbers::contains( (string) $key ) ? $key : $this->kept( (string) $key, self::KEY_LENGTH );

			if ( is_string( $safeKey ) && ( self::UNREAD === $safeKey || array_key_exists( $safeKey, $redacted ) ) ) {
				// A key the budget did not reach, or one redacted to a key already kept: its position keeps the entry apart.
				$safeKey = sprintf( '%s #%d', $safeKey, $kept );
			}

			if ( isset( $this->personal[ $name ] ) ) {
				$this->noteCardNumberIn( $value, $depth );

				$redacted[ $safeKey ] = self::REDACTED;
			} else {
				$redacted[ $safeKey ] = $this->value( $value, $depth + 1 );
			}
		}

		return $redacted;
	}

	/**
	 * Notes whether a value about to be replaced or dropped holds a card number, and keeps nothing of it.
	 *
	 * The check stops at the first card number. It reads at most what is left of the line's
	 * check budget and enters arrays no deeper than MAX_DEPTH; a value that would take it past
	 * either is noted as unchecked, and nothing more is read.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @param int   $depth How deep it is nested.
	 */
	private function noteCardNumberIn( mixed $value, int $depth ): void {
		if ( $this->cardNumberRemoved || $this->cardNumberUnchecked ) {
			return;
		}

		if ( is_array( $value ) ) {
			if ( array() !== $value && $depth > self::MAX_DEPTH ) {
				$this->cardNumberUnchecked = true;

				return;
			}

			foreach ( $value as $key => $item ) {
				if ( ! $this->checks( (string) $key ) ) {
					return;
				}

				$this->noteCardNumberIn( $item, $depth + 1 );

				if ( $this->cardNumberRemoved || $this->cardNumberUnchecked ) {
					return;
				}
			}

			return;
		}

		if ( is_float( $value ) ) {
			if ( $this->checks( '' ) && CardNumbers::MARKER === self::number( $value ) ) {
				$this->cardNumberRemoved = true;
			}

			return;
		}

		if ( $value instanceof \Throwable ) {
			$value = $value->getMessage();
		}

		if ( is_string( $value ) || is_int( $value ) ) {
			$this->checks( (string) $value );
		}
	}

	/**
	 * Checks one text of a value about to be replaced or dropped, within the line's check budget.
	 *
	 * Each text costs its length, and at least one, so an array of many empty values runs the
	 * budget out as well. A text longer than what is left is not read at all: the value is
	 * noted as unchecked.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text The text.
	 * @return bool True when the check may go on: the text was read and held no card number.
	 */
	private function checks( string $text ): bool {
		$cost = max( 1, strlen( $text ) );

		if ( $cost > $this->checkBudget ) {
			$this->checkBudget         = 0;
			$this->cardNumberUnchecked = true;

			return false;
		}

		$this->checkBudget -= $cost;

		if ( '' !== $text && CardNumbers::contains( $text ) ) {
			$this->cardNumberRemoved = true;

			return false;
		}

		return true;
	}

	/**
	 * Redacts one value.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @param int   $depth How deep it is nested.
	 * @return mixed What may be stored in its place.
	 */
	private function value( mixed $value, int $depth ): mixed {
		--$this->budget;

		if ( null === $value || is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			$number = is_float( $value ) ? self::number( $value ) : $value;

			if ( is_int( $number ) && CardNumbers::contains( (string) $number ) ) {
				$number = CardNumbers::MARKER;
			}

			if ( CardNumbers::MARKER === $number ) {
				$this->cardNumberRemoved = true;
			}

			return $number;
		}

		if ( is_string( $value ) ) {
			return $this->kept( $value );
		}

		if ( $depth > self::MAX_DEPTH && ( is_array( $value ) || is_object( $value ) ) ) {
			return self::TOO_DEEP;
		}

		if ( is_array( $value ) ) {
			return $this->entries( $value, $depth );
		}

		return $this->objectValue( $value, $depth );
	}

	/**
	 * Redacts a value that is neither scalar nor array.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The object or resource.
	 * @param int   $depth How deep it is nested.
	 * @return mixed What may be stored in its place.
	 */
	private function objectValue( mixed $value, int $depth ): mixed {
		if ( $value instanceof \Throwable ) {
			return $this->throwable( $value, $depth, true );
		}

		if ( $value instanceof \BackedEnum ) {
			return $this->value( $value->value, $depth );
		}

		if ( $value instanceof \UnitEnum ) {
			return $value->name;
		}

		if ( $value instanceof \DateTimeInterface ) {
			return $value->format( 'Y-m-d\TH:i:s.uP' );
		}

		if ( $value instanceof \JsonSerializable ) {
			try {
				return $this->value( $value->jsonSerialize(), $depth + 1 );
			} catch ( \Throwable $unserializable ) {
				return '[object ' . get_class( $value ) . ']';
			}
		}

		return is_object( $value ) ? '[object ' . get_class( $value ) . ']' : '[' . get_debug_type( $value ) . ']';
	}

	/**
	 * Writes an exception down: what it is and where it came from, never the values it held.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $throwable The exception.
	 * @param int        $depth     How deep it is nested.
	 * @param bool       $outermost Whether it is the one the caller passed, which alone gets a stack trace.
	 * @return array<string, mixed> The exception, redacted.
	 */
	private function throwable( \Throwable $throwable, int $depth, bool $outermost ): array {
		if ( $throwable instanceof StatementDiagnostic ) {
			$written = array(
				'class'          => get_class( $throwable ),
				'statement'      => $this->kept( self::withoutLiterals( $throwable->statement() ) ),
				'server_message' => $this->kept( self::withoutLiterals( $throwable->serverMessage() ) ),
			);
		} elseif ( $throwable instanceof CodedException ) {
			$written = array(
				'class'   => get_class( $throwable ),
				'code'    => (string) $throwable->errorCode()->value,
				'context' => $this->entries( $throwable->context(), $depth + 1 ),
				'file'    => self::path( $throwable->getFile() ),
				'line'    => $throwable->getLine(),
			);
		} else {
			$written = array(
				'class'   => get_class( $throwable ),
				'message' => $this->kept( $throwable->getMessage() ),
				'file'    => self::path( $throwable->getFile() ),
				'line'    => $throwable->getLine(),
			);

			if ( $outermost ) {
				$written['trace'] = self::frames( $throwable );
			}
		}

		$previous = $throwable->getPrevious();

		if ( null !== $previous ) {
			$written['previous'] = $depth >= self::MAX_DEPTH ? self::TOO_DEEP : $this->throwable( $previous, $depth + 1, false );
		}

		return $written;
	}

	/**
	 * Keeps a float unless it would print as a card number.
	 *
	 * @since 0.1.0
	 *
	 * @param float $number The number.
	 * @return float|string The number; the marker in its place; or a name for a value JSON cannot hold.
	 */
	private static function number( float $number ): float|string {
		if ( is_nan( $number ) ) {
			return 'NAN';
		}

		if ( is_infinite( $number ) ) {
			return $number > 0 ? 'INF' : '-INF';
		}

		// The exact text the log's JSON encoder writes for it, and its whole part written out in full.
		$printed = array(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() writes a float as json_encode() does, and this class may not call WordPress.
			(string) json_encode( $number ),
		);

		if ( abs( $number ) < 1e21 ) {
			$printed[] = sprintf( '%.0f', $number - fmod( $number, 1.0 ) );
		}

		foreach ( $printed as $text ) {
			if ( CardNumbers::contains( $text ) ) {
				return CardNumbers::MARKER;
			}
		}

		return $number;
	}

	/**
	 * Applies the free-text policy: declared pairs and email addresses go.
	 *
	 * A removed value is checked for a card number first, so its removal is still reported.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Valid UTF-8.
	 * @return string The text, or a placeholder when it could not be scanned.
	 */
	private function freeText( string $text ): string {
		$text = $this->pairs( $text );

		if ( null === $text ) {
			return self::UNSCANNABLE;
		}

		$text = preg_replace_callback( self::EMAIL, fn( array $address ): string => $this->removed( $address[0], self::REDACTED ), $text );

		return null === $text ? self::UNSCANNABLE : $text;
	}

	/**
	 * Removes the pairs in a text whose names are declared: a secret's whole, a personal-data value's value.
	 *
	 * Pairs are taken in order. A pair inside the value of a pair already removed goes with it;
	 * a pair inside the value of one that is kept is looked at on its own.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Valid UTF-8.
	 * @return string|null The text, or null when it could not be scanned.
	 */
	private function pairs( string $text ): ?string {
		if ( false === preg_match_all( self::PAIR_START, $text, $pairs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$kept = '';
		$at   = 0;

		foreach ( $pairs as $pair ) {
			list( $whole, $start ) = $pair[0];

			$declared = $start < $at ? null : $this->declaredName( $pair['name'][0] );

			if ( null === $declared ) {
				continue;
			}

			$valueStart = $start + strlen( $whole );
			$found      = preg_match( self::PAIR_VALUE_AT, $text, $value, 0, $valueStart );

			if ( false === $found ) {
				return null;
			}

			if ( 0 === $found ) {
				continue;
			}

			$opening = $value[0][0];

			if ( ( '"' === $opening || '\'' === $opening ) && 1 !== preg_match( self::QUOTED_AT, $text, $closed, 0, $valueStart ) ) {
				// A quoted value that is never closed: all that follows may be the value, so all of it goes.
				$value = array( substr( $text, $valueStart ) );
			}

			$end   = $valueStart + strlen( $value[0] );
			$kept .= substr( $text, $at, $start - $at );

			if ( isset( $this->secret[ $declared ] ) ) {
				$this->removed( substr( $text, $start, $end - $start ), '' );
			} else {
				$quote = '"' === $value[0][0] || '\'' === $value[0][0] ? $value[0][0] : '';
				$kept .= $whole . $this->removed( $value[0], $quote . self::REDACTED . $quote );
			}

			$at = $end;
		}

		return $kept . substr( $text, $at );
	}

	/**
	 * Returns the declared name a pair's name stands for, or null when it names nothing declared.
	 *
	 * Quotes and square brackets around the name are taken off and percent-escapes decoded, up
	 * to three times over for a name encoded more than once. A name that ends in a bracketed
	 * part, as `outer[name]` does, also stands for that part.
	 *
	 * @since 0.1.0
	 *
	 * @param string $written The name as the text writes it.
	 * @return string|null The lowercase declared name, or null.
	 */
	private function declaredName( string $written ): ?string {
		$name = in_array( $written[0], array( '"', '\'', '[' ), true ) ? trim( substr( $written, 1, -1 ), '"\'' ) : $written;

		for ( $round = 0; $round < 3; $round++ ) {
			$decoded = rawurldecode( $name );

			if ( $decoded === $name ) {
				break;
			}

			$name = $decoded;
		}

		$candidates = array( strtolower( trim( $name ) ) );

		if ( 1 === preg_match( '/\[([^\[\]]+)\]$/', $name, $last ) ) {
			$candidates[] = strtolower( trim( $last[1], " \t\"'" ) );
		}

		foreach ( $candidates as $candidate ) {
			if ( isset( $this->secret[ $candidate ] ) || isset( $this->personal[ $candidate ] ) ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * Returns what replaces a removed piece of free text, noting whether it held a card number.
	 *
	 * @since 0.1.0
	 *
	 * @param string $removed     What is removed; never kept.
	 * @param string $replacement What takes its place.
	 * @return string The replacement.
	 */
	private function removed( string $removed, string $replacement ): string {
		if ( CardNumbers::contains( $removed ) ) {
			$this->cardNumberRemoved = true;
		}

		return $replacement;
	}

	/**
	 * Replaces every literal in a SQL statement or a server message.
	 *
	 * Only its first READ_LENGTH bytes are looked at, which is more than a line keeps of it; a
	 * literal they cut off is replaced to their end.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql The text.
	 * @return string The text with each quoted string, hexadecimal literal and number replaced by a question mark.
	 */
	private static function withoutLiterals( string $sql ): string {
		if ( strlen( $sql ) > self::READ_LENGTH ) {
			$sql = mb_strcut( $sql, 0, self::READ_LENGTH, 'UTF-8' );
		}

		return (string) preg_replace( array_keys( self::SQL_LITERALS ), array_values( self::SQL_LITERALS ), $sql );
	}

	/**
	 * Shortens a file path to what locates the code, without the server's directory layout.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file The absolute path.
	 * @return string The path below the WordPress root, or the last directory and the file name.
	 */
	private static function path( string $file ): string {
		if ( str_starts_with( $file, ABSPATH ) ) {
			return substr( $file, strlen( ABSPATH ) );
		}

		return basename( dirname( $file ) ) . '/' . basename( $file );
	}

	/**
	 * Lists where an exception passed through, without any argument values.
	 *
	 * @since 0.1.0
	 *
	 * @param \Throwable $throwable The exception.
	 * @return list<string> Up to MAX_FRAMES frames, innermost first: `Class->method() dir/file.php:line`.
	 */
	private static function frames( \Throwable $throwable ): array {
		$frames = array();

		foreach ( array_slice( $throwable->getTrace(), 0, self::MAX_FRAMES ) as $frame ) {
			$where    = isset( $frame['file'] ) ? ' ' . self::path( $frame['file'] ) . ':' . ( $frame['line'] ?? 0 ) : '';
			$frames[] = ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . $frame['function'] . '()' . $where;
		}

		return $frames;
	}
}
