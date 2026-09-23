<?php
/**
 * LogRetentionJob: the recurring job that deletes log lines past retention
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging;

use SEOCart\Platform\Jobs\JobEnvelope;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Sweeps the `logs` table on a schedule, in bounded batches.
 *
 * Owns one fact: when and how much of the log's retention sweep runs. LogRetention deletes one
 * batch of the oldest lines past the retention catalog's period for `logs`; this job repeats
 * it until a batch comes back short or BUDGET_SECONDS is spent, and a backlog it cannot finish
 * is the next run's.
 *
 * Its schedule follows the same catalog entry: it runs once per retention period, but at least
 * once a day, so a line outlives its period by at most a day. With the catalog's 30 days that
 * is once a day. A recurring job is never retried: the next run is the retry.
 *
 * @since 0.1.0
 */
final class LogRetentionJob implements JobHandler {

	/**
	 * How many lines one statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BATCH = 5000;

	/**
	 * How long one run may keep deleting, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The longest time between two runs, in seconds: a day.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LONGEST_INTERVAL_SECONDS = 86400;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The sweep.
	 *
	 * @since 0.1.0
	 *
	 * @var LogRetention
	 */
	private LogRetention $retention;

	/**
	 * How many lines one statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $batch;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param LogRetention  $retention The sweep, with the catalog's period.
	 * @param int           $batch     Optional. Lines one statement deletes at most. Default BATCH.
	 * @param callable|null $clock     Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                                 null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( LogRetention $retention, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->retention = $retention;
		$this->batch     = max( 1, $batch );
		$this->clock     = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `logs.prune`.
	 */
	public static function name(): string {
		return 'logs.prune';
	}

	/**
	 * Returns how often the handler runs: once per retention period, and at least once a day.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds between runs, read from the retention catalog.
	 */
	public static function recurrence(): int {
		return max( JobEnvelope::MIN_INTERVAL_SECONDS, min( self::LONGEST_INTERVAL_SECONDS, LogRetention::seconds( LogRetention::cataloguedPeriod() ) ) );
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1: the next run is the retry.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Deletes batches of lines past the period until a batch comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + self::BUDGET_SECONDS * self::NANOSECONDS;

		do {
			$deleted = $this->retention->sweep( $this->batch );
		} while ( $deleted >= $this->batch && ( $this->clock )() < $deadline );

		return null;
	}
}
