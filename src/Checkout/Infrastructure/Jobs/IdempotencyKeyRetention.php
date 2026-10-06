<?php
/**
 * IdempotencyKeyRetention: deletes expired idempotency keys, as a recurring job
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Infrastructure\Jobs;

use SEOCart\Checkout\Infrastructure\MysqlIdempotencyKeys;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Jobs\BoundedSweep;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The retention sweep of `idempotency_keys`, as a recurring job.
 *
 * Owns one fact: when an expired key leaves the table. Every hour it deletes expired keys, a
 * batch of BATCH at a time in expiry order on its index, until a batch comes back short or
 * BUDGET_SECONDS is spent; a backlog is the next run's. A key expires the retention period after
 * it was claimed, and expiry is judged by the database clock in the statement that deletes, so a
 * key that is still live is never touched. No decision depends on the job: a claim ignores an
 * expired key already. It only keeps the table at its retention policy. A recurring job is never
 * retried: the next run is the retry.
 *
 * @since 0.1.0
 */
final class IdempotencyKeyRetention implements JobHandler {

	/**
	 * How many keys one statement deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BATCH = 1000;

	/**
	 * How long one run may keep deleting, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * The key statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlIdempotencyKeys
	 */
	private MysqlIdempotencyKeys $keys;

	/**
	 * The loop that repeats the delete, in batches and within the budget.
	 *
	 * @since 0.1.0
	 *
	 * @var BoundedSweep
	 */
	private BoundedSweep $batches;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param MysqlIdempotencyKeys $keys  The key statements.
	 * @param int                  $batch Optional. Keys one statement deletes at most. Default BATCH.
	 * @param callable|null        $clock Optional. Returns a monotonic time in nanoseconds (int).
	 *                                    Default null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( MysqlIdempotencyKeys $keys, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->keys    = $keys;
		$this->batches = new BoundedSweep( $batch, self::BUDGET_SECONDS, $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `idempotency_keys.prune`.
	 */
	public static function name(): string {
		return 'idempotency_keys.prune';
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
	 * Deletes batches of expired keys until a batch comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @throws DatabaseException When a statement fails.
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$this->batches->run( $this->keys->deleteExpired( ... ) );

		return null;
	}
}
