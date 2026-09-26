<?php
/**
 * InstantFormat: how a calculation writes an instant into its trace and its totals
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Pricing\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Writes an instant the one way the calculation records instants.
 *
 * Owns one fact: the text form of every instant in a calculation's trace and totals: ISO 8601,
 * in UTC, to the microsecond, such as `2026-09-25T10:00:00.000000Z`. One form, so a replay
 * compares instants as text.
 *
 * @since 0.1.0
 */
final class InstantFormat {

	/**
	 * Writes an instant.
	 *
	 * @since 0.1.0
	 *
	 * @param \DateTimeImmutable $instant The instant, in any time zone.
	 * @return string The instant in UTC.
	 */
	public static function of( \DateTimeImmutable $instant ): string {
		return $instant->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' );
	}
}
