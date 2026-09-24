<?php
/**
 * SweepHolds: the recurring job that reclaims expired checkout holds
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Inventory\Infrastructure\Jobs;

use SEOCart\Inventory\Application\StockService;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Reclaims expired checkout holds every five minutes, one item per transaction.
 *
 * Owns one fact: the bounds of the sweep. It pages through the items with expired, unclaimed
 * holds in ascending variant order, a page at a time, and reclaims each item's expired holds
 * in a transaction of its own with the same statements a checkout runs when it needs the
 * units; so a lock is held for one item at a time, and it changes nothing a checkout would
 * not have changed. It stops when the pages run out or BUDGET_SECONDS is spent; the rest is
 * the next run's. No correctness depends on it: a checkout that needs the units reclaims them
 * itself. The sweep keeps `held`, and the table, true for every item nobody is buying, so that
 * reads of stock levels are right.
 *
 * Two sweeps at once need no lock: each reclaim claims its rows by token, so the second finds
 * nothing to claim. An item whose reclaim fails is skipped so the others are still swept; the
 * run then fails with that error, so the runner records it.
 *
 * @since 0.1.0
 */
final class SweepHolds implements JobHandler {

	/**
	 * How long one run may keep reclaiming, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = 20;

	/**
	 * How many items one search lists at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const PAGE = 200;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The stock service.
	 *
	 * @since 0.1.0
	 *
	 * @var StockService
	 */
	private StockService $stock;

	/**
	 * How long one run may keep reclaiming, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $budgetSeconds;

	/**
	 * How many items one search lists at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $page;

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
	 * @param StockService  $stock         The stock service.
	 * @param int           $budgetSeconds Optional. How long one run may keep reclaiming. Default BUDGET_SECONDS.
	 *                                     At 0 a run reclaims one item.
	 * @param int           $page          Optional. How many items one search lists at most. Default PAGE.
	 * @param callable|null $clock         Optional. Returns a monotonic time in nanoseconds (int). Default null,
	 *                                     which uses hrtime().
	 *
	 * @phpstan-param (callable(): int)|null $clock
	 */
	public function __construct( StockService $stock, int $budgetSeconds = self::BUDGET_SECONDS, int $page = self::PAGE, ?callable $clock = null ) {
		$this->stock         = $stock;
		$this->budgetSeconds = max( 0, $budgetSeconds );
		$this->page          = max( 1, $page );
		$this->clock         = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `stock.sweep_holds`.
	 */
	public static function name(): string {
		return 'stock.sweep_holds';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Every 300 seconds.
	 */
	public static function recurrence(): int {
		return 300;
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
	 * Reclaims the expired holds of every item that has any, until the pages run out or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The first error an item's reclaim raised, once every other item was swept.
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$deadline = ( $this->clock )() + $this->budgetSeconds * self::NANOSECONDS;
		$after    = 0;
		$failure  = null;

		do {
			$variantIds = $this->stock->expiredVariants( $after, $this->page );
			$fullPage   = count( $variantIds ) === $this->page;

			foreach ( $variantIds as $variantId ) {
				$after = $variantId;

				try {
					$this->stock->reclaimExpired( $variantId );
				} catch ( CodedException $failed ) {
					$failure ??= $failed;
				}

				if ( ( $this->clock )() >= $deadline ) {
					break 2;
				}
			}
		} while ( $fullPage );

		if ( null !== $failure ) {
			throw $failure;
		}

		return null;
	}
}
