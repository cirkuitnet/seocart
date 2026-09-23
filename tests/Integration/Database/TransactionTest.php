<?php
/**
 * Tests the transaction wrapper against a real MySQL server and a second connection
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionDepthExceeded;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionGuards;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL -- These tests drive the connection directly to play the parts of third parties and failures.

/**
 * What the transaction wrapper commits, refuses and
 * reports, observed from a second connection, which sees only what is committed.
 *
 * Each test names its planted violation, a change to src/Platform/Database/Database.php or
 * TransactionGuards.php unless it says otherwise. Revert the plant afterwards.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class TransactionTest extends DatabaseTestCase {

	/**
	 * The object-cache group the cache tests write to.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'seocart_test';

	/**
	 * Tests the statement methods outside a transaction: rows affected, rows read, the last insert id.
	 *
	 * @since 0.1.0
	 */
	public function test_the_statement_methods_read_and_write(): void {
		$this->insertRow( 1, 'one' );
		$this->insertRow( 2, 'two' );

		$this->assertSame( 2, $this->db->execute( 'UPDATE %i SET n = n + 1', $this->rowsTable() ) );
		$this->assertSame(
			array(
				'id'    => '1',
				'value' => 'one',
				'n'     => '1',
			),
			$this->db->fetchRow( 'SELECT id, value, n FROM %i WHERE id = %d', $this->rowsTable(), 1 )
		);
		$this->assertNull( $this->db->fetchRow( 'SELECT id FROM %i WHERE id = %d', $this->rowsTable(), 3 ) );
		$this->assertSame( array( array( 'value' => 'one' ), array( 'value' => 'two' ) ), $this->db->fetchAll( 'SELECT value FROM %i ORDER BY id', $this->rowsTable() ) );
		$this->assertSame( '2', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->rowsTable() ) );
		$this->assertSame( 0, $this->db->depth() );
		$this->assertSame( $this->db->prefix() . 'seocart_' . self::ROWS, $this->rowsTable() );
		$this->assertSame( 'wpdb', $this->db->connectionReport()['wpdb_class'] );
		$this->assertSame( $this->db->threadId(), $this->db->connectionReport()['thread_id'] );
	}

	/**
	 * A row inserted inside transaction() is visible to another connection after it returns.
	 *
	 * Planted violation: in unitOfWork(), send 'ROLLBACK' where 'COMMIT' is sent.
	 *
	 * @since 0.1.0
	 */
	public function test_a_committed_row_is_visible_to_another_connection(): void {
		$b = $this->secondConnection();

		$this->db->transaction( fn() => $this->insertRow( 1, 'committed' ) );

		$this->assertSame( 1, $this->committedRows( $b, 'id = 1' ), 'Connection B must see the row once transaction() returned.' );
	}

	/**
	 * A depth-0 transaction costs exactly four statements and no SELECT.
	 *
	 * Planted violation: in assertCommittable(), send 'SELECT CONNECTION_ID()' before the probe.
	 *
	 * @since 0.1.0
	 */
	public function test_a_transaction_costs_four_statements(): void {
		$log = $this->captureQueries( fn() => $this->db->transaction( static fn() => null ) );

		$this->assertQueryCount( 4, $log, 'A depth-0 transaction' );
		$this->assertMatchesRegularExpression(
			'/1\. START TRANSACTION\n.*\n  2\. SAVEPOINT sc_0\n.*\n  3\. RELEASE SAVEPOINT sc_0\n.*\n  4\. COMMIT\n/',
			$log->describe() . "\n",
			'BEGIN, the probe savepoint, the probe, COMMIT: in that order.'
		);
	}

	/**
	 * An inner level that throws is undone on its own; the outer level catches, goes on and commits.
	 *
	 * Planted violation: in savepoint(), do not send ROLLBACK TO SAVEPOINT when the work throws.
	 *
	 * @since 0.1.0
	 */
	public function test_an_inner_failure_caught_by_the_outer_level_undoes_only_the_inner_level(): void {
		$b = $this->secondConnection();

		$this->db->transaction(
			function (): void {
				$this->insertRow( 1, 'outer' );

				try {
					$this->db->transaction(
						function (): void {
							$this->insertRow( 2, 'inner' );

							throw new \DomainException( 'inner failed' );
						}
					);
				} catch ( \DomainException $expected ) {
					$this->assertSame( 1, $this->db->depth() );
				}
			}
		);

		$this->assertSame( 1, $this->committedRows( $b, 'id = 1' ), 'The outer row was committed.' );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 2' ), 'The inner row was rolled back to its savepoint.' );
	}

	/**
	 * An inner failure that the outer level does not catch undoes everything and reaches the caller.
	 *
	 * Planted violation: in unitOfWork(), return null instead of rethrowing after abandon().
	 *
	 * @since 0.1.0
	 */
	public function test_an_inner_failure_the_outer_level_does_not_catch_undoes_both_and_propagates(): void {
		$b      = $this->secondConnection();
		$caught = null;

		try {
			$this->db->transaction(
				function (): void {
					$this->insertRow( 1, 'outer' );

					$this->db->transaction(
						function (): void {
							$this->insertRow( 2, 'inner' );

							throw new \DomainException( 'inner failed' );
						}
					);
				}
			);
		} catch ( \DomainException $failure ) {
			$caught = $failure;
		}

		$this->assertNotNull( $caught, 'An inner failure must never become an outer success.' );
		$this->assertSame( 'inner failed', $caught->getMessage() );
		$this->assertSame( 0, $this->committedRows( $b ), 'Neither row may be committed.' );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * The callable's return value comes back unchanged, and depth() is the level inside.
	 *
	 * Planted violation: in unitOfWork(), return null instead of $result.
	 *
	 * @since 0.1.0
	 */
	public function test_the_return_value_is_unchanged_and_depth_counts_the_levels(): void {
		$depths = array();

		$result = $this->db->transaction(
			function () use ( &$depths ): array {
				$depths[] = $this->db->depth();

				return $this->db->transaction(
					function () use ( &$depths ): array {
						$depths[] = $this->db->depth();

						return array( 'value' => 42 );
					}
				);
			}
		);

		$this->assertSame( array( 'value' => 42 ), $result );
		$this->assertSame( array( 1, 2 ), $depths );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * Five levels work; a sixth is refused before any statement is sent.
	 *
	 * Planted violation: in transaction(), compare the ceiling with `>=` instead of `>`.
	 *
	 * @since 0.1.0
	 */
	public function test_the_sixth_level_is_refused_before_any_statement(): void {
		global $wpdb;

		$b       = $this->secondConnection();
		$refused = null;
		$sent    = null;

		$nest = function ( int $level ) use ( &$nest, &$refused, &$sent, $wpdb ): void {
			$this->insertRow( $level );

			if ( 5 > $level ) {
				$this->db->transaction( static fn() => $nest( $level + 1 ) );

				return;
			}

			$before = count( (array) $wpdb->queries );

			try {
				$this->db->transaction( static fn() => null );
			} catch ( TransactionDepthExceeded $exceeded ) {
				$refused = $exceeded;
			}

			$sent = count( (array) $wpdb->queries ) - $before;
		};

		$this->db->transaction( static fn() => $nest( 1 ) );

		$this->assertSame( 5, $this->committedRows( $b ), 'Five nested levels commit.' );
		$this->assertNotNull( $refused, 'The sixth level must be refused.' );
		$this->assertSame( 5, $refused->context()['max_depth'] );
		$this->assertSame( 0, $sent, 'The refusal must come before any statement is sent.' );
	}

	/**
	 * After-commit callbacks run once, after COMMIT, outside any level; never for a rolled-back level or a rollback.
	 *
	 * Planted violation: in unitOfWork(), run the after-commit callbacks just before sending COMMIT.
	 *
	 * @since 0.1.0
	 */
	public function test_after_commit_callbacks_run_after_commit_and_only_for_committed_work(): void {
		$b    = $this->secondConnection();
		$seen = array();

		$this->db->transaction(
			function () use ( $b, &$seen ): void {
				$this->insertRow( 1 );

				$this->db->afterCommit(
					function () use ( $b, &$seen ): void {
						$seen[] = array(
							'depth'   => $this->db->depth(),
							'visible' => $this->committedRows( $b, 'id = 1' ),
						);
					}
				);

				try {
					$this->db->transaction(
						function () use ( &$seen ): void {
							$this->db->afterCommit(
								static function () use ( &$seen ): void {
									$seen[] = 'the rolled-back level';
								}
							);

							throw new \DomainException( 'inner failed' );
						}
					);
				} catch ( \DomainException $expected ) {
					unset( $expected );
				}
			}
		);

		$this->assertSame(
			array(
				array(
					'depth'   => 0,
					'visible' => 1,
				),
			),
			$seen,
			'One callback, run at depth 0 once B could see the committed row; the rolled-back level\'s callback never ran.'
		);

		$ran = false;

		try {
			$this->db->transaction(
				function () use ( &$ran ): void {
					$this->db->afterCommit(
						static function () use ( &$ran ): void {
							$ran = true;
						}
					);

					throw new \DomainException( 'outer failed' );
				}
			);
		} catch ( \DomainException $expected ) {
			unset( $expected );
		}

		$this->assertFalse( $ran, 'No after-commit callback runs when the transaction rolls back.' );
	}

	/**
	 * AfterCommit() outside a transaction runs the callback at once.
	 *
	 * Planted violation: in afterCommit(), delete the depth-0 branch, so the callback is queued.
	 *
	 * @since 0.1.0
	 */
	public function test_after_commit_outside_a_transaction_runs_at_once(): void {
		$runs = 0;

		$this->db->afterCommit(
			static function () use ( &$runs ): void {
				++$runs;
			}
		);

		$this->assertSame( 1, $runs );
	}

	/**
	 * Cache keys touched in a rolled-back level are removed; an inner rollback removes only its own.
	 *
	 * Planted violation: make flush() do nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_cache_keys_touched_in_a_rolled_back_level_are_removed(): void {
		$this->db->transaction(
			function (): void {
				$this->touchAndSet( 'outer', 'kept' );

				try {
					$this->db->transaction(
						function (): void {
							$this->touchAndSet( 'inner', 'undone' );

							throw new \DomainException( 'inner failed' );
						}
					);
				} catch ( \DomainException $expected ) {
					$this->assertFalse( wp_cache_get( 'inner', self::CACHE_GROUP ), 'The inner level\'s key is removed at its own rollback.' );
					$this->assertSame( 'kept', wp_cache_get( 'outer', self::CACHE_GROUP ), 'The outer level\'s key is untouched by the inner rollback.' );
				}
			}
		);

		$this->assertSame( 'kept', wp_cache_get( 'outer', self::CACHE_GROUP ), 'A committed level\'s key survives.' );

		try {
			$this->db->transaction(
				function (): void {
					$this->touchAndSet( 'rolled_back', 'undone' );

					throw new \DomainException( 'outer failed' );
				}
			);
		} catch ( \DomainException $expected ) {
			unset( $expected );
		}

		$this->assertFalse( wp_cache_get( 'rolled_back', self::CACHE_GROUP ), 'A rolled-back transaction\'s keys are removed.' );
	}

	/**
	 * After wpdb reconnects inside the window, COMMIT is refused because the connection changed.
	 *
	 * Planted violation: make assertSameConnection() return at once. The probe still refuses,
	 * but with the reason `ended_externally`, so the assertion on the reason proves the identity
	 * check specifically.
	 *
	 * @since 0.1.0
	 */
	public function test_a_reconnect_inside_the_window_refuses_the_commit(): void {
		global $wpdb;

		$b             = $this->secondConnection();
		$afterRollback = false;
		$afterCommit   = false;
		$lost          = null;

		try {
			$this->db->transaction(
				function () use ( $b, $wpdb, &$afterRollback, &$afterCommit ): void {
					$this->insertRow( 1 );

					$this->db->afterRollback(
						static function () use ( &$afterRollback ): void {
							$afterRollback = true;
						}
					);
					$this->db->afterCommit(
						static function () use ( &$afterCommit ): void {
							$afterCommit = true;
						}
					);

					$killed = $this->db->threadId();

					$b->kill( $killed );
					$wpdb->check_connection();

					$this->assertNotSame( $killed, $this->db->threadId(), 'wpdb must be on a new connection now.' );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertNotNull( $lost, 'COMMIT must be refused.' );
		$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $lost->reason() );
		$this->assertSame( 0, $this->committedRows( $b ), 'The row died with the killed connection.' );
		$this->assertTrue( $afterRollback, 'The after-rollback callback ran.' );
		$this->assertFalse( $afterCommit, 'The after-commit callback did not run.' );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * A COMMIT sent behind wpdb's back ends the transaction, and the probe refuses the wrapper's COMMIT.
	 *
	 * Planted violation: in assertCommittable(), delete the probe (the RELEASE SAVEPOINT sc_0 block).
	 *
	 * @since 0.1.0
	 */
	public function test_a_foreign_commit_is_caught_by_the_probe(): void {
		global $wpdb;

		$b      = $this->secondConnection();
		$before = count( (array) $wpdb->queries );
		$lost   = null;

		try {
			$this->db->transaction(
				function () use ( $wpdb ): void {
					$this->insertRow( 1 );

					// Straight to mysqli: no wpdb filter can see this.
					mysqli_query( $wpdb->__get( 'dbh' ), 'COMMIT' );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$log = QueryLog::fromWpdb( array_slice( (array) $wpdb->queries, $before ) );

		$this->assertNotNull( $lost, 'The wrapper must refuse to commit a transaction it no longer holds.' );
		$this->assertSame( TransactionIntegrityLost::ENDED_EXTERNALLY, $lost->reason() );
		$this->assertInstanceOf( QueryFailed::class, $lost->getPrevious() );
		$this->assertSame( 1305, $lost->getPrevious()->errno(), 'This server reports a missing savepoint as error 1305.' );
		$this->assertQueryCount( 0, $log->matching( '/^COMMIT$/' ), 'The wrapper\'s COMMIT' );
		$this->assertSame( 1, $this->committedRows( $b ), 'The foreign COMMIT made the row durable; the wrapper can only refuse its own.' );
	}

	/**
	 * In strict mode an outbound request inside a window throws before any filter answers; otherwise it is reported.
	 *
	 * Planted violation: in TransactionGuards::register(), delete the `pre_http_request` registration.
	 *
	 * @since 0.1.0
	 */
	public function test_outbound_http_inside_a_window_is_refused_or_reported(): void {
		$answered = 0;

		add_filter(
			'pre_http_request',
			static function () use ( &$answered ): array {
				++$answered;

				return array(
					'headers'  => array(),
					'body'     => 'answered by the test',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$refused = null;

		try {
			$this->db->transaction( static fn() => wp_remote_get( 'https://rates.example.test/v1/latest' ) );
		} catch ( ForbiddenInsideTransaction $forbidden ) {
			$refused = $forbidden;
		}

		$this->assertNotNull( $refused, 'Strict mode must refuse the request.' );
		$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $refused->kind() );
		$this->assertSame( 'rates.example.test', $refused->detail() );
		$this->assertSame( 0, $answered, 'The guard runs before any filter that would answer.' );

		$reporting = $this->makeDatabase( false );
		$response  = $reporting->transaction( static fn() => wp_remote_get( 'https://rates.example.test/v1/latest' ) );

		$this->assertSame( 'answered by the test', wp_remote_retrieve_body( $response ) );
		$this->assertSame( 1, $answered );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( ForbiddenInsideTransaction::CODE->value, $this->reports[0]['code'] );
		$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $this->reports[0]['context']['kind'] );
	}

	/**
	 * The wp_mail() function inside a window throws in strict mode and is reported otherwise.
	 *
	 * Planted violation: in TransactionGuards::register(), delete the `pre_wp_mail` registration.
	 *
	 * @since 0.1.0
	 */
	public function test_mail_inside_a_window_is_refused_or_reported(): void {
		$refused = null;

		try {
			$this->db->transaction( static fn() => wp_mail( 'customer@example.test', 'Order', 'Body' ) );
		} catch ( ForbiddenInsideTransaction $forbidden ) {
			$refused = $forbidden;
		}

		$this->assertNotNull( $refused, 'Strict mode must refuse the mail.' );
		$this->assertSame( ForbiddenInsideTransaction::KIND_MAIL, $refused->kind() );

		$this->makeDatabase( false )->transaction( static fn() => wp_mail( 'customer@example.test', 'Order', 'Body' ) );

		$this->assertCount( 1, $this->reports );
		$this->assertSame( ForbiddenInsideTransaction::KIND_MAIL, $this->reports[0]['context']['kind'] );
	}

	/**
	 * Lists statements that end a transaction silently, and must be stopped inside a window: one row per guarded keyword.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{list<string>}> Statements starting with the keyword that keys the row, with `%t` for the fixture table.
	 */
	public static function transactionEnders(): array {
		return array(
			'ALTER'                           => array( array( 'ALTER TABLE `%t` ADD COLUMN extra int' ) ),
			'CREATE'                          => array( array( 'CREATE TABLE `%t_new` ( id int )', 'create table `%t_new` ( id int )' ) ),
			'DROP'                            => array( array( 'DROP TABLE `%t`' ) ),
			'RENAME'                          => array( array( 'RENAME TABLE `%t` TO `%t_renamed`' ) ),
			'TRUNCATE'                        => array( array( 'TRUNCATE TABLE `%t`' ) ),
			'START\s+TRANSACTION'             => array( array( 'START TRANSACTION', "START\n\tTRANSACTION" ) ),
			'BEGIN'                           => array( array( 'BEGIN' ) ),
			'COMMIT'                          => array( array( 'COMMIT', 'commit work' ) ),
			'ROLLBACK(?!\s+(?:WORK\s+)?TO\b)' => array( array( '  rollback', 'ROLLBACK WORK' ) ),
			'SET\s+(?:(?:SESSION|LOCAL)\s+|@@(?:SESSION\.|LOCAL\.)?)?autocommit' => array( array( 'SET autocommit = 0', 'SET SESSION autocommit = 1', 'SET @@autocommit = 1', 'SET @@SESSION.autocommit = 1' ) ),
			'LOCK\s+TABLES?'                  => array( array( 'LOCK TABLES `%t` WRITE', 'LOCK TABLE `%t` READ' ) ),
			'UNLOCK\s+TABLES?'                => array( array( 'UNLOCK TABLES' ) ),
			'ANALYZE'                         => array( array( 'ANALYZE TABLE `%t`' ) ),
			'OPTIMIZE'                        => array( array( 'OPTIMIZE TABLE `%t`' ) ),
			'REPAIR'                          => array( array( 'REPAIR TABLE `%t`' ) ),
			'FLUSH'                           => array( array( 'FLUSH TABLES' ) ),
			'LOAD\s+DATA'                     => array( array( "LOAD DATA LOCAL INFILE '/nonexistent' INTO TABLE `%t`" ) ),
		);
	}

	/**
	 * Tests that the statements above cover every keyword the guard refuses, one row per keyword.
	 *
	 * Planted violation: add a keyword to TransactionGuards::IMPLICIT_COMMIT without a row here.
	 *
	 * @since 0.1.0
	 */
	public function test_every_keyword_the_guard_refuses_has_statements_here(): void {
		$this->assertSame( TransactionGuards::IMPLICIT_COMMIT, array_keys( self::transactionEnders() ) );
	}

	/**
	 * DDL and foreign transaction control inside a window throw before wpdb sends them.
	 *
	 * Planted violation: in TransactionGuards::IMPLICIT_COMMIT, delete `CREATE|`. The
	 * CREATE TABLE case then creates the table and fails the "absent afterwards" assertion.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider transactionEnders
	 *
	 * @param string[] $statements Statements that start with the keyword.
	 */
	public function test_a_statement_that_ends_the_transaction_is_stopped_before_it_is_sent( array $statements ): void {
		foreach ( $statements as $statement ) {
			$this->assertStoppedBeforeItIsSent( str_replace( '%t', $this->rowsTable(), $statement ) );
		}
	}

	/**
	 * Asserts that the guard stops one statement inside a window before wpdb sends it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement.
	 */
	private function assertStoppedBeforeItIsSent( string $statement ): void {
		global $wpdb;

		$b       = $this->secondConnection();
		$refused = null;

		try {
			$this->db->transaction(
				function () use ( $wpdb, $statement ): void {
					$this->insertRow( 1 );

					$wpdb->query( $statement );
				}
			);
		} catch ( ForbiddenInsideTransaction $forbidden ) {
			$refused = $forbidden;
		}

		$this->assertNotNull( $refused, 'Strict mode must stop: ' . $statement );
		$this->assertSame( ForbiddenInsideTransaction::KIND_DDL, $refused->kind() );
		$this->assertSame( 0, $this->committedRows( $b ), 'Nothing was committed, implicitly or otherwise.' );
		$this->assertSame(
			'0',
			$b->fetchValue( sprintf( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('%1\$s_new', '%1\$s_renamed')", $this->rowsTable() ) ),
			'No table was created or renamed.'
		);
		$this->assertSame( '0', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = 'extra'", $this->rowsTable() ) ) );
	}

	/**
	 * Temporary tables, reads and savepoint statements pass the guard.
	 *
	 * @since 0.1.0
	 */
	public function test_temporary_tables_and_ordinary_statements_pass(): void {
		global $wpdb;

		$b     = $this->secondConnection();
		$table = $this->db->table( 'test_temporary' );

		$this->db->transaction(
			function () use ( $wpdb, $table ): void {
				$this->insertRow( 1 );

				$this->assertTrue( $wpdb->query( "CREATE TEMPORARY TABLE `{$table}` ( id int )" ) );
				$this->assertSame( 1, $wpdb->query( "INSERT INTO `{$table}` ( id ) VALUES ( 1 )" ) );
				$this->assertTrue( $wpdb->query( "DROP TEMPORARY TABLE `{$table}`" ) );
				$this->assertSame( 1, $wpdb->query( 'SELECT 1' ) );
			}
		);

		$this->assertSame( 1, $this->committedRows( $b ), 'The transaction committed normally.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * A duplicate key becomes DuplicateKey, from the error number, with no output and no log line.
	 *
	 * The strict PHPUnit configuration fails on output, and DatabaseTestCase fails a test that
	 * writes to the PHP error log, so a leak through wpdb::print_error() cannot pass.
	 *
	 * Planted violation: in QueryFailed::CLASSES, map 1062 to QueryFailed::class.
	 *
	 * @since 0.1.0
	 */
	public function test_a_duplicate_key_is_typed_by_its_error_number_and_stays_quiet(): void {
		global $EZSQL_ERROR;

		$b = $this->secondConnection();

		$this->insertRow( 1, 'first' );

		$duplicate = null;

		try {
			$this->insertRow( 1, 'second' );
		} catch ( DuplicateKey $failure ) {
			$duplicate = $failure;
		}

		$this->assertNotNull( $duplicate );
		$this->assertSame( 1062, $duplicate->errno() );
		$this->assertSame( DuplicateKey::CODE, $duplicate->errorCode() );
		$this->assertStringStartsWith( 'INSERT INTO', $duplicate->statement() );
		$this->assertSame( array(), $this->reports, 'Nothing was reported.' );

		$last = end( $EZSQL_ERROR );

		$this->assertIsArray( $last );
		$this->assertStringStartsWith( 'INSERT INTO', (string) $last['query'], 'wpdb recorded the statement, quietly.' );

		// A duplicate inside a window is an answer, not a broken unit of work: the window goes on.
		$this->db->transaction(
			function (): void {
				try {
					$this->insertRow( 1, 'third' );
				} catch ( DuplicateKey $claimedElsewhere ) {
					unset( $claimedElsewhere );
				}

				$this->insertRow( 2, 'after the duplicate' );
			}
		);

		$this->assertSame( 1, $this->committedRows( $b, 'id = 2' ) );
	}

	/**
	 * A deadlocked unit of work is rolled back, paused with jitter and run again whole.
	 *
	 * B locks rows 2 to 4 and waits, asynchronously, for row 1, which A holds; A then asks for
	 * row 2. InnoDB rolls back the lighter transaction, A. The pause is the barrier: the test's
	 * sleeper lets B finish and commit, so A's second attempt meets no lock.
	 *
	 * Planted violation: in transaction(), replace the retry loop with a single attempt.
	 *
	 * @since 0.1.0
	 */
	public function test_a_deadlocked_unit_of_work_is_retried_whole(): void {
		$b = $this->deadlockPartner();

		$attempts   = 0;
		$retryables = 0;

		$this->onSleep = function () use ( $b ): void {
			$this->assertTrue( $b->isReady( 5000 ), 'B\'s update of row 1 must finish once A has been rolled back.' );
			$this->assertSame( 1, $b->reap() );

			$b->query( 'COMMIT' );
		};

		$this->db->transaction(
			function () use ( $b, &$attempts, &$retryables ): void {
				++$attempts;

				$this->db->execute( "UPDATE %i SET value = 'a' WHERE id = 1", $this->rowsTable() );

				if ( 1 === $attempts ) {
					$waiting = sprintf( "UPDATE `%s` SET value = 'b' WHERE id = 1", $this->rowsTable() );

					$b->queryAsync( $waiting );

					// B must be waiting for A's lock on row 1, as the server sees it.
					$this->awaitWaiting( $b, $waiting, 'updating' );
				}

				try {
					$this->db->execute( "UPDATE %i SET value = 'a' WHERE id = 2", $this->rowsTable() );
				} catch ( TransactionRetryable $deadlock ) {
					++$retryables;

					throw $deadlock;
				}

				if ( 1 === $attempts ) {
					$this->fail( 'InnoDB chose B as the deadlock victim: victim selection changed; raise B\'s weight (more rows changed by B).' );
				}
			},
			RetryPolicy::deadlocks()
		);

		$this->assertSame( 2, $attempts, 'The whole unit of work ran twice.' );
		$this->assertSame( 1, $retryables, 'One deadlock was observed.' );
		$this->assertSame( array( array( 0, 50 ) ), $this->randomRanges, 'The pause after attempt 1 is drawn from [0, 50] ms.' );
		$this->assertSame( array( 50 ), $this->sleeps );
		$this->assertSame(
			'1:a,2:a,3:b,4:b',
			$b->fetchValue( sprintf( "SELECT GROUP_CONCAT( CONCAT( id, ':', value ) ORDER BY id ) FROM `%s`", $this->rowsTable() ) ),
			'A\'s writes won on rows 1 and 2; B\'s commit stands on rows 3 and 4.'
		);
	}

	/**
	 * An inner level never retries; its failure reaches the outermost level, whose policy re-runs everything.
	 *
	 * Planted violation: in transaction(), delete `$policy = RetryPolicy::none();` for inner levels.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_outermost_level_retries(): void {
		$outer = 0;
		$inner = 0;

		$this->db->transaction(
			function () use ( &$outer, &$inner ): void {
				++$outer;

				$this->db->transaction(
					static function () use ( &$inner ): void {
						++$inner;

						if ( 1 === $inner ) {
							throw QueryFailed::fromErrno( 1213, '40001', 'UPDATE', 'Deadlock found', true );
						}
					},
					RetryPolicy::deadlocks()
				);
			},
			RetryPolicy::deadlocks()
		);

		$this->assertSame( 2, $outer, 'The outermost level re-ran the whole unit of work.' );
		$this->assertSame( 2, $inner, 'The inner level ran once per outer attempt, never on its own.' );
	}

	/**
	 * After a deadlock, a statement sent from a catch block inside the window is refused, not autocommitted.
	 *
	 * Planted violation: in statement(), delete the aborted check. The statement then runs on a
	 * connection whose transaction InnoDB already ended, commits on its own, and B sees it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_statement_after_a_deadlock_is_refused(): void {
		$b    = $this->deadlockPartner();
		$lost = null;

		try {
			$this->db->transaction(
				function () use ( $b ): void {
					$this->db->execute( "UPDATE %i SET value = 'a' WHERE id = 1", $this->rowsTable() );

					$waiting = sprintf( "UPDATE `%s` SET value = 'b' WHERE id = 1", $this->rowsTable() );

					$b->queryAsync( $waiting );

					// B must be waiting for A's lock on row 1, as the server sees it.
					$this->awaitWaiting( $b, $waiting, 'updating' );

					try {
						$this->db->execute( "UPDATE %i SET value = 'a' WHERE id = 2", $this->rowsTable() );
					} catch ( TransactionRetryable $deadlock ) {
						// The careless clean-up this rule exists for.
						$this->insertRow( 99, 'written after the deadlock' );
					}
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertTrue( $b->isReady( 5000 ), 'B finishes once A is rolled back.' );
		$b->reap();
		$b->query( 'COMMIT' );

		$this->assertNotNull( $lost, 'The unit of work must fail.' );
		$this->assertSame( TransactionIntegrityLost::ABORTED, $lost->reason() );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 99' ), 'The statement after the deadlock must never be committed.' );
	}

	/**
	 * The known limit, pinned. wpdb re-runs a statement on its new connection after error
	 * 2006, where it commits on its own before the wrapper can refuse.
	 *
	 * No plant: this test documents a limit of any wrapper around wpdb, stated in the Database
	 * class description. It fails the day the limit is lifted, and whoever lifts it updates it.
	 *
	 * @since 0.1.0
	 */
	public function test_the_statement_wpdb_re_runs_after_a_reconnect_is_committed_on_its_own(): void {
		$b    = $this->secondConnection();
		$lost = null;

		try {
			$this->db->transaction(
				function () use ( $b ): void {
					$this->insertRow( 1, 'X' );

					$b->kill( $this->db->threadId() );

					$this->insertRow( 2, 'Y' );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertNotNull( $lost );
		$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $lost->reason() );
		$this->assertStringStartsWith( 'INSERT INTO', $lost->statement(), 'The exception names the statement wpdb re-ran.' );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 1' ), 'X died with the killed connection.' );
		$this->assertSame(
			1,
			$this->committedRows( $b, 'id = 2' ),
			'Known limit, stated in the Database class description: wpdb re-ran Y on its new connection, where it committed on its own before the wrapper regained control. If this now fails, the limit was lifted: update this test and that description.'
		);
	}

	/**
	 * A failing after-commit callback is reported, never thrown, and never runs the committed work again.
	 *
	 * The unit of work is durable once COMMIT returns. A listener that fails afterwards, even with
	 * a retryable error, must not reach the retry loop, which would run the work a second time,
	 * nor the caller, who would be told a committed write failed.
	 *
	 * Planted violation: in transaction(), run the after-commit callbacks inside the retry loop.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_after_commit_callback_is_reported_and_the_work_is_not_run_again(): void {
		$b    = $this->secondConnection();
		$runs = 0;
		$ran  = array();

		$this->db->transaction(
			function () use ( &$runs, &$ran ): void {
				++$runs;

				$this->insertRow( $runs, 'committed once' );

				$this->db->afterCommit(
					static function (): void {
						throw QueryFailed::fromErrno( 1205, 'HY000', 'UPDATE listener SET n = n + 1', 'Lock wait timeout exceeded', false );
					}
				);
				$this->db->afterCommit(
					static function () use ( &$ran ): void {
						$ran[] = 'second listener';
					}
				);
			},
			RetryPolicy::deadlocks()
		);

		$this->assertSame( 1, $runs, 'The committed unit of work ran exactly once.' );
		$this->assertSame( 1, $this->committedRows( $b ) );
		$this->assertSame( array( 'second listener' ), $ran, 'Every after-commit callback still runs.' );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'database.after_commit_failed', $this->reports[0]['code'] );
	}

	/**
	 * A statement issued after wpdb reconnected elsewhere in the window is refused before it is sent.
	 *
	 * Another plugin's query finds the connection gone, and wpdb reconnects to run it. The
	 * wrapper's next statement would then autocommit on the new connection, so the connection is
	 * compared before the statement is sent, not only after.
	 *
	 * Planted violation: in statement(), delete the connection check before send().
	 *
	 * @since 0.1.0
	 */
	public function test_a_statement_after_a_reconnect_elsewhere_is_refused_before_it_is_sent(): void {
		global $wpdb;

		$b    = $this->secondConnection();
		$lost = null;

		try {
			$this->db->transaction(
				function () use ( $b, $wpdb ): void {
					$this->insertRow( 1, 'X' );

					$b->kill( $this->db->threadId() );

					$this->assertSame( '1', $wpdb->get_var( 'SELECT 1' ), 'wpdb reconnected to run another query.' );

					$this->insertRow( 2, 'Y' );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertNotNull( $lost );
		$this->assertSame( TransactionIntegrityLost::CONNECTION_CHANGED, $lost->reason() );
		$this->assertSame( 0, $this->committedRows( $b ), 'X died with the killed connection, and Y must never have been sent.' );
	}

	/**
	 * A statement WordPress refuses without sending is not typed by the error number of an earlier statement.
	 *
	 * The mysqli handle keeps the last error number until the next statement it sends, so after a
	 * caught duplicate key, a refusal by WordPress itself would read as another duplicate.
	 *
	 * Planted violation: in send(), trust mysqli_errno() whatever wpdb's last error says.
	 *
	 * @since 0.1.0
	 */
	public function test_a_statement_wordpress_refuses_is_not_typed_by_a_stale_error_number(): void {
		$this->insertRow( 1, 'café' );

		try {
			$this->insertRow( 1, 'again' );
			$this->fail( 'The second insert must be a duplicate.' );
		} catch ( DuplicateKey $expected ) {
			$this->assertSame( 1062, $expected->errno() );
		}

		$refused = null;

		try {
			$this->insertRow( 2, "invalid \xC3\x28 bytes" );
		} catch ( QueryFailed $failure ) {
			$refused = $failure;
		}

		$this->assertNotNull( $refused, 'WordPress refuses invalid UTF-8 without sending it.' );
		$this->assertSame( QueryFailed::class, get_class( $refused ) );
		$this->assertSame( 0, $refused->errno() );
	}

	/**
	 * In reporting mode, a statement after a reported COMMIT is refused instead of autocommitting.
	 *
	 * Planted violation: in TransactionGuards::forbid(), report without marking the unit of work aborted.
	 *
	 * @since 0.1.0
	 */
	public function test_in_reporting_mode_a_statement_after_a_reported_commit_is_refused(): void {
		global $wpdb;

		$b         = $this->secondConnection();
		$reporting = $this->makeDatabase( false );
		$lost      = null;

		try {
			$reporting->transaction(
				function () use ( $reporting, $wpdb ): void {
					$reporting->execute( 'INSERT INTO %i ( id, value ) VALUES ( %d, %s )', $this->rowsTable(), 1, 'before' );

					$wpdb->query( 'COMMIT' );

					$reporting->execute( 'INSERT INTO %i ( id, value ) VALUES ( %d, %s )', $this->rowsTable(), 2, 'after' );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertNotNull( $lost, 'The statement after the reported COMMIT must be refused.' );
		$this->assertSame( TransactionIntegrityLost::ABORTED, $lost->reason() );
		$this->assertSame( 1, $this->committedRows( $b, 'id = 1' ), 'The foreign COMMIT made row 1 durable.' );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 2' ), 'Row 2 must not autocommit.' );
		$this->assertSame( ForbiddenInsideTransaction::KIND_DDL, $this->reports[0]['context']['kind'] ?? null );
	}

	/**
	 * An inner level whose savepoint is already gone leaves the unit of work aborted.
	 *
	 * Planted violation: in savepoint(), do not mark the unit aborted when ROLLBACK TO SAVEPOINT fails.
	 *
	 * @since 0.1.0
	 */
	public function test_an_inner_level_whose_savepoint_is_gone_leaves_the_unit_aborted(): void {
		global $wpdb;

		$b    = $this->secondConnection();
		$lost = null;

		try {
			$this->db->transaction(
				function () use ( $wpdb ): void {
					$this->insertRow( 1 );

					try {
						$this->db->transaction(
							function () use ( $wpdb ): void {
								$this->insertRow( 2 );

								mysqli_query( $wpdb->__get( 'dbh' ), 'COMMIT' );

								throw new \DomainException( 'inner failed' );
							}
						);
					} catch ( \DomainException $expected ) {
						unset( $expected );
					}

					// Carrying on would autocommit on a connection with no transaction left.
					$this->insertRow( 3 );
				}
			);
		} catch ( TransactionIntegrityLost $refused ) {
			$lost = $refused;
		}

		$this->assertNotNull( $lost );
		$this->assertSame( TransactionIntegrityLost::ABORTED, $lost->reason() );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 3' ) );
	}

	/**
	 * After-rollback callbacks run when their own level rolls back; a level that succeeds hands them to the level around it.
	 *
	 * Planted violation: in savepoint(), do not run the rolled-back level's after-rollback callbacks.
	 *
	 * @since 0.1.0
	 */
	public function test_after_rollback_callbacks_run_when_their_own_level_rolls_back(): void {
		$seen = array();

		try {
			$this->db->transaction(
				function () use ( &$seen ): void {
					try {
						$this->db->transaction(
							function () use ( &$seen ): void {
								$this->db->afterRollback(
									static function () use ( &$seen ): void {
										$seen[] = 'failed inner level';
									}
								);

								throw new \DomainException( 'inner failed' );
							}
						);
					} catch ( \DomainException $expected ) {
						$seen[] = 'outer level caught';
					}

					$this->db->transaction(
						function () use ( &$seen ): void {
							$this->db->afterRollback(
								static function () use ( &$seen ): void {
									$seen[] = 'succeeded inner level';
								}
							);
						}
					);

					throw new \LogicException( 'outer failed' );
				}
			);
		} catch ( \LogicException $expected ) {
			$seen[] = 'caller caught';
		}

		$this->assertSame( array( 'failed inner level', 'outer level caught', 'succeeded inner level', 'caller caught' ), $seen );
	}

	/**
	 * A failing after-rollback callback is reported, the others still run, and the rollback's cause propagates.
	 *
	 * Planted violation: in runAfterRollback(), rethrow the callback's failure instead of reporting it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_after_rollback_callback_is_reported(): void {
		$caught = null;
		$ran    = false;

		try {
			$this->db->transaction(
				function () use ( &$ran ): void {
					$this->db->afterRollback(
						static function (): void {
							throw new \RuntimeException( 'listener broke' );
						}
					);
					$this->db->afterRollback(
						static function () use ( &$ran ): void {
							$ran = true;
						}
					);

					throw new \DomainException( 'the work failed' );
				}
			);
		} catch ( \DomainException $failure ) {
			$caught = $failure;
		}

		$this->assertNotNull( $caught, 'The rollback\'s own cause propagates.' );
		$this->assertTrue( $ran, 'The next callback still ran.' );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'database.after_rollback_failed', $this->reports[0]['code'] );
		$this->assertSame( \RuntimeException::class, $this->reports[0]['context']['exception'] );
	}

	/**
	 * A ROLLBACK the server refuses is reported, and the failure that caused the rollback propagates.
	 *
	 * Planted violation: in abandon(), rethrow the ROLLBACK's failure instead of reporting it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_rollback_is_reported(): void {
		global $wpdb;

		$break  = static fn( $query ) => 'ROLLBACK' === $query ? 'ROLLBACK_THE_SERVER_REFUSES' : $query;
		$caught = null;

		add_filter( 'query', $break );

		try {
			$this->db->transaction(
				static function (): void {
					throw new \DomainException( 'the work failed' );
				}
			);
		} catch ( \DomainException $failure ) {
			$caught = $failure;
		} finally {
			remove_filter( 'query', $break );
			$wpdb->query( 'ROLLBACK' );
		}

		$this->assertNotNull( $caught, 'The rollback\'s own cause propagates.' );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( 'database.query_failed', $this->reports[0]['code'] );
		$this->assertSame( 'ROLLBACK', $this->reports[0]['context']['statement'] );
		$this->assertSame( 0, $this->db->depth() );
	}

	/**
	 * A failed ROLLBACK whose report throws still leaves the wrapper outside any transaction, ready for the next one.
	 *
	 * The reporter throws here the way a process dies there. Whatever it does, the levels are
	 * closed and the after-rollback callbacks run, so the next transaction() opens a fresh one
	 * instead of a savepoint inside a unit of work that no longer exists.
	 *
	 * Planted violation: in abandon(), reset the state after the ROLLBACK and its report instead of in a finally block.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_rollback_whose_report_throws_leaves_no_transaction_open(): void {
		global $wpdb;

		$b          = $this->secondConnection();
		$break      = static fn( $query ) => 'ROLLBACK' === $query ? 'ROLLBACK_THE_SERVER_REFUSES' : $query;
		$rolledBack = 0;
		$caught     = null;
		$db         = new Database(
			$wpdb,
			true,
			static function ( string $code ): void {
				throw new \RuntimeException( 'the reporter failed on ' . $code );
			},
			5,
			$this->sleeper(),
			$this->randomSource()
		);

		add_filter( 'query', $break );

		try {
			$db->transaction(
				static function () use ( $db, &$rolledBack ): void {
					$db->afterRollback(
						static function () use ( &$rolledBack ): void {
							++$rolledBack;
						}
					);

					throw new \DomainException( 'the work failed' );
				}
			);
		} catch ( \RuntimeException $reporterFailed ) {
			$caught = $reporterFailed->getMessage();
		} finally {
			remove_filter( 'query', $break );
			$wpdb->query( 'ROLLBACK' );
		}

		$this->assertSame( 'the reporter failed on database.query_failed', $caught, 'The reporter\'s failure propagates.' );
		$this->assertSame( 0, $db->depth(), 'No level is left open.' );
		$this->assertSame( 1, $rolledBack, 'The after-rollback callbacks ran.' );

		$db->transaction( fn() => $db->execute( 'INSERT INTO %i ( id, value ) VALUES ( %d, %s )', $this->rowsTable(), 7, 'after the failed rollback' ) );

		$this->assertSame( 1, $this->committedRows( $b, 'id = 7' ), 'The next transaction commits.' );
	}

	/**
	 * The lastInsertId() value is the AUTO_INCREMENT value of the last insert, inside a window too.
	 *
	 * Planted violation: make lastInsertId() return 0.
	 *
	 * @since 0.1.0
	 */
	public function test_last_insert_id_is_the_auto_increment_value_of_the_last_insert(): void {
		global $wpdb;

		$table = $this->db->table( 'test_auto' );

		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id bigint unsigned NOT NULL AUTO_INCREMENT, value varchar(20) NOT NULL, PRIMARY KEY (id) ) ENGINE=InnoDB', $table ) );

		$this->db->execute( 'INSERT INTO %i ( value ) VALUES ( %s )', $table, 'first' );

		$this->assertSame( 1, $this->db->lastInsertId() );

		$inside = $this->db->transaction(
			function () use ( $table ): int {
				$this->db->execute( 'INSERT INTO %i ( value ) VALUES ( %s )', $table, 'second' );

				return $this->db->lastInsertId();
			}
		);

		$this->assertSame( 2, $inside );
	}

	/**
	 * Commits rows 1 to 4, then opens connection B inside a transaction holding rows 2, 3 and 4.
	 *
	 * B has changed three rows and A will have changed one, so InnoDB picks A as the victim.
	 *
	 * @since 0.1.0
	 *
	 * @return SecondConnection Connection B, mid-transaction.
	 */
	private function deadlockPartner(): SecondConnection {
		foreach ( array( 1, 2, 3, 4 ) as $id ) {
			$this->insertRow( $id, 'seed' );
		}

		$b = $this->secondConnection();

		$b->query( 'START TRANSACTION' );
		$b->query( sprintf( "UPDATE `%s` SET value = 'b' WHERE id IN (2, 3, 4)", $this->rowsTable() ) );

		$this->assertSame( 3, $b->affectedRows() );

		return $b;
	}

	/**
	 * Records a cache key with the wrapper, then writes it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The key.
	 * @param string $value The value.
	 */
	private function touchAndSet( string $key, string $value ): void {
		$this->db->touchCacheKey( $key, self::CACHE_GROUP );

		wp_cache_set( $key, $value, self::CACHE_GROUP );
	}
}
