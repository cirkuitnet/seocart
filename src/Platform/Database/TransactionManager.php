<?php
/**
 * TransactionManager: the port through which application services open a unit of work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a unit of work atomically, and says what happens after it commits or rolls back.
 *
 * Owns one fact: the contract of a unit of work as application code sees it. Nesting is
 * allowed and is atomic per level: an inner level that throws is undone on its own and its
 * exception keeps propagating, so an inner failure never becomes an outer success. Only the
 * outermost level commits, and only the outermost level may retry.
 *
 * This interface calls no WordPress function. Application services type-hint it; unit tests
 * use tests/Support/Doubles/FakeTransactionManager. Database is the implementation.
 *
 * @since 0.1.0
 */
interface TransactionManager {

	/**
	 * Runs a unit of work inside a transaction.
	 *
	 * At the outermost level a retry policy re-runs the whole callable after a deadlock or a
	 * lock-wait timeout, once the rollback is complete. At any inner level the policy is
	 * ignored and the failure propagates, so the outermost level decides.
	 *
	 * @since 0.1.0
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param callable(): mixed $work  The unit of work. It must not catch a failure of the
	 *                                 transaction it runs in and carry on.
	 * @param RetryPolicy|null  $retry Optional. How often to re-run after a deadlock or a
	 *                                 lock-wait timeout. Default null, which never retries.
	 * @return mixed What the callable returned, unchanged.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed;

	/**
	 * Returns how many transaction levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 outside any transaction, 1 inside the outermost level, and so on.
	 */
	public function depth(): int;

	/**
	 * Registers work to run once the outermost level has committed.
	 *
	 * Callbacks registered in a level that rolls back never run. Outside any transaction the
	 * callback runs at once.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after COMMIT.
	 */
	public function afterCommit( callable $callback ): void;

	/**
	 * Registers work to run once the level it was registered in has been rolled back.
	 *
	 * An inner level runs its callbacks right after its own rollback. A level that succeeds
	 * hands its callbacks to the level around it, so they run if that level rolls back later.
	 * Outside any transaction there is nothing to roll back, and the callback is ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work to run after the rollback.
	 */
	public function afterRollback( callable $callback ): void;

	/**
	 * Records an object-cache key written inside the current level, so a rollback can remove it.
	 *
	 * A persistent object cache is shared by every web node and does not take part in the
	 * transaction, so a value written inside a window that rolls back would outlive the data
	 * it describes. Call this before wp_cache_set(). Outside any transaction it does nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The cache key.
	 * @param string $group The cache group.
	 */
	public function touchCacheKey( string $key, string $group ): void;
}
