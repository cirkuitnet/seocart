<?php
/**
 * BoundedSweep: deletes a backlog one bounded batch at a time, within a time budget
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The loop of a retention job: delete one batch, then another, until a batch comes back short or the run's budget is spent.
 *
 * Owns one fact: when a sweep of bounded batches stops. The job that owns the sweep says what
 * one batch deletes, how many rows it may delete and how long a run may last. Each batch deletes
 * at most that many, so no statement holds a large lock. A batch that comes back short found
 * nothing more to delete, and ends the run. So does the budget: once it is spent, no further
 * batch starts, and the backlog is the next run's. The first batch always runs. The clock is
 * read once before it, and once after each batch that came back full.
 *
 * Run it outside any transaction: it is maintenance work for a scheduled job.
 *
 * @since 0.1.0
 */
final class BoundedSweep {

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * How many rows one batch deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $batch;

	/**
	 * How long one run may keep starting batches, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $budgetSeconds;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Creates the sweep. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $batch         How many rows one batch deletes at most. Raised to 1 when lower.
	 * @param int           $budgetSeconds How long one run may keep starting batches, in seconds.
	 * @param callable|null $clock         Optional. Returns a monotonic time in nanoseconds (int). Default
	 *                                     null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( int $batch, int $budgetSeconds, ?callable $clock = null ) {
		$this->batch         = max( 1, $batch );
		$this->budgetSeconds = $budgetSeconds;
		$this->clock         = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Runs batches until one comes back short or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $deleteBatch Deletes one batch of at most the number of rows it is given, and
	 *                              returns how many it deleted: fewer than that number only when
	 *                              nothing more was left to delete.
	 *
	 * @phpstan-param callable(int): int $deleteBatch
	 */
	public function run( callable $deleteBatch ): void {
		$deadline = ( $this->clock )() + $this->budgetSeconds * self::NANOSECONDS;

		do {
			$deleted = $deleteBatch( $this->batch );
		} while ( $deleted >= $this->batch && ( $this->clock )() < $deadline );
	}
}
