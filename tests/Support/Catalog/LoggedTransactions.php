<?php
/**
 * LoggedTransactions: a fake transaction manager that writes each outermost transaction to a step log
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Catalog;

use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;

/**
 * Runs transactions with FakeTransactionManager and records when each outermost one begins, with its retry policy, and how it ends.
 *
 * Owns one fact: how a product save's transactions appear in the step log. A transaction begins
 * as `transaction: N attempts`, N being what its retry policy allows, and ends as `commit` or
 * `rollback`. Inner levels are not recorded.
 *
 * @since 0.1.0
 */
final class LoggedTransactions implements TransactionManager {

	/**
	 * The transaction manager that runs the work.
	 *
	 * @since 0.1.0
	 *
	 * @var FakeTransactionManager
	 */
	private FakeTransactionManager $inner;

	/**
	 * The step log.
	 *
	 * @since 0.1.0
	 *
	 * @var StepLog
	 */
	private StepLog $log;

	/**
	 * Wraps a fake transaction manager.
	 *
	 * @since 0.1.0
	 *
	 * @param FakeTransactionManager $inner Runs the work.
	 * @param StepLog                $log   The step log.
	 */
	public function __construct( FakeTransactionManager $inner, StepLog $log ) {
		$this->inner = $inner;
		$this->log   = $log;
	}

	/**
	 * Runs the work, recording an outermost transaction's policy and its end.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable Whatever the work threw, after the rollback is recorded.
	 *
	 * @param callable         $work  The unit of work.
	 * @param RetryPolicy|null $retry Optional. The retry policy. Default null, which never retries.
	 * @return mixed What the work returned.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed {
		if ( 0 !== $this->inner->depth() ) {
			return $this->inner->transaction( $work, $retry );
		}

		$this->log->record( sprintf( 'transaction: %d attempts', null === $retry ? 1 : $retry->attempts() ) );

		try {
			$result = $this->inner->transaction( $work, $retry );
		} catch ( \Throwable $failure ) {
			$this->log->record( 'rollback' );

			throw $failure;
		}

		$this->log->record( 'commit' );

		return $result;
	}

	/**
	 * Returns how many levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int The depth.
	 */
	public function depth(): int {
		return $this->inner->depth();
	}

	/**
	 * Registers a callback for after the commit.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $callback The callback.
	 */
	public function afterCommit( callable $callback ): void {
		$this->inner->afterCommit( $callback );
	}

	/**
	 * Registers a callback for after a rollback.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $callback The callback.
	 */
	public function afterRollback( callable $callback ): void {
		$this->inner->afterRollback( $callback );
	}

	/**
	 * Records a cache key the transaction touched.
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
