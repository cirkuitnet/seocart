<?php
/**
 * PickupHours: when a location is open for pickup on one day of the week
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Domain;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message reports a caller's programming error to the developer; it is never HTML.

/**
 * One day a location is open for pickup: the day, and the local times it opens and closes.
 *
 * Owns one fact: what makes a day's pickup hours valid. The day is 0 for Sunday to 6 for
 * Saturday, the times are written `HH:MM` on a 24-hour clock, and the location opens before it
 * closes on the same day. A day a location is closed has no PickupHours at all.
 *
 * @since 0.2.0
 */
final readonly class PickupHours {

	/**
	 * The days of a week, numbered 0 for Sunday to 6 for Saturday.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const DAYS = 7;

	/**
	 * The shape of a time of the day: `HH:MM`, 00:00 to 23:59.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d\z/';

	/**
	 * Records a day's hours.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the values do not make a valid day: a caller's bug, since input is checked with isValid() first.
	 *
	 * @param int    $weekday  The day, 0 for Sunday to 6 for Saturday.
	 * @param string $opensAt  When the location opens, `HH:MM`.
	 * @param string $closesAt When it closes, `HH:MM`, later than it opens.
	 */
	public function __construct(
		public int $weekday,
		public string $opensAt,
		public string $closesAt
	) {
		if ( ! self::isValid( $weekday, $opensAt, $closesAt ) ) {
			throw new \InvalidArgumentException( sprintf( 'Pickup hours are a day from 0 to 6 and two times written HH:MM, the first earlier; %d, "%s" and "%s" were given.', $weekday, $opensAt, $closesAt ) );
		}
	}

	/**
	 * Tells whether values make a valid day of pickup hours.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $weekday  The day.
	 * @param string $opensAt  When the location opens.
	 * @param string $closesAt When it closes.
	 * @return bool True when the day is 0 to 6, both times are `HH:MM`, and it opens before it closes.
	 */
	public static function isValid( int $weekday, string $opensAt, string $closesAt ): bool {
		return $weekday >= 0
			&& $weekday < self::DAYS
			&& 1 === preg_match( self::TIME, $opensAt )
			&& 1 === preg_match( self::TIME, $closesAt )
			&& strcmp( $opensAt, $closesAt ) < 0;
	}
}
