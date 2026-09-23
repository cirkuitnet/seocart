<?php
/**
 * LoggingJob: a job handler that writes one log line when it runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Logging;

use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Logging\Logger;

/**
 * Writes the line CODE through the logger it is given, once per run.
 *
 * Owns one fact for the tests: what a job does that logs while it runs, so a test can read which
 * correlation id the line carries.
 *
 * @since 0.1.0
 */
final class LoggingJob implements JobHandler {

	/**
	 * The handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'test.logs_a_line';

	/**
	 * The code of the line it writes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CODE = 'test.job_line';

	/**
	 * The logger.
	 *
	 * @since 0.1.0
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param Logger $logger The logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Returns null: it runs when a job is queued.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Null.
	 */
	public static function recurrence(): ?int {
		return null;
	}

	/**
	 * Returns one attempt.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Writes the line.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: done.
	 */
	public function handle( array $payload ): ?int {
		$this->logger->info( self::CODE, 'A job logs while it runs.' );

		return null;
	}
}
