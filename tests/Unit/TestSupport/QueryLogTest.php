<?php
/**
 * Tests the filters and the failure report of the query log
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\TestSupport;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Support\PluginOwnership;
use SEOCart\Tests\Support\QueryLog;

/**
 * Proves the query log on entries shaped like `$wpdb->queries`, with no database involved.
 *
 * @since 0.1.0
 */
final class QueryLogTest extends TestCase {

	/**
	 * Builds a log of four queries: two reads by WordPress and the plugin, two writes by the plugin.
	 *
	 * @since 0.1.0
	 *
	 * @return QueryLog The log.
	 */
	private function log(): QueryLog {
		// Entries as wpdb records them: SQL, elapsed seconds, call-stack summary, start time, extra data.
		return QueryLog::fromWpdb(
			array(
				array( "SELECT option_value\n\t\t\tFROM wp_options\n\t\t\tWHERE option_name = 'seocart_x' LIMIT 1", 0.0003, "do_action('plugins_loaded'), SEOCart\\Platform\\Kernel\\Kernel::boot, get_option", 1.5, array() ),
				array( 'SELECT * FROM wp_posts WHERE ID = 1', 0.0002, 'WP->main, WP_Query->get_posts', 1.6, array() ),
				array( 'INSERT INTO `wp_seocart_orders` (`uuid`) VALUES (1)', 0.0004, 'SEOCart\\Order\\OrderRepository->save', 1.7, array() ),
				array( '  update wp_seocart_orders_lines SET quantity = 2', 0.0004, 'SEOCart\\Order\\OrderRepository->save', 1.8, array() ),
			)
		);
	}

	/**
	 * Tests that the log counts what it was given.
	 *
	 * @since 0.1.0
	 */
	public function test_it_counts_every_recorded_query(): void {
		$this->assertCount( 4, $this->log() );
		$this->assertCount( 0, QueryLog::fromWpdb( array() ) );
	}

	/**
	 * Tests that a table filter matches the whole table name, quoted or not, and nothing longer.
	 *
	 * @since 0.1.0
	 */
	public function test_for_table_matches_a_whole_table_name(): void {
		$this->assertCount( 1, $this->log()->forTable( 'wp_seocart_orders' ), 'wp_seocart_orders_lines is another table.' );
		$this->assertCount( 1, $this->log()->forTable( 'wp_seocart_orders_lines' ) );
		$this->assertCount( 1, $this->log()->forTable( 'wp_options' ) );
		$this->assertCount( 0, $this->log()->forTable( 'wp_seocart' ) );
	}

	/**
	 * Tests that a statement-type filter reads the leading keyword, whatever its case.
	 *
	 * @since 0.1.0
	 */
	public function test_of_type_matches_the_leading_keyword(): void {
		$this->assertCount( 2, $this->log()->ofType( 'select' ) );
		$this->assertCount( 1, $this->log()->ofType( 'UPDATE' ) );
		$this->assertCount( 2, $this->log()->ofType( 'INSERT', 'UPDATE' ) );
		$this->assertCount( 0, $this->log()->ofType( 'DELETE' ) );
	}

	/**
	 * Tests that a shape filter sees the SQL on one line.
	 *
	 * @since 0.1.0
	 */
	public function test_matching_sees_the_sql_with_whitespace_collapsed(): void {
		$this->assertCount( 1, $this->log()->matching( "/^SELECT option_value FROM wp_options WHERE option_name = 'seocart_x' LIMIT 1$/" ) );
		$this->assertCount( 0, $this->log()->matching( '/TRUNCATE/' ) );
	}

	/**
	 * Tests that the ownership filter keeps the queries with plugin code in their call stack.
	 *
	 * @since 0.1.0
	 */
	public function test_issued_by_keeps_the_queries_the_plugin_caused(): void {
		$owner = new PluginOwnership( '/srv/plugins/seocart', array( 'SEOCart\\Tests\\' => 'tests/' ) );

		$this->assertCount( 3, $this->log()->issuedBy( $owner ) );
	}

	/**
	 * Tests that filters combine, and that filtering leaves the original log untouched.
	 *
	 * @since 0.1.0
	 */
	public function test_filters_chain_and_never_change_the_log_they_are_called_on(): void {
		$log = $this->log();

		$this->assertCount( 1, $log->forTable( 'wp_seocart_orders' )->ofType( 'INSERT' ) );
		$this->assertCount( 0, $log->forTable( 'wp_seocart_orders' )->ofType( 'SELECT' ) );
		$this->assertCount( 4, $log );
	}

	/**
	 * Tests that the report numbers each query and prints it with the stack that issued it.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_prints_each_query_with_its_caller(): void {
		$this->assertSame(
			"  1. INSERT INTO `wp_seocart_orders` (`uuid`) VALUES (1)\n"
			. "     caller: SEOCart\\Order\\OrderRepository->save\n"
			. "  2. update wp_seocart_orders_lines SET quantity = 2\n"
			. '     caller: SEOCart\\Order\\OrderRepository->save',
			$this->log()->ofType( 'INSERT', 'UPDATE' )->describe()
		);
	}

	/**
	 * Tests the report of an empty log and of an entry that lacks a call stack.
	 *
	 * @since 0.1.0
	 */
	public function test_describe_copes_with_an_empty_log_and_a_missing_caller(): void {
		$this->assertSame( '  (no queries)', QueryLog::fromWpdb( array() )->describe() );

		$this->assertSame(
			"  1. SELECT 1\n     caller: (not recorded)",
			QueryLog::fromWpdb( array( array( 'SELECT 1' ) ) )->describe()
		);
	}
}
