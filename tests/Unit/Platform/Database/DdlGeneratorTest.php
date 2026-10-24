<?php
/**
 * Tests the CREATE TABLE statements the DDL generator writes
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\DataRegistry\OwnedData;

/**
 * The generator's output, byte for byte, for a declaration that uses every rule.
 *
 * The expected strings are written out by hand, so a change to the generator is a change to
 * this test, never a silent change to every table. MigratorTest proves the other
 * half: that dbDelta, parsing this output for an existing table, sends no ALTER, on MySQL in
 * the plain form and on MariaDB in the form with integer display widths.
 *
 * Planted violations, each shown red and removed: in DdlGenerator::createTable(), write
 * `PRIMARY KEY (` with one space; in DdlGenerator::replaceUniqueKey(), write the key's columns as
 * a separate ADD after the DROP, in two statements: the table goes without the key between them;
 * in DdlGenerator::withDisplayWidth(), return the type unchanged: on MariaDB every "second run
 * sends no DDL" test then counts a CHANGE COLUMN for each integer column again.
 *
 * @since 0.1.0
 */
final class DdlGeneratorTest extends TestCase {

	/**
	 * The charset clause wpdb::get_charset_collate() returns on a utf8mb4 site.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CHARSET = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

	/**
	 * Tests the statement for a declaration that uses every formatting rule.
	 *
	 * @since 0.1.0
	 */
	public function test_the_statement_follows_every_dbdelta_rule(): void {
		$expected = "CREATE TABLE `wp_seocart_widgets` (\n"
			. "\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
			. "\t`uuid` char(36) COLLATE ascii_bin NOT NULL,\n"
			. "\t`name` varchar(191) NOT NULL DEFAULT '',\n"
			. "\t`price_minor` bigint NOT NULL DEFAULT '0',\n"
			. "\t`note` longtext NULL,\n"
			. "\t`created_at` datetime(6) NOT NULL,\n"
			. "\tPRIMARY KEY  (`id`),\n"
			. "\tUNIQUE KEY `uuid` (`uuid`),\n"
			. "\tKEY `name_created` (`name`(20),`created_at`)\n"
			. ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

		$this->assertSame( $expected, ( new DdlGenerator() )->createTable( self::widgets(), 'wp_seocart_widgets', self::CHARSET ) );
	}

	/**
	 * Tests the statement for an existing table on a server that reports integer display widths: only the integer types change.
	 *
	 * @since 0.2.0
	 */
	public function test_the_statement_with_integer_widths_changes_only_the_integer_types(): void {
		$expected = "CREATE TABLE `wp_seocart_widgets` (\n"
			. "\t`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n"
			. "\t`uuid` char(36) COLLATE ascii_bin NOT NULL,\n"
			. "\t`name` varchar(191) NOT NULL DEFAULT '',\n"
			. "\t`price_minor` bigint(20) NOT NULL DEFAULT '0',\n"
			. "\t`note` longtext NULL,\n"
			. "\t`created_at` datetime(6) NOT NULL,\n"
			. "\tPRIMARY KEY  (`id`),\n"
			. "\tUNIQUE KEY `uuid` (`uuid`),\n"
			. "\tKEY `name_created` (`name`(20),`created_at`)\n"
			. ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';

		$this->assertSame( $expected, ( new DdlGenerator() )->createTable( self::widgets(), 'wp_seocart_widgets', self::CHARSET, true ) );
	}

	/**
	 * Lists declared types with the form a server that reports integer display widths shows, as `SHOW COLUMNS` printed it on MariaDB 11.8.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> Declared, reported.
	 */
	public static function reportedTypes(): array {
		return array(
			'bigint unsigned'    => array( 'bigint unsigned', 'bigint(20) unsigned' ),
			'bigint'             => array( 'bigint', 'bigint(20)' ),
			'int unsigned'       => array( 'int unsigned', 'int(10) unsigned' ),
			'int'                => array( 'int', 'int(11)' ),
			'mediumint unsigned' => array( 'mediumint unsigned', 'mediumint(8) unsigned' ),
			'mediumint'          => array( 'mediumint', 'mediumint(9)' ),
			'smallint unsigned'  => array( 'smallint unsigned', 'smallint(5) unsigned' ),
			'smallint'           => array( 'smallint', 'smallint(6)' ),
			'tinyint unsigned'   => array( 'tinyint unsigned', 'tinyint(3) unsigned' ),
			'tinyint'            => array( 'tinyint', 'tinyint(4)' ),
			'a boolean'          => array( 'tinyint(1)', 'tinyint(1)' ),
			'varchar'            => array( 'varchar(191)', 'varchar(191)' ),
			'decimal'            => array( 'decimal(24,12)', 'decimal(24,12)' ),
			'datetime'           => array( 'datetime(6)', 'datetime(6)' ),
		);
	}

	/**
	 * Tests that each integer type gains the width a server that reports widths shows, and every other type is left alone.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider reportedTypes
	 *
	 * @param string $declared The type as a declaration writes it.
	 * @param string $reported The type as MariaDB reports it.
	 */
	public function test_a_type_is_written_as_a_server_that_reports_widths_reports_it( string $declared, string $reported ): void {
		$this->assertSame( $reported, DdlGenerator::withDisplayWidth( $declared ) );
	}

	/**
	 * Tests that every integer type a production table declares has a reported form, so a new one cannot be declared without it.
	 *
	 * @since 0.2.0
	 */
	public function test_every_integer_type_the_declarations_use_has_its_reported_width(): void {
		$types = array();

		foreach ( OwnedData::registry()->migrations() as $migration ) {
			if ( $migration instanceof SchemaMigration ) {
				foreach ( $migration->tables() as $table ) {
					foreach ( $table->columns() as $column ) {
						if ( 1 === preg_match( '/^(?:tinyint|smallint|mediumint|int|integer|bigint)\b/', $column->type() ) ) {
							$types[ $column->type() ] = DdlGenerator::withDisplayWidth( $column->type() );
						}
					}
				}
			}
		}

		ksort( $types );

		$this->assertSame(
			array(
				'bigint'            => 'bigint(20)',
				'bigint unsigned'   => 'bigint(20) unsigned',
				'int'               => 'int(11)',
				'int unsigned'      => 'int(10) unsigned',
				'smallint unsigned' => 'smallint(5) unsigned',
				'tinyint unsigned'  => 'tinyint(3) unsigned',
				'tinyint(1)'        => 'tinyint(1)',
			),
			$types,
			'A declaration uses an integer type this list does not know: add it here and to DdlGenerator::DISPLAY_WIDTHS, as SHOW COLUMNS prints it on MariaDB.'
		);
	}

	/**
	 * Tests the statements for the two platform tables, which the bootstrap migration sends.
	 *
	 * @since 0.1.0
	 */
	public function test_the_platform_tables(): void {
		$generator = new DdlGenerator();

		$this->assertSame(
			"CREATE TABLE `wp_seocart_locks` (\n"
			. "\t`name` varchar(191) COLLATE ascii_bin NOT NULL,\n"
			. "\t`owner_token` char(64) COLLATE ascii_bin NULL,\n"
			. "\t`acquired_at` datetime(6) NULL,\n"
			. "\t`expires_at` datetime(6) NULL,\n"
			. "\t`holder` varchar(64) COLLATE ascii_bin NULL,\n"
			. "\t`created_at` datetime NOT NULL,\n"
			. "\tPRIMARY KEY  (`name`)\n"
			. ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci',
			$generator->createTable( PlatformTables::locks(), 'wp_seocart_locks', self::CHARSET )
		);

		$migrations = $generator->createTable( PlatformTables::migrations(), 'wp_3_seocart_migrations', self::CHARSET );

		$this->assertStringStartsWith( "CREATE TABLE `wp_3_seocart_migrations` (\n\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n", $migrations );
		$this->assertStringContainsString( "\n\tPRIMARY KEY  (`id`),\n\tUNIQUE KEY `migration_id` (`migration_id`),\n\tKEY `state` (`state`),\n\tKEY `applied_at` (`applied_at`)\n) ENGINE=InnoDB ", $migrations );
	}

	/**
	 * Tests that no charset clause leaves no trailing space.
	 *
	 * @since 0.1.0
	 */
	public function test_an_empty_charset_clause_ends_the_statement_at_the_engine(): void {
		$this->assertStringEndsWith( "\n) ENGINE=InnoDB", ( new DdlGenerator() )->createTable( self::widgets(), 'wp_seocart_widgets', '' ) );
	}

	/**
	 * Tests the constructs dbDelta chokes on or ignores never appear.
	 *
	 * @since 0.1.0
	 */
	public function test_no_comment_no_if_not_exists_no_foreign_key_and_no_blank_line(): void {
		$statement = ( new DdlGenerator() )->createTable( self::widgets(), 'wp_seocart_widgets', self::CHARSET );

		foreach ( array( 'COMMENT', 'IF NOT EXISTS', 'FOREIGN KEY', 'REFERENCES', "\n\n" ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $statement );
		}
	}

	/**
	 * Tests the one statement that replaces a unique key: the key dropped and added again under its name, with its declared columns, in the same ALTER TABLE.
	 *
	 * @since 0.2.0
	 */
	public function test_a_unique_key_is_replaced_in_one_statement(): void {
		$this->assertSame(
			'ALTER TABLE `wp_seocart_widgets` DROP INDEX `uuid`, ADD UNIQUE KEY `uuid` (`uuid`)',
			( new DdlGenerator() )->replaceUniqueKey( self::widgets(), 'wp_seocart_widgets', 'uuid' )
		);
	}

	/**
	 * Tests that only a declared unique key is replaced: a plain key's name, and a name the table does not declare, are refused before any statement is written.
	 *
	 * @since 0.2.0
	 */
	public function test_a_name_that_is_no_declared_unique_key_is_refused(): void {
		foreach ( array( 'name_created', 'no_such_key', 'uuid`, DROP INDEX `name_created' ) as $name ) {
			try {
				( new DdlGenerator() )->replaceUniqueKey( self::widgets(), 'wp_seocart_widgets', $name );
				$this->fail( $name . ' was replaced.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringContainsString( 'declares no unique key named', $refused->getMessage(), $name );
			}
		}
	}

	/**
	 * Tests that a column's definition for an ALTER is written as its CREATE TABLE line is: its type as declared, its collation, its nullability, its default and its auto-increment.
	 *
	 * @since 0.2.0
	 */
	public function test_a_column_is_written_as_its_create_table_line(): void {
		$generator = new DdlGenerator();

		$this->assertSame( '`location_id` bigint unsigned NULL', $generator->columnLine( new ColumnSpec( 'location_id', 'bigint unsigned', Classification::Public, 'A location.', nullable: true ) ) );
		$this->assertSame( "`state` varchar(12) COLLATE ascii_bin NOT NULL DEFAULT 'open'", $generator->columnLine( new ColumnSpec( 'state', 'varchar(12)', Classification::Public, 'A state.', defaultValue: 'open', collation: 'ascii_bin' ) ) );
		$this->assertSame( '`id` bigint unsigned NOT NULL AUTO_INCREMENT', $generator->columnLine( new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Key.', autoIncrement: true ) ) );

		foreach ( self::widgets()->columns() as $column ) {
			$this->assertStringContainsString( "\t" . $generator->columnLine( $column ) . ",\n", $generator->createTable( self::widgets(), 'wp_seocart_widgets', self::CHARSET ), $column->name() );
		}
	}

	/**
	 * Declares a fixture table that uses every rule the generator applies.
	 *
	 * @since 0.1.0
	 *
	 * @return TableDefinition The declaration.
	 */
	private static function widgets(): TableDefinition {
		return new TableDefinition(
			'widgets',
			'Tests',
			'A fixture.',
			MutationPattern::Config,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Key.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'Public id.', collation: 'ascii_bin' ),
				new ColumnSpec( 'name', 'varchar(191)', Classification::Pii, 'A name.', defaultValue: '', erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'price_minor', 'bigint', Classification::Financial, 'Minor units.', defaultValue: '0' ),
				new ColumnSpec( 'note', 'longtext', Classification::Public, 'Free text.', nullable: true ),
				new ColumnSpec( 'created_at', 'datetime(6)', Classification::Public, 'UTC.' ),
			),
			array( 'id' ),
			array( IndexSpec::unique( 'uuid', array( 'uuid' ), 'One row per public id.' ) ),
			array( IndexSpec::key( 'name_created', array( 'name(20)', 'created_at' ), 'Lists by name.' ) ),
			'permanent',
			array()
		);
	}
}
