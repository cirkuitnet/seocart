<?php
/**
 * SweepExpiredCarts: the recurring job that deletes expired carts and their lines
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Infrastructure\Jobs;

use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * The retention sweep of the cart tables, as a recurring job.
 *
 * Owns one fact: when an expired cart leaves the tables. Every hour it deletes expired carts, a
 * page of BATCH at a time in expiry order on its index, each page's lines first and then its
 * carts, until a page comes back short or BUDGET_SECONDS is spent; a backlog is the next run's.
 * No decision depends on it: every read and every write of a cart compares its expiry with the
 * database clock, so an expired cart is gone to them already. It only keeps the tables at their
 * retention policy. A recurring job is never retried: the next run is the retry.
 *
 * @since 0.1.0
 */
final class SweepExpiredCarts implements JobHandler {

	/**
	 * How many carts one page deletes at most. A cart holds at most 50 lines, so a page deletes at most 10,000 lines.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BATCH = 200;

	/**
	 * How long one run may keep deleting, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The cart statements.
	 *
	 * @since 0.1.0
	 *
	 * @var MysqlCartRepository
	 */
	private MysqlCartRepository $carts;

	/**
	 * How many carts one page deletes at most.
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
	 * @param MysqlCartRepository $carts The cart statements.
	 * @param int                 $batch Optional. Carts one page deletes at most. Default BATCH.
	 * @param callable|null       $clock Optional. Returns a monotonic time in nanoseconds (int).
	 *                                   Default null, which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( MysqlCartRepository $carts, int $batch = self::BATCH, ?callable $clock = null ) {
		$this->carts = $carts;
		$this->batch = max( 1, $batch );
		$this->clock = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `carts.sweep_expired`.
	 */
	public static function name(): string {
		return 'carts.sweep_expired';
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
	 * Deletes pages of expired carts until a page comes back short or the budget is spent.
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

		do {
			$found = $this->carts->deleteExpired( $this->batch );
		} while ( $found >= $this->batch && ( $this->clock )() < $deadline );

		return null;
	}
}
