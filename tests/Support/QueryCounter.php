<?php
/**
 * QueryCounter: counts the database queries a piece of work issues, and asserts a budget on them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The query-count half of the measurement harness.
 *
 * Capture the queries around a closure, narrow them with the QueryLog filters, then assert an
 * exact count or a ceiling. A failure prints every counted query with the call stack that
 * issued it, so a broken budget names its own culprit:
 *
 *     $log = $this->captureQueries( fn() => $repository->find( $id ) );
 *     $this->assertQueryCount( 1, $log->forTable( $wpdb->prefix . 'seocart_orders' ), 'Order lookup' );
 *
 * @since 0.1.0
 */
trait QueryCounter {

	/**
	 * Runs a piece of work and returns the queries it issued.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): mixed $work The work to measure.
	 * @return QueryLog The queries `$wpdb` recorded while the work ran, in order.
	 */
	protected function captureQueries( callable $work ): QueryLog {
		global $wpdb;

		self::requireSavedQueries();

		/*
		 * wpdb declares `queries` without a default value, so the log is null, not an empty
		 * array, until the first query is recorded. That is the state of a suite whose bootstrap
		 * left SAVEQUERIES off and had it turned on a moment ago by the line above. The cast
		 * reads null as an empty log.
		 */
		$logged_before = count( (array) $wpdb->queries );
		$issued_before = $wpdb->num_queries;

		$work();

		$logged = array_slice( (array) $wpdb->queries, $logged_before );
		$issued = $wpdb->num_queries - $issued_before;

		/*
		 * wpdb counts every query it runs in `num_queries`, whether or not it logs them. If the
		 * log did not grow by the same number, it was emptied or replaced during the work, and
		 * a budget asserted on it would pass for the wrong reason.
		 */
		if ( count( $logged ) !== $issued ) {
			Assert::fail(
				sprintf(
					'wpdb ran %d queries during the measured work, but its query log gained %d entries, so a count taken from the log would be wrong. Do not reset $wpdb->queries inside measured work.',
					$issued,
					count( $logged )
				)
			);
		}

		return QueryLog::fromWpdb( $logged );
	}

	/**
	 * Asserts that exactly a number of queries were counted.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $expected The exact number of queries allowed.
	 * @param QueryLog $log      The queries to count, already narrowed to the ones the budget covers.
	 * @param string   $budget   Optional. What is being budgeted, for the failure message. Default empty.
	 */
	protected function assertQueryCount( int $expected, QueryLog $log, string $budget = '' ): void {
		Assert::assertSame(
			$expected,
			count( $log ),
			self::describeBudgetFailure( sprintf( 'expected exactly %d, counted %d', $expected, count( $log ) ), $log, $budget )
		);
	}

	/**
	 * Asserts that no more than a number of queries were counted.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $maximum The largest number of queries allowed.
	 * @param QueryLog $log     The queries to count, already narrowed to the ones the budget covers.
	 * @param string   $budget  Optional. What is being budgeted, for the failure message. Default empty.
	 */
	protected function assertQueryCountAtMost( int $maximum, QueryLog $log, string $budget = '' ): void {
		Assert::assertLessThanOrEqual(
			$maximum,
			count( $log ),
			self::describeBudgetFailure( sprintf( 'expected at most %d, counted %d', $maximum, count( $log ) ), $log, $budget )
		);
	}

	/**
	 * Makes sure wpdb records its queries.
	 *
	 * The integration bootstrap turns SAVEQUERIES on before WordPress loads. wpdb reads the
	 * constant on every query, so defining it here still works for a suite that did not.
	 *
	 * @since 0.1.0
	 */
	private static function requireSavedQueries(): void {
		if ( ! defined( 'SAVEQUERIES' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is WordPress's own constant.
			define( 'SAVEQUERIES', true );

			return;
		}

		// @phpstan-ignore booleanNot.alwaysFalse (PHPStan reads the definition above; a test configuration may have defined the constant as false first.)
		if ( ! SAVEQUERIES ) {
			Assert::fail( 'SAVEQUERIES is defined as false, so wpdb records no queries and nothing can be counted. Remove that definition from the test configuration.' );
		}
	}

	/**
	 * Builds the failure message: the verdict, then every counted query with its caller.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $verdict What was expected and what was counted.
	 * @param QueryLog $log     The counted queries.
	 * @param string   $budget  What is being budgeted. May be empty.
	 * @return string The message.
	 */
	private static function describeBudgetFailure( string $verdict, QueryLog $log, string $budget ): string {
		return sprintf(
			"%s: %s. The counted queries, each with the call stack that issued it:\n%s\n",
			'' === $budget ? 'Query budget' : $budget,
			$verdict,
			$log->describe()
		);
	}
}
