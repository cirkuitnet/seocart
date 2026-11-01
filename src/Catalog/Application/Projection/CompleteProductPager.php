<?php
/**
 * CompleteProductPager: walks every complete product a page at a time, beside the outbox drain, resumably
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Catalog\Application\Projection;

use SEOCart\Platform\Database\Exception\LockLost;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Lease;
use SEOCart\Platform\Events\DrainOptions;
use SEOCart\Platform\Events\OutboxDrainer;

defined( 'ABSPATH' ) || exit;

/**
 * Hands the complete products to a piece of work a page at a time, each page while holding the outbox drainer's lock, and says how far it came.
 *
 * Owns one fact: how the catalog's maintenance commands walk the catalog safely beside the drain.
 * The pages come by id, from a cursor, through StockSummaries::completeProductIds(). Each page's
 * work runs while the walk holds OutboxDrainer::LOCK_NAME, the lock a drain holds while it delivers
 * the events the catalog's projectors hear, so a page and a drain never recompute one product at
 * once; a drain that finds the lock held leaves its events pending for the next drain. A page waits
 * up to LOCK_WAIT_SECONDS for a drain at work, and a walk that cannot take the lock in that time
 * stops with LockNotAcquired, before the page writes anything.
 *
 * After the page's work the lease is renewed: a lease lost during the page (a table-mode lease that
 * ran out, or a connection that dropped the server's lock) throws LockLost, and the page's line,
 * with the cursor that skips it, is not said, so `--after` from the last cursor said does the page
 * again. After each page the walk says how far it came, with that cursor.
 *
 * @since 0.2.0
 */
final class CompleteProductPager {

	/**
	 * How long a page waits for a drain at work to give the drainer's lock back, in seconds: twice as long as `wp seocart outbox drain` delivers for.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LOCK_WAIT_SECONDS = 2 * DrainOptions::COMMAND_BUDGET_SECONDS;

	/**
	 * How long a table-mode lease on the lock lasts: far longer than a page takes.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const LOCK_TTL_SECONDS = 60;

	/**
	 * Where the complete products are found.
	 *
	 * @since 0.2.0
	 *
	 * @var StockSummaries
	 */
	private StockSummaries $summaries;

	/**
	 * Takes a named lock, runs work while holding it and releases it: LockService::withLock().
	 *
	 * @since 0.2.0
	 *
	 * @var callable(string, int, int, callable): mixed
	 */
	private $withLock;

	/**
	 * Creates the pager. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param StockSummaries $summaries Where the complete products are found.
	 * @param callable       $withLock  Takes a named lock (string), for a TTL and a wait (ints, seconds), runs work
	 *                                  (callable, given the Lease) while holding it and releases it: LockService::withLock().
	 *
	 * @phpstan-param callable(string, int, int, callable): mixed $withLock
	 */
	public function __construct( StockSummaries $summaries, callable $withLock ) {
		$this->summaries = $summaries;
		$this->withLock  = $withLock;
	}

	/**
	 * Walks the complete products after a cursor, a page at a time, each page's work under the drainer's lock.
	 *
	 * @since 0.2.0
	 *
	 * @throws LockNotAcquired When a drain held the lock for the whole wait; the page wrote nothing.
	 * @throws LockLost        When the lock was lost during a page; that page's line is not said.
	 *
	 * @param int      $after The last product id already done, or 0.
	 * @param int      $page  How many products a page holds, 1 or more.
	 * @param callable $work  Does a page's work: given the page's product ids (list<int>), returns how many rows it wrote (int).
	 * @param callable $say   Says how far the walk came: given the page's size, the cursor and what the page wrote (ints).
	 * @return array{products: int, written: int} How many products the walk handed over, and what their pages wrote.
	 *
	 * @phpstan-param callable(list<int>): int          $work
	 * @phpstan-param callable(int, int, int): void      $say
	 */
	public function walk( int $after, int $page, callable $work, callable $say ): array {
		$page     = max( 1, $page );
		$products = 0;
		$written  = 0;

		do {
			$ids = $this->summaries->completeProductIds( $after, $page );

			if ( array() === $ids ) {
				break;
			}

			$changed = ( $this->withLock )(
				OutboxDrainer::LOCK_NAME,
				self::LOCK_TTL_SECONDS,
				self::LOCK_WAIT_SECONDS,
				static function ( Lease $lease ) use ( $work, $ids ): int {
					$changed = $work( $ids );

					// A lease lost during the page throws here, before its cursor is said.
					$lease->renew();

					return $changed;
				}
			);

			$after     = (int) end( $ids );
			$products += count( $ids );
			$written  += (int) $changed;

			$say( count( $ids ), $after, (int) $changed );

			$full = count( $ids ) === $page;
		} while ( $full );

		return array(
			'products' => $products,
			'written'  => $written,
		);
	}
}
