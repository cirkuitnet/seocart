<?php
/**
 * DateRange: a span of time from a start instant to an optional end
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A half-open span of time, [start, end), in UTC; the end may be open.
 *
 * This class owns one fact: what it means for an instant to fall inside a window — a sale
 * price's schedule, a promotion's validity. The start is included and the end is not, so two
 * windows that meet at an instant never both contain it. A window without an end runs forever.
 *
 * Instants are kept in UTC, like everything SEOCart stores. A rule that is meant in the
 * store's calendar ("the sale ends at midnight in Berlin") is turned into instants by the code
 * that knows the store's time zone, before it builds the range.
 *
 * @since 0.1.0
 */
final class DateRange {

	/**
	 * The first instant inside the range, in UTC.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $start;

	/**
	 * The first instant after the range, in UTC, or null when the range has no end.
	 *
	 * @since 0.1.0
	 *
	 * @var \DateTimeImmutable|null
	 */
	private ?\DateTimeImmutable $end;

	/**
	 * Creates a range.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable      $start The first instant inside the range.
	 * @param \DateTimeImmutable|null $end   The first instant after it, or null for no end.
	 */
	private function __construct( \DateTimeImmutable $start, ?\DateTimeImmutable $end ) {
		$utc = new \DateTimeZone( 'UTC' );

		$this->start = $start->setTimezone( $utc );
		$this->end   = null === $end ? null : $end->setTimezone( $utc );
	}

	/**
	 * Creates a range with an end.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the end is not after the start.
	 *
	 * @param \DateTimeImmutable $start The first instant inside the range. Any time zone.
	 * @param \DateTimeImmutable $end   The first instant after it. Any time zone; must be later.
	 * @return self The range.
	 */
	public static function between( \DateTimeImmutable $start, \DateTimeImmutable $end ): self {
		if ( $end <= $start ) {
			throw new \InvalidArgumentException( 'A date range ends after it starts; an empty range contains no instant.' );
		}

		return new self( $start, $end );
	}

	/**
	 * Creates a range with no end.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $start The first instant inside the range. Any time zone.
	 * @return self The range.
	 */
	public static function startingAt( \DateTimeImmutable $start ): self {
		return new self( $start, null );
	}

	/**
	 * Returns the first instant inside the range.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The start, in UTC.
	 */
	public function start(): \DateTimeImmutable {
		return $this->start;
	}

	/**
	 * Returns the first instant after the range.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable|null The end, in UTC, or null when the range has no end.
	 */
	public function end(): ?\DateTimeImmutable {
		return $this->end;
	}

	/**
	 * Tells whether an instant falls inside the range: at or after the start, and before the end.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant. Any time zone.
	 * @return bool True when the instant is inside the range.
	 */
	public function contains( \DateTimeImmutable $instant ): bool {
		return $instant >= $this->start && ( null === $this->end || $instant < $this->end );
	}
}
