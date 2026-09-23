<?php
/**
 * SystemClock: the production Clock, reading the system time in UTC
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The Clock that production code runs with.
 *
 * This class owns one fact: where "now" comes from outside tests — the system clock, read in
 * UTC whatever PHP's default time zone or the site's time zone setting is. Tests use the
 * doubles in tests/Support/Doubles/ instead, so no test depends on the wall clock.
 *
 * @since 0.1.0
 */
final class SystemClock implements Clock {

	/**
	 * Returns the current instant.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The current instant, to the microsecond, with its time zone set to UTC.
	 */
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
