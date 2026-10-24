<?php
/**
 * Tests the ALTER TABLE that adds a column a migration needs before its table reaches its declaration
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
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Tests\Support\DatabaseTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Each test builds the table by hand, as an earlier release or a fresh install left it.

/**
 * A column is added as it is written, once: a table that has a column of that name, whatever its nullability, is left as it is.
 *
 * The column is the location a stock row gets before its rows are filled in: nullable while the
 * migration fills it, NOT NULL in a table created since in its final shape.
 *
 * Planted violation, shown red and removed: in SchemaOperations::addColumn(), send the ALTER
 * without asking SchemaVerifier::hasColumn() first: the second run sends it again and the server
 * refuses the duplicate column, and a table created in its final shape is refused the same way.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class AddColumnTest extends DatabaseTestCase {

	/**
	 * The table's unprefixed name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TABLE = 'test_added';

	/**
	 * Tests that a missing column is added as written, in strict mode, with the rows kept, and that a second run sends nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_missing_column_is_added_as_written_and_a_second_run_sends_nothing(): void {
		$this->createTable( '' );
		$this->db->execute( 'INSERT INTO %i ( variant_id ) VALUES ( 1 ), ( 2 )', $this->db->table( self::TABLE ) );

		$mode = $this->sessionMode();
		$log  = $this->captureQueries( fn() => $this->operations()->addColumn( self::TABLE, self::location() ) );

		$this->assertSame( array( 'bigint unsigned', 'YES' ), $this->column(), 'The column is there, nullable.' );
		$this->assertSame( array( array( '1', null ), array( '2', null ) ), $this->rows(), 'Every row is kept, its location NULL.' );
		$this->assertSame(
			array( 'SET @seocart_sql_mode =', 'ALTER TABLE `' . $this->db->table( self::TABLE ) . '` ADD COLUMN `location_id` bigint unsigned NULL', 'SET SESSION sql_mode' ),
			array_map( static fn( string $sql ): string => str_starts_with( $sql, 'SET' ) ? implode( ' ', array_slice( explode( ' ', $sql ), 0, 3 ) ) : $sql, $log->ofType( 'ALTER', 'SET' )->sqls() ),
			'One ALTER, between the SET that adds strict mode and the one that restores the session\'s mode'
		);
		$this->assertSame( $mode, $this->sessionMode(), 'The session has its own mode back.' );

		$again = $this->captureQueries( fn() => $this->operations()->addColumn( self::TABLE, self::location() ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP', 'SET' ), 'DDL or a mode change on a second run' );
	}

	/**
	 * Tests that a table created since in its final shape, the column NOT NULL, is left as it is.
	 *
	 * @since 0.2.0
	 */
	public function test_a_column_already_there_with_another_nullability_is_left_as_it_is(): void {
		$this->createTable( ', location_id bigint unsigned NOT NULL' );

		$log = $this->captureQueries( fn() => $this->operations()->addColumn( self::TABLE, self::location() ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP', 'SET' ), 'DDL on a table that has the column' );
		$this->assertSame( array( 'bigint unsigned', 'NO' ), $this->column(), 'The column stays NOT NULL.' );
	}

	/**
	 * Tests that an ALTER the server refuses, of a table that is not there, leaves the session its own mode.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refused_alter_leaves_the_session_its_own_mode(): void {
		$mode    = $this->sessionMode();
		$refused = null;

		try {
			$this->operations()->addColumn( self::TABLE, self::location() );
		} catch ( DatabaseException $failure ) {
			$refused = $failure;
		}

		$this->assertInstanceOf( QueryFailed::class, $refused, 'The server refused the ALTER of a table that does not exist.' );
		$this->assertSame( $mode, $this->sessionMode(), 'The session has its own mode back.' );
	}

	/**
	 * Creates the table.
	 *
	 * @since 0.2.0
	 *
	 * @param string $location The location column's definition, after a comma, or '' for none.
	 */
	private function createTable( string $location ): void {
		global $wpdb;

		$this->assertTrue( $wpdb->query( sprintf( 'CREATE TABLE `%1$s` ( variant_id bigint unsigned NOT NULL%2$s, PRIMARY KEY (variant_id) ) ENGINE=InnoDB %3$s', $this->db->table( self::TABLE ), $location, $this->db->charsetCollate() ) ), 'The fixture table could not be created: ' . $wpdb->last_error );
	}

	/**
	 * Reads the location column's type and nullability.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> Its type, without a display width, and YES or NO.
	 */
	private function column(): array {
		$row = (array) $this->db->fetchRow( 'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->db->table( self::TABLE ), 'location_id' );

		return array( SchemaVerifier::normalizeType( (string) ( $row['type'] ?? '' ) ), (string) ( $row['nullable'] ?? '' ) );
	}

	/**
	 * Reads the rows.
	 *
	 * @since 0.2.0
	 *
	 * @return list<list<string|null>> Each row's variant and location, by variant.
	 */
	private function rows(): array {
		return array_map( static fn( array $row ): array => array_values( $row ), $this->db->fetchAll( 'SELECT variant_id, location_id FROM %i ORDER BY variant_id', $this->db->table( self::TABLE ) ) );
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
	 * Declares the column as the migration adds it: nullable, for its rows to be filled in afterwards.
	 *
	 * @since 0.2.0
	 *
	 * @return ColumnSpec The column.
	 */
	private static function location(): ColumnSpec {
		return new ColumnSpec( 'location_id', 'bigint unsigned', Classification::Public, 'The location, filled in after the column is added.', nullable: true );
	}
}
