<?php
/**
 * FakeTransactionManager: a TransactionManager that keeps the contract and never touches SQL
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;

/**
 * The unit-test stand-in for Database, as application services see it.
 *
 * Owns one fact: the TransactionManager contract without a database. It runs the callable,
 * counts depth, keeps after-commit and after-rollback callbacks per level with the same rules
 * as Database (a rolled-back level's after-commit callbacks never run; its after-rollback
 * callbacks run at its rollback; a level that succeeds hands both to the level around it),
 * re-runs the outermost level on TransactionRetryable as the policy allows without pausing,
 * and records what a test may want to assert: how many units of work began, how many
 * attempts ran, and which cache keys were touched.
 *
 * @since 0.1.0
 */
final class FakeTransactionManager implements TransactionManager {

	/**
	 * How many levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Per open level, from 1: its after-commit and after-rollback callbacks.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array{commit: list<callable(): mixed>, rollback: list<callable(): mixed>}>
	 */
	private array $levels = array();

	/**
	 * How many outermost attempts ran, retries included.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $attempts = 0;

	/**
	 * How many outermost units of work committed.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $commits = 0;

	/**
	 * How many outermost units of work rolled back.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private int $rollbacks = 0;

	/**
	 * Every cache key touched inside a transaction, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{key: string, group: string}>
	 */
	private array $touched = array();

	/**
	 * Runs the work as a unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionRetryable When the last attempt the policy allows still failed that way.
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param callable(): mixed $work  The unit of work.
	 * @param RetryPolicy|null  $retry Optional. Honoured at the outermost level only. Default null.
	 * @return mixed What the callable returned.
	 */
	public function transaction( callable $work, ?RetryPolicy $retry = null ): mixed {
		if ( 0 !== $this->depth ) {
			return $this->level( $work );
		}

		$attempts = null === $retry ? 1 : $retry->attempts();

		for ( $attempt = 1; true; ++$attempt ) {
			++$this->attempts;

			try {
				$result = $this->level( $work );
			} catch ( TransactionRetryable $retryable ) {
				if ( $attempt >= $attempts ) {
					throw $retryable;
				}

				continue;
			}

			return $result;
		}
	}

	/**
	 * Returns how many levels are open.
	 *
	 * @since 0.1.0
	 *
	 * @return int 0 outside any transaction.
	 */
	public function depth(): int {
		return $this->depth;
	}

	/**
	 * Registers after-commit work, or runs it at once outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work.
	 */
	public function afterCommit( callable $callback ): void {
		if ( 0 === $this->depth ) {
			$callback();

			return;
		}

		$this->levels[ $this->depth ]['commit'][] = $callback;
	}

	/**
	 * Registers after-rollback work. Ignored outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $callback The work.
	 */
	public function afterRollback( callable $callback ): void {
		if ( 0 !== $this->depth ) {
			$this->levels[ $this->depth ]['rollback'][] = $callback;
		}
	}

	/**
	 * Records a touched cache key. Ignored outside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The key.
	 * @param string $group The group.
	 */
	public function touchCacheKey( string $key, string $group ): void {
		if ( 0 !== $this->depth ) {
			$this->touched[] = array(
				'key'   => $key,
				'group' => $group,
			);
		}
	}

	/**
	 * Returns how many outermost attempts ran, retries included.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function attempts(): int {
		return $this->attempts;
	}

	/**
	 * Returns how many outermost units of work committed.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function commits(): int {
		return $this->commits;
	}

	/**
	 * Returns how many outermost units of work rolled back.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count.
	 */
	public function rollbacks(): int {
		return $this->rollbacks;
	}

	/**
	 * Returns every cache key touched inside a transaction.
	 *
	 * @since 0.1.0
	 *
	 * @return list<array{key: string, group: string}> In order.
	 */
	public function touchedKeys(): array {
		return $this->touched;
	}

	/**
	 * Runs one level.
	 *
	 * @since 0.1.0
	 *
	 * @throws \Throwable Whatever the work threw, after the level's after-rollback callbacks ran.
	 *
	 * @param callable(): mixed $work The work.
	 * @return mixed What it returned.
	 */
	private function level( callable $work ): mixed {
		$index = $this->depth;

		$this->depth                  = $index + 1;
		$this->levels[ $this->depth ] = array(
			'commit'   => array(),
			'rollback' => array(),
		);

		try {
			$result = $work();
		} catch ( \Throwable $failure ) {
			$level = $this->close( $index );

			if ( 0 === $index ) {
				++$this->rollbacks;
			}

			foreach ( $level['rollback'] as $callback ) {
				$callback();
			}

			throw $failure;
		}

		$level = $this->close( $index );

		if ( 0 === $index ) {
			++$this->commits;

			foreach ( $level['commit'] as $callback ) {
				$callback();
			}

			return $result;
		}

		$this->levels[ $index ]['commit']   = array_merge( $this->levels[ $index ]['commit'], $level['commit'] );
		$this->levels[ $index ]['rollback'] = array_merge( $this->levels[ $index ]['rollback'], $level['rollback'] );

		return $result;
	}

	/**
	 * Closes the level above the given depth.
	 *
	 * @since 0.1.0
	 *
	 * @param int $index The depth to return to.
	 * @return array{commit: list<callable(): mixed>, rollback: list<callable(): mixed>} The closed level.
	 */
	private function close( int $index ): array {
		$level = $this->levels[ $index + 1 ];

		unset( $this->levels[ $index + 1 ] );

		$this->depth = $index;

		return $level;
	}
}
