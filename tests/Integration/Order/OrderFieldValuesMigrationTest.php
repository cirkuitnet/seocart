<?php
/**
 * Tests the migration that creates the table of the checkout fields' values an order was placed with
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Checkout\Infrastructure\Migrations\AddSessionFieldValues;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderFieldValues;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * A site whose order tables were created before the checkout fields' values gets their table from one statement, exactly as declared and as approved; a site installed since sends no DDL for it.
 *
 * The CREATE TABLE the step sends is compared byte for byte with the statement written out
 * here, the one approved: a change to the declaration or to the generator that changes it fails
 * here before it reaches a store.
 *
 * Planted violation, shown red and removed: in OrderTables::fieldValues(), leave out the unique
 * key `order_field`. The declaration then writes another statement than the approved one.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class OrderFieldValuesMigrationTest extends DatabaseTestCase {

	/**
	 * The statement that creates the table, as approved: `{prefix}` and `{charset_collate}` are the site's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TABLE_DDL = "CREATE TABLE `{prefix}seocart_order_field_values` (\n"
		. "\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
		. "\t`order_id` bigint unsigned NOT NULL,\n"
		. "\t`field_key` varchar(64) COLLATE ascii_bin NOT NULL,\n"
		. "\t`field_source` varchar(64) COLLATE ascii_bin NOT NULL,\n"
		. "\t`section` varchar(16) COLLATE ascii_bin NOT NULL,\n"
		. "\t`type` varchar(16) COLLATE ascii_bin NOT NULL,\n"
		. "\t`label_snapshot` varchar(255) NOT NULL,\n"
		. "\t`field_value` text NULL,\n"
		. "\t`value_label_snapshot` varchar(255) NULL,\n"
		. "\t`locale_snapshot` varchar(20) COLLATE ascii_bin NOT NULL,\n"
		. "\t`position` int NOT NULL,\n"
		. "\tPRIMARY KEY  (`id`),\n"
		. "\tUNIQUE KEY `order_field` (`order_id`,`field_key`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * Tests that, on a site whose order tables were created without it, the step creates the table once with the approved statement, the head moves, and a second run sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_table_is_created_on_a_site_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the table was declared.
		$this->db->execute( 'DROP TABLE %i', $this->db->table( OrderTables::FIELD_VALUES ) );

		$chain     = array_merge( $before, array( new CreateOrderFieldValues() ) );
		$report    = null;
		$generator = new DdlGenerator();
		$log       = $this->captureQueries(
			function () use ( $chain, &$report ): void {
				$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);
		$sent      = $this->approved( self::TABLE_DDL );

		fwrite( STDOUT, "\nThe order step sent: " . implode( "\n", $log->ofType( 'CREATE' )->sqls() ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The statement sent is printed for the report.

		$this->assertSame( array( CreateOrderFieldValues::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateOrderFieldValues::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( $sent, $generator->createTable( OrderTables::fieldValues(), $this->db->table( OrderTables::FIELD_VALUES ), $this->db->charsetCollate() ), 'The declaration writes the approved statement, byte for byte.' );
		$this->assertSame( array( trim( (string) preg_replace( '/\s+/', ' ', $sent ) ) ), $log->ofType( 'CREATE' )->sqls(), 'The step sends it once; the log keeps it with its white space collapsed.' );
		$this->assertQueryCount( 0, $log->ofType( 'ALTER', 'DROP' ), 'ALTER and DROP statements of the step' );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::fieldValues() ), 'order_field_values matches its declaration exactly.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateOrderFieldValues() )->up( new SchemaOperations( $this->db, $generator, new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing table' );
	}

	/**
	 * Tests that a site installed since has the table from the order tables' own migration, and the step sends no DDL there.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertTrue( ( new SchemaVerifier( $this->db ) )->hasTable( OrderTables::FIELD_VALUES ), 'The order tables\' own migration creates the table.' );

		$log = $this->captureQueries( fn() => ( new CreateOrderFieldValues() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the table' );
	}

	/**
	 * Tests the step's place and what it declares: after the session's step, the store not trading while it is outstanding, in the order module's contribution; the value and the option's label personal data the eraser destroys, and the field's key and label kept with the order.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_follows_the_sessions_and_declares_the_values_personal_data(): void {
		$migration = new CreateOrderFieldValues();
		$table     = OrderTables::fieldValues();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );
		$handling  = array();

		foreach ( $table->columns() as $column ) {
			$handling[ $column->name() ] = Classification::Pii === $column->classification() ? $column->erasure() : $column->classification()->value;
		}

		$this->assertContains( CreateOrderFieldValues::ID, $ids, 'The registry runs the step.' );
		$this->assertGreaterThan( AddSessionFieldValues::ID, $migration->id(), 'The step sorts after the session\'s, and so after every migration before it.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'A placement may write the table, so the store waits for it.' );
		$this->assertEquals( array( $table ), $migration->tables() );
		$this->assertSame( array( 'Order', MutationPattern::MutableTransactional, 'financial' ), array( $table->module(), $table->mutationPattern(), $table->retention() ) );
		$this->assertSame(
			array(
				'id'                   => 'public',
				'order_id'             => 'public',
				'field_key'            => 'public',
				'field_source'         => 'public',
				'section'              => 'public',
				'type'                 => 'public',
				'label_snapshot'       => 'public',
				'field_value'          => ColumnSpec::ERASE_DESTROY,
				'value_label_snapshot' => ColumnSpec::ERASE_DESTROY,
				'locale_snapshot'      => 'public',
				'position'             => 'public',
			),
			$handling
		);
	}

	/**
	 * Returns an approved statement with the site's table prefix and character set.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement The statement, with `{prefix}` and `{charset_collate}`.
	 * @return string The statement as the site sends it.
	 */
	private function approved( string $statement ): string {
		return rtrim( str_replace( array( '{prefix}', '{charset_collate}' ), array( $this->db->prefix(), $this->db->charsetCollate() ), $statement ) );
	}

	/**
	 * Builds a migrator in table lock mode that records its reports.
	 *
	 * @since 0.2.0
	 *
	 * @param Migration[] $migrations The chain.
	 * @return Migrator The migrator.
	 */
	private function migrator( array $migrations ): Migrator {
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-11-15 12:00:00' ), $this->reporter() );
	}
}
