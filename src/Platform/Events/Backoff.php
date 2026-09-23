<?php
/**
 * Backoff: how long a stored event waits before its delivery is tried again
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The delay before the next delivery attempt of a stored event.
 *
 * Owns one fact: the retry schedule. It is `min( 60 × 4^(attempts − 1), 3600 )` seconds: 1,
 * 4, 16, 60 and then 60 minutes. A pure function.
 *
 * @since 0.1.0
 */
final class Backoff {

	/**
	 * The delay after the first failed attempt, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FIRST_DELAY_SECONDS = 60;

	/**
	 * How much each further attempt multiplies the delay by.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FACTOR = 4;

	/**
	 * The longest delay, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const MAX_DELAY_SECONDS = 3600;

	/**
	 * Returns the delay after a number of failed attempts.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the number of attempts is below 1.
	 *
	 * @param int $attempts How many attempts have failed, from 1.
	 * @return int The delay in seconds.
	 */
	public static function seconds( int $attempts ): int {
		if ( $attempts < 1 ) {
			throw new \InvalidArgumentException( 'A delay follows at least one failed attempt.' );
		}

		$delay = self::FIRST_DELAY_SECONDS;

		for ( $attempt = 1; $attempt < $attempts && $delay < self::MAX_DELAY_SECONDS; ++$attempt ) {
			$delay *= self::FACTOR;
		}

		return min( $delay, self::MAX_DELAY_SECONDS );
	}
}
