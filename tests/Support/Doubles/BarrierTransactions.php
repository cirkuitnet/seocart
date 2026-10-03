<?php
/**
 * BarrierTransactions: a unit of work that runs what a test gives it just before each transaction it opens
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;

/**
 * Wraps a unit of work and runs what a test gives it just before each transaction it opens at depth 0, outside it.
 *
 * Owns one fact, for the tests of two refunds of one order at once: the moment after a refund's
 * reads and before its claim's transaction, the first one the refund opens. Another refund run
 * whole there, on a connection of its own, moves what this one was worked out from; run there, it
 * is outside this one's transaction, as a request on another connection would be. A barrier that
 * should run once says so itself. Everything else is the wrapped unit of work's.
 *
 * @since 0.1.0
 */
final class BarrierTransactions implements TransactionManager {

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $inner;

	/**
	 * What runs just before each transaction opened at depth 0; null for nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): void)|null
	 */
	private ?\Closure $before = null;

	/**
	 * Wraps a unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $inner The unit of work.
	 */
	public function __construct( TransactionManager $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Gives every later transaction opened at depth 0 something to run just before it.
	 *
	 * @since 0.1.0
	 *
	 * @param \Closure $run What runs, such as another refund.
	 *
	 * @phpstan-param \Closure(): void $run
	 */
	public function beforeTransaction( \Closure $run ): void {
		$this->before = $run;
	}

	/**
	 * Runs the barrier when the transaction is opened at depth 0, then runs the work in the wrapped unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param callable         $work      The work.
	 * @param RetryPolicy|null $retry     Optional. The retry policy. Default null.
	 * @param Isolation        $isolation Optional. The isolation level. Default Isolation::Default.
	 * @return mixed What the work returned.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null, Isolation $isolation = Isolation::Default ): mixed {
		if ( null !== $this->before && 0 === $this->inner->depth() ) {
			( $this->before )();
		}

		return $this->inner->transaction( $work, $retry, $isolation );
	}

	/**
	 * Returns the wrapped unit of work's depth.
	 *
	 * @since 0.1.0
	 *
	 * @return int The depth.
	 */
	public function depth(): int {
		return $this->inner->depth();
	}

	/**
	 * Registers a callback after the commit, on the wrapped unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $callback The callback.
	 */
	public function afterCommit( callable $callback ): void {
		$this->inner->afterCommit( $callback );
	}

	/**
	 * Registers a callback after a rollback, on the wrapped unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $callback The callback.
	 */
	public function afterRollback( callable $callback ): void {
		$this->inner->afterRollback( $callback );
	}

	/**
	 * Notes a cache key written inside the window, on the wrapped unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The key.
	 * @param string $group The group.
	 */
	public function touchCacheKey( string $key, string $group ): void {
		$this->inner->touchCacheKey( $key, $group );
	}
}
