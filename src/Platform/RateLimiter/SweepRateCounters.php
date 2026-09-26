<?php
/**
 * SweepRateCounters: deletes the counter rows whose window has ended
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The retention sweep of the `rate_counters` table, as a recurring job.
 *
 * Owns one fact: when counter rows leave the table. Every hour it deletes, in batches of BATCH
 * rows in `expires_at` order on its index, the rows whose window has ended by the database clock,
 * until a batch comes back short or BUDGET_SECONDS is spent; a backlog is the next run's. No
 * decision depends on it: an ended window is never counted again, because the next window has a
 * row of its own. It only keeps the table small, which it must do often, since a busy store
 * writes a row per client and window. A recurring job is never retried: the next run is the retry.
 *
 * @since 0.1.0
 */
final class SweepRateCounters implements JobHandler {

	/**
	 * How many rows one statement deletes at most.
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
	 * The statement that deletes one batch of ended rows. Placeholders: the table, the batch size.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DELETE_ENDED = 'DELETE FROM %i WHERE expires_at < UTC_TIMESTAMP() ORDER BY expires_at LIMIT %d';

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * How many rows one statement deletes at most.
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
	 * @param Database      $db    The connection.
	 * @param int           $batch Optional. Rows one statement deletes at most. Default BATCH.
	 * @param callable|null $clock Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                             null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( Database $db, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->db    = $db;
		$this->batch = max( 1, $batch );
		$this->clock = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `rate_counters.sweep`.
	 */
	public static function name(): string {
		return 'rate_counters.sweep';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Every hour.
	 */
	public static function recurrence(): int {
		return 3600;
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
	 * Deletes batches of ended rows until a batch comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When a statement fails.
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + self::BUDGET_SECONDS * self::NANOSECONDS;
		$table    = $this->db->table( RateCountersTable::NAME );

		do {
			$deleted = $this->db->execute( self::DELETE_ENDED, $table, $this->batch );
		} while ( $deleted >= $this->batch && ( $this->clock )() < $deadline );

		return null;
	}
}
