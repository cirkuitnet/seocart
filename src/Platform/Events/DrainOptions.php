<?php
/**
 * DrainOptions: the bounds of one drain of the outbox
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A bounds error is a message for the developer, never HTML.

/**
 * How long a drain may run, how much it claims at once, and when it gives up on a row.
 *
 * Owns one fact: the bounds a trigger sets for its drain. Each trigger builds its own: the end
 * of a request uses shutdown(), `wp seocart outbox drain` uses command(), and the job runner
 * builds its own with a budget of its choosing. Only the command prunes opportunistically:
 * the daily retention job prunes the outbox, so neither the end of a request nor the job
 * runner spends time on it.
 *
 * @since 0.1.0
 */
final readonly class DrainOptions {

	/**
	 * The time budget of a drain at the end of a request, in seconds.
	 *
	 * On PHP-FPM the client's connection stays open until the request's shutdown work is done,
	 * so this budget is latency added to the request that published the events.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SHUTDOWN_BUDGET_SECONDS = 2;

	/**
	 * The default time budget of `wp seocart outbox drain`, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const COMMAND_BUDGET_SECONDS = 60;

	/**
	 * The part of a row's lease a drainer keeps in reserve: it does not start a listener with less left.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const LEASE_RESERVE_SECONDS = 5;

	/**
	 * How long the drain may keep claiming and dispatching, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $timeBudgetSeconds;

	/**
	 * How many rows one claim leases at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $batchSize;

	/**
	 * After how many failed attempts a row is parked as failed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $maxAttempts;

	/**
	 * How long a claim's lease lasts, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public int $leaseSeconds;

	/**
	 * Whether a drain that dispatched something also deletes one bounded batch of rows past retention.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	public bool $prune;

	/**
	 * Sets the bounds.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a bound is out of range.
	 *
	 * @param int  $timeBudgetSeconds How long the drain may run, 1 second or more.
	 * @param int  $batchSize         Optional. Rows per claim, from 1 to 1000. Default 50.
	 * @param int  $maxAttempts       Optional. Attempts before a row is parked, 1 or more. Default 5.
	 * @param int  $leaseSeconds      Optional. The lease, longer than LEASE_RESERVE_SECONDS. Default 60.
	 * @param bool $prune             Optional. Whether to prune opportunistically. Default true.
	 */
	public function __construct( int $timeBudgetSeconds, int $batchSize = 50, int $maxAttempts = 5, int $leaseSeconds = 60, bool $prune = true ) {
		if ( $timeBudgetSeconds < 1 || $batchSize < 1 || $batchSize > 1000 || $maxAttempts < 1 || $leaseSeconds <= self::LEASE_RESERVE_SECONDS ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Drain bounds out of range: budget %d s (1 or more), batch %d (1 to 1000), attempts %d (1 or more), lease %d s (more than %d).',
					$timeBudgetSeconds,
					$batchSize,
					$maxAttempts,
					$leaseSeconds,
					self::LEASE_RESERVE_SECONDS
				)
			);
		}

		$this->timeBudgetSeconds = $timeBudgetSeconds;
		$this->batchSize         = $batchSize;
		$this->maxAttempts       = $maxAttempts;
		$this->leaseSeconds      = $leaseSeconds;
		$this->prune             = $prune;
	}

	/**
	 * Returns the bounds of a drain at the end of a request.
	 *
	 * @since 0.1.0
	 *
	 * @return self A 2-second budget, the defaults, and no pruning.
	 */
	public static function shutdown(): self {
		return new self( self::SHUTDOWN_BUDGET_SECONDS, prune: false );
	}

	/**
	 * Returns the bounds of `wp seocart outbox drain`.
	 *
	 * @since 0.1.0
	 *
	 * @param int $budgetSeconds Optional. The time budget. Default COMMAND_BUDGET_SECONDS.
	 * @return self The bounds.
	 */
	public static function command( int $budgetSeconds = self::COMMAND_BUDGET_SECONDS ): self {
		return new self( $budgetSeconds );
	}
}
