<?php
/**
 * Tests ChangedMeanwhile: which failures a unit of work answers as its own refusal, and where
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\ChangedMeanwhile;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\MysqlErrno;
use SEOCart\Tests\Support\Doubles\FakeTransactionManager;

/**
 * Pins that only error 1020, and only at the outermost level, becomes the caller's refusal, after one run.
 *
 * Planted violation: in ChangedMeanwhile::refusedAs(), drop the depth check (restate inside a
 * caller's transaction too): the savepoint test fails. Or drop the errno check: the deadlock test fails.
 *
 * @since 0.2.0
 */
final class ChangedMeanwhileTest extends TestCase {

	/**
	 * Tests that the work's result comes back, from one run.
	 *
	 * @since 0.2.0
	 */
	public function test_the_work_runs_once_and_its_result_comes_back(): void {
		$tx = new FakeTransactionManager();

		$this->assertSame( 'done', ChangedMeanwhile::refusedAs( $tx, static fn(): string => 'done', self::refusal() ) );
		$this->assertSame( 1, $tx->attempts() );
	}

	/**
	 * Tests that a row changed since the snapshot, at the outermost level, leaves as the refusal, the server's failure inside it, after one run.
	 *
	 * @since 0.2.0
	 */
	public function test_a_changed_row_at_the_outermost_level_becomes_the_refusal(): void {
		$tx      = new FakeTransactionManager();
		$changed = self::failure( MysqlErrno::RECORD_CHANGED );

		try {
			ChangedMeanwhile::refusedAs(
				$tx,
				static function () use ( $changed ): never {
					throw $changed;
				},
				self::refusal()
			);
			$this->fail( 'The changed row was not answered as the refusal.' );
		} catch ( \DomainException $refused ) {
			$this->assertSame( 'another writer won', $refused->getMessage() );
			$this->assertSame( $changed, $refused->getPrevious() );
		}

		$this->assertSame( 1, $tx->attempts(), 'The unit of work ran again.' );
		$this->assertSame( 0, $tx->depth() );
	}

	/**
	 * Tests that a deadlock and a lock-wait timeout leave unchanged: they are no lost race.
	 *
	 * @since 0.2.0
	 */
	public function test_a_deadlock_or_a_lock_wait_timeout_is_never_restated(): void {
		foreach ( array( MysqlErrno::DEADLOCK, MysqlErrno::LOCK_WAIT_TIMEOUT ) as $errno ) {
			$failure = self::failure( $errno );

			try {
				ChangedMeanwhile::refusedAs(
					new FakeTransactionManager(),
					static function () use ( $failure ): never {
						throw $failure;
					},
					self::refusal()
				);
				$this->fail( sprintf( 'Error %d left without its failure.', $errno ) );
			} catch ( TransactionRetryable $left ) {
				$this->assertSame( $failure, $left, sprintf( 'Error %d was restated.', $errno ) );
			}
		}
	}

	/**
	 * Tests that inside a caller's transaction the failure propagates unchanged, for the outermost level to decide.
	 *
	 * @since 0.2.0
	 */
	public function test_inside_a_callers_transaction_the_failure_propagates_unchanged(): void {
		$tx      = new FakeTransactionManager();
		$changed = self::failure( MysqlErrno::RECORD_CHANGED );

		try {
			$tx->transaction(
				static fn(): mixed => ChangedMeanwhile::refusedAs(
					$tx,
					static function () use ( $changed ): never {
						throw $changed;
					},
					self::refusal()
				)
			);
			$this->fail( 'The failure did not reach the caller\'s transaction.' );
		} catch ( TransactionRetryable $left ) {
			$this->assertSame( $changed, $left, 'A savepoint restated what only the outermost level may answer.' );
		}
	}

	/**
	 * Returns the refusal a caller builds: its own answer to a lost race.
	 *
	 * @since 0.2.0
	 *
	 * @return \Closure(TransactionRetryable): \Throwable The builder.
	 */
	private static function refusal(): \Closure {
		return static fn( TransactionRetryable $changed ): \Throwable => new \DomainException( 'another writer won', 0, $changed );
	}

	/**
	 * Builds the failure Database throws for an error number, inside a transaction.
	 *
	 * @since 0.2.0
	 *
	 * @param int $errno The server's error number.
	 * @return TransactionRetryable The failure.
	 */
	private static function failure( int $errno ): TransactionRetryable {
		$failure = QueryFailed::fromErrno( $errno, 'HY000', "UPDATE wp_options SET option_value = 'x' WHERE option_name = 'y'", 'server text', true );

		self::assertInstanceOf( TransactionRetryable::class, $failure );

		return $failure;
	}
}
