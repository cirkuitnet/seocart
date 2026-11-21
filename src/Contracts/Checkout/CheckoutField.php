<?php
/**
 * CheckoutField: the one declaration of a field the checkout asks a shopper for
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Checkout;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a declaration's programming error to the developer; they are never HTML.

/**
 * One checkout field: its key, where the form shows it, what its value is, its words, its longest value, its options, the presences a store may choose for it, and its autocomplete token.
 *
 * Owns one fact: what a checkout field is. The session's address schema, the checkout's
 * validation, the form the storefront renders, the list in the store's settings and the
 * reference page are all compiled from this declaration, and none of them restates any part of
 * it. Whether the store asks for the field is not part of it: the declaration lists the
 * presences allowed, the first being the default, and the store's choice among them is one
 * setting. A field with one presence allowed is locked to it.
 *
 * - The key is lower-case snake_case, at most KEY_MAX_LENGTH characters.
 * - The label and the help are closures around literal gettext calls, translated only when a
 *   screen or an answer shows them.
 * - The longest value is counted in characters: at most LINE_MAX_LENGTH for a one-line type and
 *   TEXTAREA_MAX_LENGTH for a textarea.
 * - A select lists its options, at least one and at most MAX_OPTIONS, each a value no longer
 *   than the field's longest value and a label closure; no other type lists any.
 * - The autocomplete token is the HTML one the form gives the input, or null for none.
 * - The check, when given, is the field's own rule beyond its type: a closure given a value that
 *   already passed the field's rules, answering null when it accepts it, or the sentence that
 *   says why it does not.
 *
 * Declarations are data: building one performs no I/O, calls no WordPress function and
 * translates nothing. Experimental until SEOCart 1.0: a member may still be added.
 *
 * @since 0.2.0
 *
 * @api
 */
final readonly class CheckoutField {

	/**
	 * The longest key, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const KEY_MAX_LENGTH = 48;

	/**
	 * The longest value a one-line field may declare, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LINE_MAX_LENGTH = 255;

	/**
	 * The longest value a textarea may declare, in characters.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const TEXTAREA_MAX_LENGTH = 2000;

	/**
	 * The most options a select may list.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_OPTIONS = 50;

	/**
	 * The shape of a key: lower-case snake_case.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY_PATTERN = '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*\z/';

	/**
	 * The presences a store may choose for the field, the default first.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Presence>
	 */
	public array $presences;

	/**
	 * A select's options: each value's label closure, by value; empty for any other type.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, \Closure(): string>
	 */
	public array $options;

	/**
	 * Declares a field.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the key is not snake_case of at most KEY_MAX_LENGTH
	 *                                   characters, no presence or one twice is given, the longest
	 *                                   value is below 1 or above its type's limit, or options are
	 *                                   given to a type that is not a select, none to a select, more
	 *                                   than MAX_OPTIONS of them, one that is not a non-empty value
	 *                                   with a label, or one whose value is longer than the longest
	 *                                   value.
	 *
	 * @param string            $key          The key, lower-case snake_case.
	 * @param Section           $section      Where the form shows the field.
	 * @param CheckoutFieldType $type         What its value is.
	 * @param \Closure          $label        Returns the label through a literal gettext call.
	 * @param int               $maxLength    The longest value, in characters.
	 * @param Presence[]        $presences    The presences a store may choose, the default first.
	 * @param \Closure|null     $help         Optional. Returns a sentence the form shows under the
	 *                                        field, through a literal gettext call. Default none.
	 * @param array             $options      Optional. A select's options: each value's label
	 *                                        closure, by value. Default none.
	 * @param string|null       $autocomplete Optional. The HTML autocomplete token. Default none.
	 * @param \Closure|null     $check        Optional. The field's own rule: given a value, answers
	 *                                        null, or why it is refused. Default none.
	 *
	 * @phpstan-param \Closure(): string                  $label
	 * @phpstan-param list<Presence>                      $presences
	 * @phpstan-param (\Closure(): string)|null           $help
	 * @phpstan-param array<string, \Closure(): string>   $options
	 * @phpstan-param (\Closure(string): ?string)|null    $check
	 */
	public function __construct(
		public string $key,
		public Section $section,
		public CheckoutFieldType $type,
		public \Closure $label,
		public int $maxLength,
		array $presences,
		public ?\Closure $help = null,
		array $options = array(),
		public ?string $autocomplete = null,
		public ?\Closure $check = null
	) {
		if ( ! self::isKey( $key ) ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field key "%1$s" is not lower-case snake_case of at most %2$d characters.', $key, self::KEY_MAX_LENGTH ) );
		}

		self::checkPresences( $key, $presences );
		self::checkLength( $key, $type, $maxLength );
		self::checkOptions( $key, $type, $options, $maxLength );

		$this->presences = $presences;
		$this->options   = $options;
	}

	/**
	 * Tells whether a text can be a field's key: lower-case snake_case of at most KEY_MAX_LENGTH characters.
	 *
	 * The declaration and anything that stores a choice by field key judge a key here.
	 *
	 * @since 0.2.0
	 *
	 * @param string $key The text.
	 * @return bool True when it can be a key.
	 */
	public static function isKey( string $key ): bool {
		return 1 === preg_match( self::KEY_PATTERN, $key ) && strlen( $key ) <= self::KEY_MAX_LENGTH;
	}

	/**
	 * Returns the presence a store that made no choice gets: the first allowed.
	 *
	 * @since 0.2.0
	 *
	 * @return Presence The default.
	 */
	public function defaultPresence(): Presence {
		return $this->presences[0];
	}

	/**
	 * Tells whether a store may choose a presence for the field.
	 *
	 * @since 0.2.0
	 *
	 * @param Presence $presence The presence.
	 * @return bool True when the field allows it.
	 */
	public function allows( Presence $presence ): bool {
		return in_array( $presence, $this->presences, true );
	}

	/**
	 * Tells whether the field is locked: one presence is allowed, so no store can change it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when one presence is allowed.
	 */
	public function isLocked(): bool {
		return 1 === count( $this->presences );
	}

	/**
	 * Refuses a list of presences that is empty, holds a presence twice, or holds something else.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it does.
	 *
	 * @param string $key       The field's key, for the message.
	 * @param array  $presences The presences.
	 *
	 * @phpstan-param array<mixed> $presences
	 */
	private static function checkPresences( string $key, array $presences ): void {
		if ( array() === $presences || ! array_is_list( $presences ) ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s allows no presence: list at least one, the default first.', $key ) );
		}

		foreach ( $presences as $index => $presence ) {
			if ( ! $presence instanceof Presence || array_search( $presence, $presences, true ) !== $index ) {
				throw new \InvalidArgumentException( sprintf( 'The presences of the checkout field %1$s must be distinct Presence cases.', $key ) );
			}
		}
	}

	/**
	 * Refuses a longest value below 1, or above what the type may hold.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it is.
	 *
	 * @param string            $key       The field's key, for the message.
	 * @param CheckoutFieldType $type      The type.
	 * @param int               $maxLength The longest value.
	 */
	private static function checkLength( string $key, CheckoutFieldType $type, int $maxLength ): void {
		$limit = $type->isMultiline() ? self::TEXTAREA_MAX_LENGTH : self::LINE_MAX_LENGTH;

		if ( $maxLength < 1 || $maxLength > $limit ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s may hold at most %2$d characters, and at least 1.', $key, $limit ) );
		}
	}

	/**
	 * Refuses options on a type that is not a select, a select without any, more than MAX_OPTIONS of them, one that is not a non-empty value with a label closure, or one whose value is longer than the field's longest value, which no shopper could then choose.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When it does.
	 *
	 * @param string            $key     The field's key, for the message.
	 * @param CheckoutFieldType $type    The type.
	 * @param array             $options   The options.
	 * @param int               $maxLength The longest value, in characters.
	 *
	 * @phpstan-param array<mixed> $options
	 */
	private static function checkOptions( string $key, CheckoutFieldType $type, array $options, int $maxLength ): void {
		if ( array() !== $options && CheckoutFieldType::Select !== $type ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s lists options, which only a select has.', $key ) );
		}

		if ( array() === $options && CheckoutFieldType::Select === $type ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s is a select and lists no option, so no value could answer it.', $key ) );
		}

		if ( count( $options ) > self::MAX_OPTIONS ) {
			throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s lists more than %2$d options.', $key, self::MAX_OPTIONS ) );
		}

		foreach ( $options as $value => $label ) {
			if ( '' === (string) $value || ! $label instanceof \Closure ) {
				throw new \InvalidArgumentException( sprintf( 'Each option of the checkout field %1$s is a non-empty value with a label closure.', $key ) );
			}

			if ( mb_strlen( (string) $value ) > $maxLength ) {
				throw new \InvalidArgumentException( sprintf( 'The checkout field %1$s lists the option %2$s, longer than its longest value of %3$d characters, so no shopper could choose it.', $key, (string) $value, $maxLength ) );
			}
		}
	}
}
