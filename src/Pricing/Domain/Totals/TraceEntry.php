<?php
/**
 * TraceEntry: one event of a calculation, as its trace records it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain\Totals;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The messages name values built by code, for the developer; they are never rendered.

/**
 * One line of a calculation's trace: the step it happened in, what kind of event it was, and its data.
 *
 * Owns one fact: the shape of a trace entry, which an order stores as JSON for years. Its data
 * holds only scalars, nulls and lists of scalars, never an object, so the stored trace reads
 * back as the array it was written from and can be replayed without the code that wrote it. It
 * holds no float either: every figure is an exact decimal string or a count of minor units.
 *
 * @since 0.1.0
 */
final readonly class TraceEntry {

	/**
	 * A fact of the input.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INPUT = 'input';

	/**
	 * An intent a promotion stated.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const INTENT = 'intent';

	/**
	 * A quote the calculation applied.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const QUOTE = 'quote';

	/**
	 * An adjustment the calculation made.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ADJUSTMENT = 'adjustment';

	/**
	 * A rounding boundary: the value before it, the value after it, and how it was rounded.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ROUNDING = 'rounding';

	/**
	 * A choice among quoted options, such as the shipping rate.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SELECTION = 'selection';

	/**
	 * Something the calculation left out, and why.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SKIPPED = 'skipped';

	/**
	 * Every kind of entry.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const KINDS = array( self::INPUT, self::INTENT, self::QUOTE, self::ADJUSTMENT, self::ROUNDING, self::SELECTION, self::SKIPPED );

	/**
	 * Checks and holds the entry.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the kind is unknown, or a value of the data is not a
	 *                                   string, an integer, a boolean, null or a list of those.
	 *
	 * @param string $step The step the event happened in, such as `b6.tax`.
	 * @param string $kind One of the kinds above.
	 * @param array  $data The event's data, keyed by name.
	 *
	 * @phpstan-param array<string, mixed> $data
	 */
	public function __construct(
		public string $step,
		public string $kind,
		public array $data
	) {
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'A trace entry is of a known kind; "%s" is not one.', $kind ) );
		}

		foreach ( $data as $name => $value ) {
			if ( ! self::isTraceValue( $value ) ) {
				throw new \InvalidArgumentException( sprintf( 'The value "%s" of a trace entry must be a string, an integer, a boolean, null or a list of those.', $name ) );
			}
		}
	}

	/**
	 * Returns the entry as an array.
	 *
	 * @since 0.1.0
	 *
	 * @return array{step: string, kind: string, data: array<string, mixed>} The entry.
	 */
	public function toArray(): array {
		return array(
			'step' => $this->step,
			'kind' => $this->kind,
			'data' => $this->data,
		);
	}

	/**
	 * Tells whether a value may be stored in a trace entry.
	 *
	 * A float may not: a trace holds exact figures, written as decimal strings.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value.
	 * @return bool True for a string, an integer, a boolean, null, or a list of strings, integers and booleans.
	 */
	private static function isTraceValue( mixed $value ): bool {
		if ( null === $value || is_string( $value ) || is_int( $value ) || is_bool( $value ) ) {
			return true;
		}

		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}

		foreach ( $value as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) && ! is_bool( $item ) ) {
				return false;
			}
		}

		return true;
	}
}
