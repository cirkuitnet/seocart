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

/**
 * The generator's output, byte for byte, for a declaration that uses every rule.
 *
 * The expected strings are written out by hand, so a change to the generator is a change to
 * this test, never a silent change to every table. MigratorTest proves the other
 * half: that dbDelta, parsing this output for an existing table, sends no ALTER.
 *
 * Planted violation: in DdlGenerator::createTable(), write `PRIMARY KEY (` with one space.
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
				new ColumnSpec( 'name', 'varchar(191)', Classification::Pii, 'A name.', defaultValue: '' ),
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
