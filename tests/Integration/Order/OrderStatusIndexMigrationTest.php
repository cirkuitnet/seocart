<?php
/**
 * Tests the migration that adds the index on the orders' status and age
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\Migrations\AddOrderStatusIndex;
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
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * A site whose orders were created before the index gets it, exactly as declared, and a site installed since sends no DDL for it.
 *
 * Planted violation: in AddOrderStatusIndex::up(), do nothing. The migrator's post-condition then
 * fails the migration on the older site.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class OrderStatusIndexMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the index is added to orders created without it, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_index_is_added_to_orders_created_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the index was declared.
		$this->db->execute( 'ALTER TABLE %i DROP INDEX status_created', $this->db->table( OrderTables::ORDERS ) );
		$this->assertSame( array(), $this->indexColumns(), 'The older site has no index.' );

		$chain  = array_merge( $before, array( new AddOrderStatusIndex() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddOrderStatusIndex::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddOrderStatusIndex::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array( 'status', 'created_at' ), $this->indexColumns() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::orders() ), 'orders matches its declaration exactly.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddOrderStatusIndex() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the indexed table' );
	}

	/**
	 * Tests that a site installed with the index sends no DDL for the migration.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( 'status', 'created_at' ), $this->indexColumns(), 'The order tables\' own migration creates the index.' );

		$log = $this->captureQueries( fn() => ( new AddOrderStatusIndex() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the index' );
	}

	/**
	 * Tests the migration's place and what it declares: after the migration that creates the orders table, the table it declares, trading on while it runs, in the order module's contribution.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_sorts_after_the_orders_table_and_declares_the_orders(): void {
		$migration = new AddOrderStatusIndex();
		$ids       = array();

		foreach ( OwnedData::registry()->migrations() as $registered ) {
			$ids[] = $registered->id();
		}

		$this->assertContains( AddOrderStatusIndex::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( CreateOrderTables::ID, $migration->id(), 'The index migration sorts after the one that creates the table it changes.' );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'The store keeps trading while the index is added.' );
		$this->assertEquals( array( OrderTables::orders() ), $migration->tables() );
	}

	/**
	 * Reads the columns of the orders' `status_created` index from information_schema, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The columns; none when there is no such index.
	 */
	private function indexColumns(): array {
		return array_map(
			'strval',
			array_column(
				$this->db->fetchAll( "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'status_created' ORDER BY SEQ_IN_INDEX", $this->db->table( OrderTables::ORDERS ) ),
				'COLUMN_NAME'
			)
		);
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-01 12:00:00' ), $this->reporter() );
	}
}
