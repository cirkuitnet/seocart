<?php
/**
 * Tests the ALTER TABLE that brings an existing table to its declaration
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Tests\Support\DatabaseTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Each test builds the table of the old shape by hand, as an earlier release left it.

/**
 * A table of an earlier shape reaches its declaration in one ALTER, which a second run does not send again; wrong clauses are named at the statement; and a row the change cannot keep refuses the whole ALTER.
 *
 * The table is shaped as a stock item table was before it was keyed by location: keyed by the
 * variant, with two columns that are to go and the location column added nullable. The
 * declaration keys it by variant and location, with the location NOT NULL.
 *
 * WordPress opens its connection without strict mode, where a column made NOT NULL over a NULL
 * would take 0 instead and the ALTER would succeed; alterTable() sends the ALTER in strict mode,
 * so the server refuses it whole, and gives the session its own mode back afterwards. Both are
 * asserted on the server the suite runs on, MySQL or MariaDB.
 *
 * Planted violations, each shown red and removed: in SchemaOperations::alterTable(), send the
 * ALTER without the diff() before it: the second run sends it again, and the server refuses to
 * drop a column that is gone; without the diff() after it: the clauses that leave out a key
 * pass, and the migrator's check of the whole migration would be the first to notice, naming the
 * migration and not the statement; in SchemaOperations::alterStrictly(), send the ALTER alone,
 * without the two SET statements: the row whose location is NULL is stored at location 0 and the
 * ALTER succeeds.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class AlterTableTest extends DatabaseTestCase {

	/**
	 * The table's unprefixed name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TABLE = 'test_altered';

	/**
	 * The table as an earlier release left it, with `%s` for its name and `%c` for the charset clause.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OLD_SHAPE = "CREATE TABLE `%s` (
		variant_id bigint unsigned NOT NULL,
		on_hand int NOT NULL DEFAULT '0',
		track tinyint(1) NOT NULL DEFAULT '1',
		low_stock_threshold int NULL,
		updated_at datetime(6) NOT NULL,
		location_id bigint unsigned NULL,
		PRIMARY KEY (variant_id),
		KEY track_threshold (track, low_stock_threshold)
	) ENGINE=InnoDB %c";

	/**
	 * The clauses that bring the old shape to the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const CLAUSES = array(
		'MODIFY location_id bigint unsigned NOT NULL',
		'DROP PRIMARY KEY',
		'ADD PRIMARY KEY (variant_id, location_id)',
		'DROP COLUMN track',
		'DROP COLUMN low_stock_threshold',
		'ADD KEY location_on_hand (location_id, on_hand)',
	);

	/**
	 * Tests that the old shape reaches the declaration with its rows, in one ALTER sent in strict mode, and that a second run sends nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_old_shape_reaches_its_declaration_and_a_second_run_sends_nothing(): void {
		$this->createOldShape( array( array( 1, 5, 7 ), array( 2, 3, 7 ) ) );

		$mode = $this->sessionMode();
		$log  = $this->captureQueries( fn() => $this->operations()->alterTable( self::declaration(), ...self::CLAUSES ) );

		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( self::declaration() ), 'The table matches its declaration exactly.' );
		$this->assertSame( array( array( '1', '7', '5' ), array( '2', '7', '3' ) ), $this->rows(), 'Every row is kept as it was.' );
		$this->assertSame(
			array( 'STRICT', 'ALTER TABLE `' . $this->db->table( self::TABLE ) . '` ' . implode( ', ', self::CLAUSES ), 'RESTORE' ),
			self::changes( $log->sqls() ),
			'One ALTER, between the SET that adds strict mode and the one that restores the session\'s mode'
		);
		$this->assertSame( $mode, $this->sessionMode(), 'The session has its own mode back.' );

		$again = $this->captureQueries( fn() => $this->operations()->alterTable( self::declaration(), ...self::CLAUSES ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP', 'SET' ), 'DDL or a mode change on a second run' );
	}

	/**
	 * Tests that a row whose location is still NULL refuses the whole ALTER, the table left as it was, and that the session has its own mode back.
	 *
	 * In WordPress's mode, which is not strict, the same ALTER succeeds and stores the row at location 0.
	 *
	 * @since 0.2.0
	 */
	public function test_a_row_the_change_cannot_keep_refuses_the_whole_alter_and_the_mode_is_restored(): void {
		$this->createOldShape( array( array( 1, 5, 7 ), array( 2, 3, null ) ) );

		$mode    = $this->sessionMode();
		$refused = null;

		$this->assertStringNotContainsString( 'STRICT', $mode, 'WordPress\'s connection is not strict, which is why the ALTER adds strict mode itself.' );

		try {
			$this->operations()->alterTable( self::declaration(), ...self::CLAUSES );
		} catch ( DatabaseException $failure ) {
			$refused = $failure;
		}

		$this->assertInstanceOf( QueryFailed::class, $refused, 'The server refused the ALTER.' );
		$this->assertSame( array( array( '1', '7', '5' ), array( '2', null, '3' ) ), $this->rows(), 'The row keeps its NULL.' );
		$this->assertSame(
			array(
				'primary'         => 'variant_id',
				'track_threshold' => 'track,low_stock_threshold',
			),
			$this->keys(),
			'The table keeps its old keys.'
		);
		$this->assertSame( 'YES', $this->db->fetchValue( 'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->db->table( self::TABLE ), 'location_id' ), 'The column is still nullable.' );
		$this->assertSame( $mode, $this->sessionMode(), 'The session has its own mode back.' );
	}

	/**
	 * Tests that clauses that leave the table short of its declaration fail at the statement, naming it and what is left.
	 *
	 * @since 0.2.0
	 */
	public function test_clauses_that_leave_the_table_short_name_the_statement_and_the_difference(): void {
		$this->createOldShape( array( array( 1, 5, 7 ) ) );

		$short = array_slice( self::CLAUSES, 0, -1 );

		try {
			$this->operations()->alterTable( self::declaration(), ...$short );
			$this->fail( 'Clauses that leave out a key passed.' );
		} catch ( \UnexpectedValueException $left ) {
			$this->assertStringContainsString( 'ALTER TABLE `' . $this->db->table( self::TABLE ) . '` ' . implode( ', ', $short ) . ' left the table short of its declaration', $left->getMessage() );
			$this->assertStringContainsString( 'index location_on_hand does not exist', $left->getMessage() );
		}
	}

	/**
	 * Tests that a clause outside the declared forms, and an ALTER with none, are refused before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_clause_outside_the_declared_forms_is_refused_before_any_statement(): void {
		$this->createOldShape( array() );

		foreach ( array( array(), array( 'RENAME INDEX track_threshold TO tracked' ), array( 'DROP COLUMN track', 'ALGORITHM=INPLACE' ), array( 'modify location_id bigint unsigned NOT NULL' ), array( 'DROP COLUMN track; DROP TABLE x' ) ) as $clauses ) {
			$log = $this->captureQueries(
				function () use ( $clauses ): void {
					try {
						$this->operations()->alterTable( self::declaration(), ...$clauses );
						$this->fail( implode( ', ', $clauses ) . ' was sent.' );
					} catch ( \InvalidArgumentException $refused ) {
						unset( $refused );
					}
				}
			);

			$this->assertQueryCount( 0, $log, 'Statements for ' . ( array() === $clauses ? 'no clause' : implode( ', ', $clauses ) ) );
		}
	}

	/**
	 * Creates the table in its old shape, with rows.
	 *
	 * @since 0.2.0
	 *
	 * @param list<array{0: int, 1: int, 2: int|null}> $rows Each row's variant, on hand and location.
	 */
	private function createOldShape( array $rows ): void {
		global $wpdb;

		$this->assertTrue( $wpdb->query( str_replace( array( '%s', '%c' ), array( $this->db->table( self::TABLE ), $this->db->charsetCollate() ), self::OLD_SHAPE ) ), 'The fixture table could not be created: ' . $wpdb->last_error );

		foreach ( $rows as $row ) {
			$this->db->execute( 'INSERT INTO %i ( variant_id, on_hand, location_id, updated_at ) VALUES ( %d, %d, NULLIF( %d, 0 ), UTC_TIMESTAMP(6) )', $this->db->table( self::TABLE ), $row[0], $row[1], $row[2] ?? 0 );
		}
	}

	/**
	 * Reads the rows.
	 *
	 * @since 0.2.0
	 *
	 * @return list<list<string|null>> Each row's variant, location and on hand, by variant.
	 */
	private function rows(): array {
		return array_map( static fn( array $row ): array => array_values( $row ), $this->db->fetchAll( 'SELECT variant_id, location_id, on_hand FROM %i ORDER BY variant_id', $this->db->table( self::TABLE ) ) );
	}

	/**
	 * Reads the table's keys.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each key's columns, comma-separated, by lowercase name.
	 */
	private function keys(): array {
		$keys = array();

		foreach ( $this->db->fetchAll( 'SELECT INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME, SEQ_IN_INDEX', $this->db->table( self::TABLE ) ) as $row ) {
			$name          = strtolower( (string) $row['INDEX_NAME'] );
			$keys[ $name ] = ( isset( $keys[ $name ] ) ? $keys[ $name ] . ',' : '' ) . (string) $row['COLUMN_NAME'];
		}

		return $keys;
	}

	/**
	 * Reads the session's SQL mode.
	 *
	 * @since 0.2.0
	 *
	 * @return string The mode.
	 */
	private function sessionMode(): string {
		return (string) $this->db->fetchValue( 'SELECT @@SESSION.sql_mode' );
	}

	/**
	 * Names the statements of a log that change the schema or the session's mode, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $sqls The statements.
	 * @return list<string> `STRICT` for the SET that adds strict mode, `RESTORE` for the one that restores the mode, and each ALTER as sent.
	 *
	 * @phpstan-param list<string> $sqls
	 */
	private static function changes( array $sqls ): array {
		$changes = array();

		foreach ( $sqls as $sql ) {
			if ( str_starts_with( $sql, 'SET @seocart_sql_mode' ) ) {
				$changes[] = 'STRICT';
			} elseif ( str_starts_with( $sql, 'SET SESSION sql_mode' ) ) {
				$changes[] = 'RESTORE';
			} elseif ( str_starts_with( $sql, 'ALTER' ) ) {
				$changes[] = $sql;
			}
		}

		return $changes;
	}

	/**
	 * Builds the DDL operations over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @return SchemaOperations The operations.
	 */
	private function operations(): SchemaOperations {
		return new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );
	}

	/**
	 * Declares the table in the shape it must reach.
	 *
	 * @since 0.2.0
	 *
	 * @return TableDefinition The declaration.
	 */
	private static function declaration(): TableDefinition {
		return new TableDefinition(
			self::TABLE,
			'Tests',
			'A table keyed again by the ALTER under test.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'variant_id', 'bigint unsigned', Classification::Public, 'The variant.' ),
				new ColumnSpec( 'location_id', 'bigint unsigned', Classification::Public, 'The location.' ),
				new ColumnSpec( 'on_hand', 'int', Classification::Public, 'Units on hand.', defaultValue: '0' ),
				new ColumnSpec( 'updated_at', 'datetime(6)', Classification::Public, 'When the row last changed.' ),
			),
			array( 'variant_id', 'location_id' ),
			array(),
			array( IndexSpec::key( 'location_on_hand', array( 'location_id', 'on_hand' ), 'A location\'s units.' ) ),
			'permanent',
			array()
		);
	}
}
