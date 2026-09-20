<?php
/**
 * Tests the query-counting trait against a stand-in for wpdb
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\QueryCounter;
use SEOCart\Tests\Support\QueryLog;

/**
 * Proves the capture and the two assertions without WordPress or a database.
 *
 * The trait reads two things from WordPress: the public `queries` array and the public
 * `num_queries` counter of the `$wpdb` global. An object with those two properties stands in
 * for it here.
 *
 * @since 0.1.0
 */
final class QueryCounterTest extends TestCase {

	use QueryCounter;

	/**
	 * Installs the stand-in for wpdb, with one query already in its log.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded in a unit test; the stand-in is the only wpdb there is.
		$GLOBALS['wpdb'] = new class() {

			/**
			 * The query log, declared the way wpdb declares it: no type and no default value, so
			 * it is null until the first query is recorded.
			 *
			 * @since 0.1.0
			 *
			 * @var array<int, array<int, mixed>>|null
			 */
			public $queries;

			/**
			 * How many queries have run, which wpdb counts whether or not it logs them.
			 *
			 * @since 0.1.0
			 *
			 * @var int
			 */
			public int $num_queries = 0;
		};

		$this->issue( 'SELECT 0 /* issued before the measurement */' );
	}

	/**
	 * Removes the stand-in.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );

		parent::tearDown();
	}

	/**
	 * Appends a query to the stand-in's log, the way wpdb does when SAVEQUERIES is on.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql    The SQL.
	 * @param string $caller Optional. The call-stack summary. Default 'WP_Query->get_posts'.
	 */
	private function issue( string $sql, string $caller = 'WP_Query->get_posts' ): void {
		$GLOBALS['wpdb']->queries[] = array( $sql, 0.0001, $caller, 1.0, array() );

		++$GLOBALS['wpdb']->num_queries;
	}

	/**
	 * Runs an assertion that is expected to fail, and returns its failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): void $assertion The assertion to run.
	 * @return string The failure message.
	 */
	private function failureMessageOf( callable $assertion ): string {
		try {
			$assertion();
		} catch ( AssertionFailedError $failure ) {
			return $failure->getMessage();
		}

		$this->fail( 'The assertion passed, but it was expected to fail.' );
	}

	/**
	 * Tests that the capture holds the queries issued during the work, and only those.
	 *
	 * @since 0.1.0
	 */
	public function test_capture_returns_only_the_queries_the_work_issued(): void {
		$log = $this->captureQueries(
			function (): void {
				$this->issue( 'SELECT 1' );
				$this->issue( 'SELECT 2' );
			}
		);

		$this->issue( 'SELECT 3 /* issued after the measurement */' );

		$this->assertSame( "  1. SELECT 1\n     caller: WP_Query->get_posts\n  2. SELECT 2\n     caller: WP_Query->get_posts", $log->describe() );
	}

	/**
	 * Tests the capture on a wpdb that has recorded nothing yet, whose log is null rather than empty.
	 *
	 * @since 0.1.0
	 */
	public function test_capture_reads_a_log_that_is_still_null_as_empty(): void {
		$GLOBALS['wpdb']->queries     = null;
		$GLOBALS['wpdb']->num_queries = 0;

		$this->assertCount( 0, $this->captureQueries( static function (): void {} ), 'Work that issues nothing, on a log that is still null.' );
		$this->assertNull( $GLOBALS['wpdb']->queries, 'The capture must not write to the log it measures.' );

		$log = $this->captureQueries(
			function (): void {
				$this->issue( 'SELECT 1 /* the first query wpdb ever recorded */' );
			}
		);

		$this->assertSame( "  1. SELECT 1 /* the first query wpdb ever recorded */\n     caller: WP_Query->get_posts", $log->describe() );
	}

	/**
	 * Tests that capturing turns query recording on for a suite whose bootstrap did not.
	 *
	 * @since 0.1.0
	 */
	public function test_capture_turns_saved_queries_on(): void {
		$this->captureQueries( static function (): void {} );

		// Read at run time: static analysis already knows the value the trait defines.
		$this->assertTrue( get_defined_constants()['SAVEQUERIES'] ?? null );
	}

	/**
	 * Tests that emptying the log during the work fails the test instead of producing a small count.
	 *
	 * @since 0.1.0
	 */
	public function test_capture_fails_when_the_log_is_emptied_during_the_work(): void {
		$message = $this->failureMessageOf(
			function (): void {
				$this->captureQueries(
					function (): void {
						$GLOBALS['wpdb']->queries = array();

						$this->issue( 'SELECT 1' );
					}
				);
			}
		);

		$this->assertStringContainsString( 'wpdb ran 1 queries during the measured work, but its query log gained 0 entries', $message );
	}

	/**
	 * Tests that the exact assertion passes on the exact count and fails on either side of it.
	 *
	 * @since 0.1.0
	 */
	public function test_exact_count_passes_only_on_the_exact_count(): void {
		$two = QueryLog::fromWpdb( array( array( 'SELECT 1' ), array( 'SELECT 2' ) ) );

		$this->assertQueryCount( 2, $two );

		$this->assertStringContainsString( 'expected exactly 1, counted 2', $this->failureMessageOf( fn() => $this->assertQueryCount( 1, $two ) ) );
		$this->assertStringContainsString( 'expected exactly 3, counted 2', $this->failureMessageOf( fn() => $this->assertQueryCount( 3, $two ) ) );
	}

	/**
	 * Tests that the ceiling assertion passes up to the ceiling and fails above it.
	 *
	 * @since 0.1.0
	 */
	public function test_ceiling_passes_up_to_the_ceiling_and_fails_above_it(): void {
		$two = QueryLog::fromWpdb( array( array( 'SELECT 1' ), array( 'SELECT 2' ) ) );

		$this->assertQueryCountAtMost( 3, $two );
		$this->assertQueryCountAtMost( 2, $two );

		$this->assertStringContainsString( 'expected at most 1, counted 2', $this->failureMessageOf( fn() => $this->assertQueryCountAtMost( 1, $two ) ) );
	}

	/**
	 * Tests that a failure names the budget and prints every counted query with its call stack.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failure_names_the_budget_and_prints_the_offending_queries_with_their_callers(): void {
		$log = $this->captureQueries(
			function (): void {
				$this->issue( "SELECT option_value FROM wp_options WHERE option_name = 'seocart_planted' LIMIT 1", 'SEOCart\\Platform\\Kernel\\Kernel::boot, get_option' );
			}
		);

		$message = $this->failureMessageOf( fn() => $this->assertQueryCount( 0, $log, 'G1, idle request' ) );

		$this->assertStringContainsString( 'G1, idle request: expected exactly 0, counted 1.', $message );
		$this->assertStringContainsString( "1. SELECT option_value FROM wp_options WHERE option_name = 'seocart_planted' LIMIT 1", $message );
		$this->assertStringContainsString( 'caller: SEOCart\\Platform\\Kernel\\Kernel::boot, get_option', $message );
	}
}
