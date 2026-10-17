<?php
/**
 * ChangedMeanwhile: a unit of work that answers a row changed since its snapshot with its own refusal
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\TransactionRetryable;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a unit of work once, and answers MariaDB's refusal of a row changed since its snapshot as the unit's own refusal.
 *
 * Owns one fact: where a compare-and-swap's lost race is answered alike on both servers. A unit
 * that reads a row and then writes it only if it is still as read loses to another writer in two
 * ways: MySQL compares with the newest row, the write matches nothing, and the unit refuses with
 * its own code; MariaDB refuses the write of a row committed after the unit's snapshot (error
 * 1020) and rolls the whole transaction back. Both mean "another writer won, nothing was
 * written". Here the second is answered as the first, by the call that opened the transaction,
 * once the server has rolled it back: nothing of the unit continues.
 *
 * Inside a caller's transaction the work runs as a savepoint and the failure propagates
 * unchanged: only the outermost level may decide what a rolled-back unit of work answers. A
 * deadlock or a lock-wait timeout is never restated.
 *
 * @since 0.2.0
 */
final class ChangedMeanwhile {

	/**
	 * Runs the work once in a transaction; at the outermost level, error 1020 leaves as the refusal the caller builds.
	 *
	 * @since 0.2.0
	 *
	 * @throws TransactionRetryable What ended the transaction, when it was not opened here or is not error 1020.
	 * @phpstan-throws \Throwable
	 *
	 * @param-immediately-invoked-callable $work
	 *
	 * @param TransactionManager $tx      The unit of work's manager.
	 * @param callable(): mixed  $work    The unit of work. RetryPolicy::none(): it is never run again.
	 * @param \Closure           $refusal Builds the refusal from the server's: the unit's own answer to a lost race.
	 * @return mixed What the work returned.
	 *
	 * @phpstan-param \Closure(TransactionRetryable): \Throwable $refusal
	 */
	public static function refusedAs( TransactionManager $tx, callable $work, \Closure $refusal ): mixed {
		if ( 0 !== $tx->depth() ) {
			return $tx->transaction( $work, RetryPolicy::none() );
		}

		try {
			return $tx->transaction( $work, RetryPolicy::none() );
		} catch ( TransactionRetryable $refused ) {
			if ( MysqlErrno::RECORD_CHANGED !== $refused->errno() ) {
				throw $refused;
			}

			$answer = $refusal( $refused );

			throw $answer;
		}
	}
}
