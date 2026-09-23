<?php
/**
 * Clock: the port through which SEOCart reads the current time
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the time, always in UTC.
 *
 * Domain and application code never reads the wall clock directly: it asks a Clock, so a
 * test can decide what time it is. Storage is UTC, and so is every instant this port
 * returns. A rule with calendar meaning (a sale window, a promotion date) takes an explicit
 * store time zone next to the instant; it never relies on the zone of the returned object.
 *
 * This interface owns one fact: that the current time is asked for, never read. SystemClock
 * is the production adapter; the doubles in tests/Support/Doubles/ are the ones tests use.
 *
 * @since 0.1.0
 */
interface Clock {

	/**
	 * Returns the current instant.
	 *
	 * @since 0.1.0
	 *
	 * @return \DateTimeImmutable The current instant, with its time zone set to UTC.
	 */
	public function now(): \DateTimeImmutable;
}
