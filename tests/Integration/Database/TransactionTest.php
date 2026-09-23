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

use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Exception\TransactionDepthExceeded;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions, WordPress.DB.PreparedSQL -- These tests drive the connection directly to play the parts of third parties and failures.

/**
 * T1 to T13 and T15 to T18: what the wrapper commits, refuses and
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
	 * T1: a row inserted inside transaction() is visible to another connection after it returns.
	 *
	 * Planted violation: in unitOfWork(), send 'ROLLBACK' where 'COMMIT' is sent.
	 *
	 * @since 0.1.0
	 */
	public function test_t1_a_committed_row_is_visible_to_another_connection(): void {
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
	 * T2: an inner level that throws is undone on its own; the outer level catches, goes on and commits.
	 *
	 * Planted violation: in savepoint(), do not send ROLLBACK TO SAVEPOINT when the work throws.
	 *
	 * @since 0.1.0
	 */
	public function test_t2_an_inner_failure_caught_by_the_outer_level_undoes_only_the_inner_level(): void {
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
	 * T3: an inner failure that the outer level does not catch undoes everything and reaches the caller.
	 *
	 * Planted violation: in unitOfWork(), return null instead of rethrowing after abandon().
	 *
	 * @since 0.1.0
	 */
	public function test_t3_an_inner_failure_the_outer_level_does_not_catch_undoes_both_and_propagates(): void {
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
	 * T4: the callable's return value comes back unchanged, and depth() is the level inside.
	 *
	 * Planted violation: in unitOfWork(), return null instead of $result.
	 *
	 * @since 0.1.0
	 */
	public function test_t4_the_return_value_is_unchanged_and_depth_counts_the_levels(): void {
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
	 * T5: five levels work; a sixth is refused before any statement is sent.
	 *
	 * Planted violation: in transaction(), compare the ceiling with `>=` instead of `>`.
	 *
	 * @since 0.1.0
	 */
	public function test_t5_the_sixth_level_is_refused_before_any_statement(): void {
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
	 * T6: after-commit callbacks run once, after COMMIT, outside any level; never for a rolled-back level or a rollback.
	 *
	 * Planted violation: in unitOfWork(), run the after-commit callbacks just before sending COMMIT.
	 *
	 * @since 0.1.0
	 */
	public function test_t6_after_commit_callbacks_run_after_commit_and_only_for_committed_work(): void {
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
	 * T7: afterCommit() outside a transaction runs the callback at once.
	 *
	 * Planted violation: in afterCommit(), delete the depth-0 branch, so the callback is queued.
	 *
	 * @since 0.1.0
	 */
	public function test_t7_after_commit_outside_a_transaction_runs_at_once(): void {
		$runs = 0;

		$this->db->afterCommit(
			static function () use ( &$runs ): void {
				++$runs;
			}
		);

		$this->assertSame( 1, $runs );
	}

	/**
	 * T8: cache keys touched in a rolled-back level are removed; an inner rollback removes only its own.
	 *
	 * Planted violation: make flush() do nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_t8_cache_keys_touched_in_a_rolled_back_level_are_removed(): void {
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
	 * T9: after wpdb reconnects inside the window, COMMIT is refused because the connection changed.
	 *
	 * Planted violation: make assertSameConnection() return at once. The probe still refuses,
	 * but with the reason `ended_externally`, so the assertion on the reason proves the identity
	 * check specifically.
	 *
	 * @since 0.1.0
	 */
	public function test_t9_a_reconnect_inside_the_window_refuses_the_commit(): void {
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
	 * T10: a COMMIT sent behind wpdb's back ends the transaction, and the probe refuses the wrapper's COMMIT.
	 *
	 * Planted violation: in assertCommittable(), delete the probe (the RELEASE SAVEPOINT sc_0 block).
	 *
	 * @since 0.1.0
	 */
	public function test_t10_a_foreign_commit_is_caught_by_the_probe(): void {
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
	 * T11: in strict mode an outbound request inside a window throws before any filter answers; otherwise it is reported.
	 *
	 * Planted violation: in TransactionGuards::register(), delete the `pre_http_request` registration.
	 *
	 * @since 0.1.0
	 */
	public function test_t11_outbound_http_inside_a_window_is_refused_or_reported(): void {
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
	 * T11, mail half: wp_mail() inside a window throws in strict mode and is reported otherwise.
	 *
	 * Planted violation: in TransactionGuards::register(), delete the `pre_wp_mail` registration.
	 *
	 * @since 0.1.0
	 */
	public function test_t11_mail_inside_a_window_is_refused_or_reported(): void {
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
	 * Lists statements that end a transaction silently, and must be stopped inside a window.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}> The statement, with `%t` for the fixture table.
	 */
	public static function transactionEnders(): array {
		return array(
			'CREATE TABLE'           => array( 'CREATE TABLE `%t_new` ( id int )' ),
			'ALTER TABLE'            => array( 'ALTER TABLE `%t` ADD COLUMN extra int' ),
			'DROP TABLE'             => array( 'DROP TABLE `%t`' ),
			'TRUNCATE'               => array( 'TRUNCATE TABLE `%t`' ),
			'RENAME'                 => array( 'RENAME TABLE `%t` TO `%t_renamed`' ),
			'COMMIT'                 => array( 'COMMIT' ),
			'ROLLBACK'               => array( '  rollback' ),
			'START TRANSACTION'      => array( 'START TRANSACTION' ),
			'BEGIN'                  => array( 'BEGIN' ),
			'SET autocommit'         => array( 'SET autocommit = 0' ),
			'SET SESSION autocommit' => array( 'SET SESSION autocommit = 1' ),
			'LOCK TABLES'            => array( 'LOCK TABLES `%t` WRITE' ),
			'UNLOCK TABLES'          => array( 'UNLOCK TABLES' ),
			'OPTIMIZE'               => array( 'OPTIMIZE TABLE `%t`' ),
		);
	}

	/**
	 * T12: DDL and foreign transaction control inside a window throw before wpdb sends them.
	 *
	 * Planted violation: in TransactionGuards::IMPLICIT_COMMIT, delete `CREATE|`. The
	 * CREATE TABLE case then creates the table and fails the "absent afterwards" assertion.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider transactionEnders
	 *
	 * @param string $statement The statement.
	 */
	public function test_t12_a_statement_that_ends_the_transaction_is_stopped_before_it_is_sent( string $statement ): void {
		global $wpdb;

		$b         = $this->secondConnection();
		$statement = str_replace( '%t', $this->rowsTable(), $statement );
		$refused   = null;

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
	 * T12: temporary tables, reads and savepoint statements pass the guard.
	 *
	 * @since 0.1.0
	 */
	public function test_t12_temporary_tables_and_ordinary_statements_pass(): void {
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
	 * T13: a duplicate key becomes DuplicateKey, from the error number, with no output and no log line.
	 *
	 * The strict PHPUnit configuration fails on output, and DatabaseTestCase fails a test that
	 * writes to the PHP error log, so a leak through wpdb::print_error() cannot pass.
	 *
	 * Planted violation: in QueryFailed::CLASSES, map 1062 to QueryFailed::class.
	 *
	 * @since 0.1.0
	 */
	public function test_t13_a_duplicate_key_is_typed_by_its_error_number_and_stays_quiet(): void {
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
	 * T15: a deadlocked unit of work is rolled back, paused with jitter and run again whole.
	 *
	 * B locks rows 2 to 4 and waits, asynchronously, for row 1, which A holds; A then asks for
	 * row 2. InnoDB rolls back the lighter transaction, A. The pause is the barrier: the test's
	 * sleeper lets B finish and commit, so A's second attempt meets no lock.
	 *
	 * Planted violation: in transaction(), replace the retry loop with a single attempt.
	 *
	 * @since 0.1.0
	 */
	public function test_t15_a_deadlocked_unit_of_work_is_retried_whole(): void {
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
					$b->queryAsync( sprintf( "UPDATE `%s` SET value = 'b' WHERE id = 1", $this->rowsTable() ) );

					$this->assertFalse( $b->isReady( 0 ), 'B must be waiting for A\'s lock on row 1.' );
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
	 * T16: an inner level never retries; its failure reaches the outermost level, whose policy re-runs everything.
	 *
	 * Planted violation: in transaction(), delete `$policy = RetryPolicy::none();` for inner levels.
	 *
	 * @since 0.1.0
	 */
	public function test_t16_only_the_outermost_level_retries(): void {
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
	 * T17: after a deadlock, a statement sent from a catch block inside the window is refused, not autocommitted.
	 *
	 * Planted violation: in statement(), delete the aborted check. The statement then runs on a
	 * connection whose transaction InnoDB already ended, commits on its own, and B sees it.
	 *
	 * @since 0.1.0
	 */
	public function test_t17_a_statement_after_a_deadlock_is_refused(): void {
		$b    = $this->deadlockPartner();
		$lost = null;

		try {
			$this->db->transaction(
				function () use ( $b ): void {
					$this->db->execute( "UPDATE %i SET value = 'a' WHERE id = 1", $this->rowsTable() );

					$b->queryAsync( sprintf( "UPDATE `%s` SET value = 'b' WHERE id = 1", $this->rowsTable() ) );

					$this->assertFalse( $b->isReady( 0 ), 'B must be waiting for A\'s lock on row 1.' );

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
	 * T18: the known limit, pinned. wpdb re-runs a statement on its new connection after error
	 * 2006, where it commits on its own before the wrapper can refuse.
	 *
	 * No plant: this test documents a limit of any wrapper around wpdb, stated in the Database
	 * class description. It fails the day the limit is lifted, and whoever lifts it updates it.
	 *
	 * @since 0.1.0
	 */
	public function test_t18_the_statement_wpdb_re_runs_after_a_reconnect_is_committed_on_its_own(): void {
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
		$this->assertStringStartsWith( 'INSERT INTO', (string) $lost->context()['statement'], 'The exception names the statement wpdb re-ran.' );
		$this->assertSame( 0, $this->committedRows( $b, 'id = 1' ), 'X died with the killed connection.' );
		$this->assertSame(
			1,
			$this->committedRows( $b, 'id = 2' ),
			'Known limit, stated in the Database class description: wpdb re-ran Y on its new connection, where it committed on its own before the wrapper regained control. If this now fails, the limit was lifted: update this test and that description.'
		);
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
