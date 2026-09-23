<?php
/**
 * Level: how serious a log line is
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * The severity of a log line, from the most detailed to the most serious.
 *
 * Owns one fact: the levels a line can have and their order. The logger writes a line only
 * when its level reaches the minimum the logger was built with, and an administrator filters
 * the log by level.
 *
 * @since 0.1.0
 */
enum Level: string {

	/**
	 * Detail for a developer tracing a problem; written only when the minimum is lowered to it.
	 *
	 * @since 0.1.0
	 */
	case Debug = 'debug';

	/**
	 * Something worth knowing happened as expected.
	 *
	 * @since 0.1.0
	 */
	case Info = 'info';

	/**
	 * Something went wrong, and the work that found it carried on.
	 *
	 * @since 0.1.0
	 */
	case Warning = 'warning';

	/**
	 * Something failed, and a request or a job ended because of it.
	 *
	 * @since 0.1.0
	 */
	case Error = 'error';

	/**
	 * Tells whether a line's level is at least as serious as a minimum.
	 *
	 * @since 0.1.0
	 *
	 * @param Level $level   The level of a line.
	 * @param Level $minimum The minimum.
	 * @return bool True when the line is at least as serious as the minimum.
	 */
	public static function reaches( Level $level, Level $minimum ): bool {
		return self::rank( $level ) >= self::rank( $minimum );
	}

	/**
	 * Returns a level's place in the order, the most detailed first.
	 *
	 * @since 0.1.0
	 *
	 * @param Level $level The level.
	 * @return int 0 for debug, up to 3 for error.
	 */
	private static function rank( Level $level ): int {
		return match ( $level ) {
			self::Debug   => 0,
			self::Info    => 1,
			self::Warning => 2,
			self::Error   => 3,
		};
	}
}
