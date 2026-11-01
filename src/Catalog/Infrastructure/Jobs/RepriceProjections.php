<?php
/**
 * RepriceProjections: rewrites the converted sort prices behind the current exchange-rate version, page by page
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Infrastructure\Jobs;

use SEOCart\Catalog\Application\Projection\CurrencyTerms;
use SEOCart\Catalog\Application\Projection\PriceProjections;
use SEOCart\Catalog\Application\Projection\PriceProjector;
use SEOCart\Inventory\Infrastructure\Jobs\SweepHolds;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Jobs\JobHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Reprices, after a new exchange-rate version, the products whose sort price was converted at an earlier one, until none is left or its time is spent.
 *
 * Owns one fact: the bounds of a repricing run. A page is up to PAGE products with a converted price
 * behind the current version; each is projected again by PriceProjector::project(), which rewrites
 * the converted rows at the current rate and leaves every authored one as it is. A page's rows leave
 * the set as they are written, so the next page needs no cursor. Each page holds the outbox
 * drainer's lock, OutboxDrainer::LOCK_NAME, without waiting: a drain at work, which projects saved
 * products, and a page never project at once; a page that finds the lock held asks to run again a
 * moment later. A run ends when a page comes back empty against the version read again for that
 * page, so a version saved while it ran is repriced by the same run; when BUDGET_SECONDS is spent it
 * asks to run again a moment later. Until it ends, a price order in another currency than the base
 * one sorts some products at the earlier rate; a cart prices them at the current one.
 *
 * Its jobs are queued by PriceProjector, one per version.
 *
 * @since 0.2.0
 */
final class RepriceProjections implements JobHandler {

	/**
	 * How long one run may keep repricing, in seconds: the hold sweep's budget.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const BUDGET_SECONDS = SweepHolds::BUDGET_SECONDS;

	/**
	 * How soon a run that spent its budget, or found the drainer's lock held, runs again, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const AGAIN_AFTER_SECONDS = 1;

	/**
	 * How many products one page reprices.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const PAGE = 200;

	/**
	 * How long a table-mode lease on the drainer's lock lasts: far longer than a page takes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const LOCK_TTL_SECONDS = 60;

	/**
	 * Nanoseconds in a second.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const NANOSECONDS = 1000000000;

	/**
	 * The sort prices.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjections
	 */
	private PriceProjections $projections;

	/**
	 * Projects a page of products.
	 *
	 * @since 0.2.0
	 *
	 * @var PriceProjector
	 */
	private PriceProjector $projector;

	/**
	 * The current exchange-rate version.
	 *
	 * @since 0.2.0
	 *
	 * @var CurrencyTerms
	 */
	private CurrencyTerms $terms;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * How long one run may keep repricing, in seconds.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $budgetSeconds;

	/**
	 * Returns a monotonic time in nanoseconds.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(): int
	 */
	private \Closure $clock;

	/**
	 * Creates the handler. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param PriceProjections $projections   The sort prices.
	 * @param PriceProjector   $projector     Projects a page of products.
	 * @param CurrencyTerms    $terms         The current exchange-rate version.
	 * @param callable         $withLock      Takes a named lock (string), for a TTL and a wait (ints, seconds), runs work
	 *                                        (callable) while holding it and releases it: LockService::withLock().
	 * @param int              $budgetSeconds Optional. How long one run may keep repricing. Default BUDGET_SECONDS. At 0 a run reprices one page.
	 * @param callable|null    $clock         Optional. Returns a monotonic time in nanoseconds (int). Default null, which uses hrtime().
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 * @phpstan-param (callable(): int)|null                      $clock
	 */
	public function __construct( PriceProjections $projections, PriceProjector $projector, CurrencyTerms $terms, callable $withLock, int $budgetSeconds = self::BUDGET_SECONDS, ?callable $clock = null ) {
		$this->projections   = $projections;
		$this->projector     = $projector;
		$this->terms         = $terms;
		$this->withLock      = $withLock;
		$this->budgetSeconds = max( 0, $budgetSeconds );
		$this->clock         = null === $clock ? static fn(): int => (int) hrtime( true ) : \Closure::fromCallable( $clock );
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string `catalog.reprice_projections`.
	 */
	public static function name(): string {
		return PriceProjector::REPRICE_JOB;
	}

	/**
	 * Returns how often the handler runs on its own: never, it runs when a rate version is saved.
	 *
	 * @since 0.2.0
	 *
	 * @return int|null Null.
	 */
	public static function recurrence(): ?int {
		return null;
	}

	/**
	 * Returns the attempts per queued run.
	 *
	 * @since 0.2.0
	 *
	 * @return int 3.
	 */
	public static function maxAttempts(): int {
		return 3;
	}

	/**
	 * Reprices pages of products until none is behind the current version or the budget is spent.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, int|string|bool|null> $payload The job's payload: rate_version, the version saved, for the record; the
	 *                                                     version repriced to is the current one, read for each page.
	 * @return int|null Null once a page finds nothing behind the current version, or no version is current; AGAIN_AFTER_SECONDS
	 *                  when the drainer's lock was held or the budget was spent.
	 */
	public function handle( array $payload ): ?int {
		unset( $payload );

		$deadline = ( $this->clock )() + $this->budgetSeconds * self::NANOSECONDS;

		do {
			$version = $this->terms->rateVersion();

			if ( null === $version ) {
				return null;
			}

			try {
				$repriced = ( $this->withLock )( OutboxDrainer::LOCK_NAME, self::LOCK_TTL_SECONDS, 0, fn(): int => $this->page( $version ) );
			} catch ( LockNotAcquired $held ) {
				return self::AGAIN_AFTER_SECONDS;
			}

			if ( 0 === $repriced ) {
				return null;
			}
		} while ( ( $this->clock )() < $deadline );

		return self::AGAIN_AFTER_SECONDS;
	}

	/**
	 * Reprices one page of the products with a converted price behind a version.
	 *
	 * @since 0.2.0
	 *
	 * @param int $version The current version.
	 * @return int How many products the page held; 0 when none is behind.
	 */
	private function page( int $version ): int {
		$products = $this->projections->staleProductIds( $version, self::PAGE );

		$this->projector->project( $products );

		return count( $products );
	}
}
