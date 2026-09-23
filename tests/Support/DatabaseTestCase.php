<?php
/**
 * DatabaseTestCase: the base of every test that needs real tables, real commits and a second connection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Database\Database;
use WP_UnitTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This base class creates, inspects and drops real tables around each test on purpose.

/**
 * A WordPress integration test without the harness transaction.
 *
 * Owns one fact: how a test gets a database it can commit to and still leave nothing behind.
 *
 * WP_UnitTestCase wraps every test in a transaction it rolls back, and rewrites CREATE TABLE
 * into CREATE TEMPORARY TABLE. Neither survives here: a transaction test must commit and let
 * another connection see the result, and a migration test must read information_schema, which
 * cannot see temporary tables. So start_transaction() does nothing, autocommit is switched on
 * explicitly, and cleanup is by hand:
 *
 * - every `{prefix}seocart_` table the test created is dropped in tear_down(), and so is every
 *   `{prefix}seocart_test_` table;
 * - every second connection is closed, so its locks and open transaction go with it;
 * - the object cache is flushed.
 *
 * So a test passes alone and in any order. No test here may use the WordPress factories, whose
 * rows would now be committed.
 *
 * Each test gets a Database with strict guards over the real `$wpdb`, and records instead of
 * side effects: every reported code, every pause (the sleeper is a barrier a test can hook
 * with $onSleep, never a real pause), and every random draw (which returns its upper bound).
 * The PHP error log is redirected to a file for the duration of the test, and tear_down()
 * fails the test if anything was written to it, so a database error that reached error_log()
 * cannot pass unnoticed.
 *
 * @since 0.1.0
 */
abstract class DatabaseTestCase extends WP_UnitTestCase {

	use QueryCounter;

	/**
	 * The unprefixed name of the fixture table every test gets.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	protected const ROWS = 'test_rows';

	/**
	 * The wrapper under test, over the real `$wpdb`, with strict guards.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	protected Database $db;

	/**
	 * Every code and context the wrapper reported, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{code: string, context: array<string, mixed>}>
	 */
	protected array $reports = array();

	/**
	 * Every pause asked of the sleeper, in milliseconds, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<int>
	 */
	protected array $sleeps = array();

	/**
	 * Every range asked of the random source, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{0: int, 1: int}>
	 */
	protected array $randomRanges = array();

	/**
	 * Runs when the sleeper is called, with the pause asked for: the other side of a barrier.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(int): void)|null
	 */
	protected ?\Closure $onSleep = null;

	/**
	 * The second connections this test opened.
	 *
	 * @since 0.1.0
	 *
	 * @var list<SecondConnection>
	 */
	private array $connections = array();

	/**
	 * The plugin tables that existed before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $tablesBefore = array();

	/**
	 * The file the PHP error log goes to during the test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $errorLog = '';

	/**
	 * The error_log setting before the test.
	 *
	 * @since 0.1.0
	 *
	 * @var string|false
	 */
	private string|false $previousErrorLog = false;

	/**
	 * Opens no harness transaction and installs no temporary-table filters.
	 *
	 * @since 0.1.0
	 */
	public function start_transaction(): void {
		// Deliberately empty; see the class description.
	}

	/**
	 * Prepares a committed-to database, the wrapper and the fixture table.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->reports      = array();
		$this->sleeps       = array();
		$this->randomRanges = array();
		$this->onSleep      = null;

		$this->errorLog = (string) tempnam( sys_get_temp_dir(), 'seocart-error-log-' );
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- redirected for the test and restored in tear_down().
		$this->previousErrorLog = ini_set( 'error_log', $this->errorLog );

		// Insurance: each class already gets a fresh connection, whose autocommit is on.
		$wpdb->query( 'SET autocommit = 1' );

		$this->tablesBefore = $this->pluginTables();
		$this->db           = $this->makeDatabase();

		$this->createRowsTable();
	}

	/**
	 * Drops what the test created, closes its connections, and fails if anything reached the error log.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wpdb;

		foreach ( $this->connections as $connection ) {
			$connection->close();
		}

		$this->connections = array();

		// Whatever the test left open on wpdb's connection ends here; DROP would commit it anyway.
		$wpdb->query( 'ROLLBACK' );

		foreach ( $this->pluginTables() as $table ) {
			if ( ! in_array( $table, $this->tablesBefore, true ) || str_starts_with( $table, $wpdb->prefix . 'seocart_test_' ) ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
			}
		}

		wp_cache_flush();

		$logged = (string) file_get_contents( $this->errorLog );

		// phpcs:ignore WordPress.PHP.IniSet.Risky -- restores the setting changed in set_up().
		ini_set( 'error_log', false === $this->previousErrorLog ? '' : $this->previousErrorLog );
		unlink( $this->errorLog );

		parent::tear_down();

		$this->assertSame( '', $logged, 'The test wrote to the PHP error log; a database error must reach neither output nor the log.' );
	}

	/**
	 * Builds a wrapper over the real `$wpdb` that records its reports, pauses and random draws.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $strictGuards Optional. Whether the guards throw. Default true.
	 * @param int  $maxDepth     Optional. The depth ceiling. Default 5.
	 * @return Database The wrapper.
	 */
	protected function makeDatabase( bool $strictGuards = true, int $maxDepth = 5 ): Database {
		global $wpdb;

		return new Database( $wpdb, $strictGuards, $this->reporter(), $maxDepth, $this->sleeper(), $this->randomSource() );
	}

	/**
	 * Returns a reporter that records into $reports.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(string, array<string, mixed>): void The reporter.
	 */
	protected function reporter(): \Closure {
		return function ( string $code, array $context ): void {
			$this->reports[] = array(
				'code'    => $code,
				'context' => $context,
			);
		};
	}

	/**
	 * Returns a sleeper that records into $sleeps and then runs $onSleep. It never pauses.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(int): void The sleeper.
	 */
	protected function sleeper(): \Closure {
		return function ( int $milliseconds ): void {
			$this->sleeps[] = $milliseconds;

			if ( null !== $this->onSleep ) {
				( $this->onSleep )( $milliseconds );
			}
		};
	}

	/**
	 * Returns a random source that records into $randomRanges and answers its upper bound.
	 *
	 * @since 0.1.0
	 *
	 * @return \Closure(int, int): int The random source.
	 */
	protected function randomSource(): \Closure {
		return function ( int $min, int $max ): int {
			$this->randomRanges[] = array( $min, $max );

			return $max;
		};
	}

	/**
	 * Opens connection B. It is closed in tear_down().
	 *
	 * @since 0.1.0
	 *
	 * @return SecondConnection The connection.
	 */
	protected function secondConnection(): SecondConnection {
		$connection          = new SecondConnection();
		$this->connections[] = $connection;

		return $connection;
	}

	/**
	 * Returns the full name of the fixture table.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `wptests_seocart_test_rows`.
	 */
	protected function rowsTable(): string {
		return $this->db->table( self::ROWS );
	}

	/**
	 * Inserts a fixture row through the wrapper.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id    The row id.
	 * @param string $value Optional. The value. Default empty.
	 */
	protected function insertRow( int $id, string $value = '' ): void {
		$this->db->execute( 'INSERT INTO %i ( id, value ) VALUES ( %d, %s )', $this->rowsTable(), $id, $value );
	}

	/**
	 * Counts fixture rows as connection B sees them, which is what is committed.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b     Connection B.
	 * @param string           $where Optional. A condition. Default all rows.
	 * @return int The count.
	 */
	protected function committedRows( SecondConnection $b, string $where = '1 = 1' ): int {
		return (int) $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE %s', $this->rowsTable(), $where ) );
	}

	/**
	 * Lists the tables whose name starts with `{prefix}seocart_`.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Table names.
	 */
	protected function pluginTables(): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
					$wpdb->esc_like( $wpdb->prefix . 'seocart_' ) . '%'
				)
			)
		);
	}

	/**
	 * Creates the fixture table `{prefix}seocart_test_rows` as a real InnoDB table.
	 *
	 * @since 0.1.0
	 */
	private function createRowsTable(): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"CREATE TABLE %i ( id bigint unsigned NOT NULL, value varchar(100) NOT NULL DEFAULT '', n int NOT NULL DEFAULT 0, PRIMARY KEY (id) ) ENGINE=InnoDB",
				$this->rowsTable()
			)
		);
	}
}
