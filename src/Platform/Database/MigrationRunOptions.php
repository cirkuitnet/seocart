<?php
/**
 * MigrationRunOptions: how long one migration run may wait and work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The bounds of one call to Migrator::migrate().
 *
 * Owns one fact: the two limits a caller puts on a run. How long to wait for another runner's
 * schema lock before reporting "blocked", and how long to keep working through data batches
 * before stopping with "incomplete" and leaving the rest to the next scheduled attempt.
 *
 * @since 0.1.0
 */
final class MigrationRunOptions {

	/**
	 * The wait an operator at the command line gets by default, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DEFAULT_WAIT_SECONDS = 30;

	/**
	 * How long to wait for the schema lock, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $waitSeconds;

	/**
	 * How long data batches may run, in seconds, or null for no limit.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private ?int $timeBudgetSeconds;

	/**
	 * Sets the bounds.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a bound is negative.
	 *
	 * @param int      $waitSeconds       Optional. How long to wait for the schema lock. Default 30.
	 * @param int|null $timeBudgetSeconds Optional. How long data batches may run; null for no limit.
	 *                                    At least one batch always runs. Default null.
	 */
	public function __construct( int $waitSeconds = self::DEFAULT_WAIT_SECONDS, ?int $timeBudgetSeconds = null ) {
		if ( $waitSeconds < 0 || ( null !== $timeBudgetSeconds && $timeBudgetSeconds < 0 ) ) {
			throw new \InvalidArgumentException( 'Migration run bounds cannot be negative.' );
		}

		$this->waitSeconds       = $waitSeconds;
		$this->timeBudgetSeconds = $timeBudgetSeconds;
	}

	/**
	 * Returns how long to wait for the schema lock.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds.
	 */
	public function waitSeconds(): int {
		return $this->waitSeconds;
	}

	/**
	 * Returns how long data batches may run.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Seconds, or null for no limit.
	 */
	public function timeBudgetSeconds(): ?int {
		return $this->timeBudgetSeconds;
	}
}
