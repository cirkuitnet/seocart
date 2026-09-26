<?php
/**
 * Tests the migration that creates the order tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The order tables are created exactly as declared, after the platform's, and a second run changes nothing.
 *
 * Planted violation: at the end of CreateOrderTables::up(), drop the `order_current` unique key
 * of `order_totals` directly, so the table no longer has a key its declaration names. The
 * migrator's post-condition then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class OrderTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateOutboxMigration::ID, CreateOrderTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateOrderTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( OrderTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$this->assertSame( '0', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->db->table( OrderTables::NUMBER_SEQUENCE ) ), 'The counter gets its row from the first allocation, not from the migration.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateOrderTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: ten tables, their patterns and retention, and that the production registry lists them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_ten_tables(): void {
		$migration = new CreateOrderTables();

		$this->assertGreaterThan( CreateOutboxMigration::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( OrderTables::all(), $migration->tables() );

		$shape = array();

		foreach ( OrderTables::all() as $table ) {
			$this->assertSame( 'Order', $table->module() );

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention() );
		}

		$this->assertSame(
			array(
				'orders'               => array( MutationPattern::MutableTransactional, 'financial' ),
				'order_lines'          => array( MutationPattern::MutableTransactional, 'financial' ),
				'order_line_options'   => array( MutationPattern::MutableTransactional, 'financial' ),
				'order_tax_components' => array( MutationPattern::AppendOnly, 'financial' ),
				'order_adjustments'    => array( MutationPattern::MutableTransactional, 'financial' ),
				'order_addresses'      => array( MutationPattern::MutableTransactional, 'financial' ),
				'order_totals'         => array( MutationPattern::AppendOnly, 'financial' ),
				'order_events'         => array( MutationPattern::AppendOnly, 'financial' ),
				'order_number_seq'     => array( MutationPattern::MutableTransactional, 'permanent' ),
				'conversion_contexts'  => array( MutationPattern::AppendOnly, 'permanent' ),
			),
			$shape
		);
		$this->assertSame( OrderTables::names(), array_keys( $shape ) );

		$registered = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Order' === $table->module() ) {
				$registered[] = $table->name();
			}
		}

		$this->assertSame( OrderTables::names(), $registered, 'The production registry lists exactly the order tables.' );
	}

	/**
	 * Tests that an order records its stock hold in a nullable, public uuid column that no index names: every read of it is by the order's own row.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_records_its_hold_group_without_an_index(): void {
		$declared = null;

		foreach ( OrderTables::orders()->columns() as $column ) {
			if ( 'hold_group' === $column->name() ) {
				$declared = array( $column->type(), $column->nullable(), $column->classification()->value, $column->collation() );
			}
		}

		$this->assertSame( array( 'char(36)', true, 'public', 'ascii_bin' ), $declared );

		foreach ( array_merge( OrderTables::orders()->uniqueKeys(), OrderTables::orders()->indexes() ) as $index ) {
			$this->assertNotContains( 'hold_group', array_column( $index->columns(), 'name' ), "Index {$index->name()} names hold_group, which is read only by primary key." );
		}
	}

	/**
	 * Builds a migrator in table lock mode that records its reports.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[] $migrations The chain.
	 * @return Migrator The migrator.
	 */
	private function migrator( array $migrations ): Migrator {
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-26 12:00:00' ), $this->reporter() );
	}
}
