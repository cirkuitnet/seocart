<?php
/**
 * Tests the post-condition verifier against tables that differ from their declaration in one way each
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Tests\Support\DatabaseTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- Each test builds the table under inspection by hand, as a host or a broken migration would leave it.

/**
 * M4: the verifier notices each kind of difference on its own, and nothing else.
 *
 * Every case creates the declared table by hand with exactly one deviation and expects a diff
 * of exactly one line that names it. A matching table must give an empty diff.
 *
 * Planted violations: in SchemaVerifier, remove the comparison of one aspect at a time (the
 * column type, the nullability, the default, the undeclared-column check, the missing-index
 * check, the uniqueness, the index columns, the engine, the table collation, the column
 * collation). Each removal turns its own case red.
 *
 * The engine case uses MEMORY rather than MyISAM: some servers, the development server among
 * them, disable MyISAM, and the verifier only needs an engine that is not InnoDB.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class SchemaVerifierTest extends DatabaseTestCase {

	/**
	 * The matching definition, with `%s` for the table name, `%e` for the engine and `%c` for the charset clause.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const MATCHING = "CREATE TABLE `%s` (
		id bigint unsigned NOT NULL AUTO_INCREMENT,
		code char(3) COLLATE ascii_bin NOT NULL DEFAULT 'abc',
		name varchar(50) NOT NULL DEFAULT '',
		note varchar(20) NULL,
		a int NOT NULL DEFAULT '0',
		b int NOT NULL DEFAULT '0',
		PRIMARY KEY (id),
		UNIQUE KEY code (code),
		KEY name (name),
		KEY a_b (a, b)
	) ENGINE=%e %c";

	/**
	 * Tests that the table as declared passes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_matching_table_has_an_empty_diff(): void {
		$this->createShape( self::MATCHING );

		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( self::declaration() ) );
	}

	/**
	 * Tests that a missing table is one line.
	 *
	 * @since 0.1.0
	 */
	public function test_a_missing_table_is_one_line(): void {
		$this->assertSame( array( $this->shapeTable() . ': the table does not exist' ), ( new SchemaVerifier( $this->db ) )->diff( self::declaration() ) );
	}

	/**
	 * Lists the ten deviations, each as a replacement in the matching statement and the line it must produce.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, string}> Search, replace, expected diff line without the table name.
	 */
	public static function deviations(): array {
		return array(
			'column type'      => array( 'name varchar(50)', 'name varchar(60)', '.name: type is varchar(60), declared varchar(50)' ),
			'nullability'      => array( 'note varchar(20) NULL', 'note varchar(20) NOT NULL', '.note: NOT NULL, declared nullable' ),
			'default'          => array( "DEFAULT 'abc'", "DEFAULT 'xyz'", ".code: default is 'xyz', declared 'abc'" ),
			'extra column'     => array( 'b int NOT NULL', "extra int NULL,\n\t\tb int NOT NULL", '.extra: the column is not declared' ),
			'missing index'    => array( "KEY name (name),\n", '', ': index name does not exist' ),
			'unique vs plain'  => array( 'UNIQUE KEY code (code)', 'KEY code (code)', ': index code is not unique, declared unique' ),
			'index order'      => array( 'KEY a_b (a, b)', 'KEY a_b (b, a)', ': index a_b covers (b,a), declared (a,b)' ),
			'engine'           => array( '%e', 'MEMORY', ': engine is MEMORY, declared InnoDB' ),
			'table collation'  => array( '%c', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci', ': table collation is utf8mb4_general_ci, expected %collation%' ),
			'column collation' => array( 'char(3) COLLATE ascii_bin', 'char(3)', '.code: collation is \'%collation%\', declared ascii_bin' ),
		);
	}

	/**
	 * Tests that one deviation gives one line, naming it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider deviations
	 *
	 * @param string $search   What to change in the matching statement.
	 * @param string $replace  What to change it to.
	 * @param string $expected The diff line, after the table name.
	 */
	public function test_one_deviation_gives_one_line( string $search, string $replace, string $expected ): void {
		$statement = str_replace( $search, $replace, self::MATCHING );

		$this->assertNotSame( self::MATCHING, $statement, 'The deviation must change the statement.' );

		$this->createShape( $statement );

		$this->assertSame(
			array( $this->shapeTable() . str_replace( '%collation%', $this->db->collation(), $expected ) ),
			( new SchemaVerifier( $this->db ) )->diff( self::declaration() )
		);
	}

	/**
	 * Tests the ALTER guards a migration uses.
	 *
	 * @since 0.1.0
	 */
	public function test_has_column_and_has_index_read_the_live_table(): void {
		$this->createShape( self::MATCHING );

		$verifier = new SchemaVerifier( $this->db );

		$this->assertTrue( $verifier->hasTable( 'test_shape' ) );
		$this->assertTrue( $verifier->hasColumn( 'test_shape', 'note' ) );
		$this->assertFalse( $verifier->hasColumn( 'test_shape', 'missing' ) );
		$this->assertTrue( $verifier->hasIndex( 'test_shape', 'a_b' ) );
		$this->assertTrue( $verifier->hasIndex( 'test_shape', 'PRIMARY' ) );
		$this->assertFalse( $verifier->hasIndex( 'test_shape', 'missing' ) );
		$this->assertFalse( $verifier->hasTable( 'test_absent' ) );
	}

	/**
	 * Creates the table under inspection.
	 *
	 * @since 0.1.0
	 *
	 * @param string $statement The statement, with its `%s`, `%e` and `%c` markers.
	 */
	private function createShape( string $statement ): void {
		global $wpdb;

		$statement = str_replace( array( '%s', '%e', '%c' ), array( $this->shapeTable(), 'InnoDB', $this->db->charsetCollate() ), $statement );

		$this->assertTrue( $wpdb->query( $statement ), 'The fixture table could not be created: ' . $wpdb->last_error );
	}

	/**
	 * Returns the full name of the table under inspection.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	private function shapeTable(): string {
		return $this->db->table( 'test_shape' );
	}

	/**
	 * Declares the table under inspection.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	private static function declaration(): TableDefinition {
		return new TableDefinition(
			'test_shape',
			'Tests',
			'A table the verifier inspects.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Key.', autoIncrement: true ),
				new ColumnSpec( 'code', 'char(3)', Classification::Public, 'A code.', defaultValue: 'abc', collation: 'ascii_bin' ),
				new ColumnSpec( 'name', 'varchar(50)', Classification::Public, 'A name.', defaultValue: '' ),
				new ColumnSpec( 'note', 'varchar(20)', Classification::Public, 'A note.', nullable: true ),
				new ColumnSpec( 'a', 'int', Classification::Public, 'First of a pair.', defaultValue: '0' ),
				new ColumnSpec( 'b', 'int', Classification::Public, 'Second of a pair.', defaultValue: '0' ),
			),
			array( 'id' ),
			array( IndexSpec::unique( 'code', array( 'code' ), 'One row per code.' ) ),
			array(
				IndexSpec::key( 'name', array( 'name' ), 'Lookups by name.' ),
				IndexSpec::key( 'a_b', array( 'a', 'b' ), 'Lookups by the pair.' ),
			),
			'permanent',
			array()
		);
	}
}
