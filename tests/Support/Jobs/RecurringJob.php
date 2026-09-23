<?php
/**
 * RecurringJob: a fixture recurring job handler that records its runs
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Logging\CorrelationId;

/**
 * A handler that recurs every `$every` seconds, records each run, and throws when told to.
 *
 * Owns one fact: what the jobs tests observe of a recurring run. `$every` is static so a test
 * can change the declared interval between two registries, as a new release would.
 *
 * @since 0.1.0
 */
final class RecurringJob implements JobHandler {

	/**
	 * The handler name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'test.recurring';

	/**
	 * The declared interval, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public static int $every = 60;

	/**
	 * Whether the next run throws.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public static bool $fail = false;

	/**
	 * The correlation id in force during each run, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	public static array $runs = array();

	/**
	 * The correlation id holder.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param CorrelationId $correlation The correlation id holder.
	 */
	public function __construct( CorrelationId $correlation ) {
		$this->correlation = $correlation;
	}

	/**
	 * Restores the defaults.
	 *
	 * @since 0.1.0
	 */
	public static function reset(): void {
		self::$every = 60;
		self::$fail  = false;
		self::$runs  = array();
	}

	/**
	 * Returns the handler name.
	 *
	 * @since 0.1.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Returns how often it recurs.
	 *
	 * @since 0.1.0
	 *
	 * @return int The declared interval.
	 */
	public static function recurrence(): int {
		return self::$every;
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Records the run.
	 *
	 * @since 0.1.0
	 *
	 * @throws \RuntimeException When told to fail.
	 *
	 * @param array<string, int|string|bool|null> $payload The payload.
	 * @return int A delay, which the runner must ignore for a recurring run.
	 */
	public function handle( array $payload ): int {
		self::$runs[] = $this->correlation->current();

		if ( self::$fail ) {
			throw new \RuntimeException( 'Planned recurring failure' );
		}

		return 5;
	}
}
