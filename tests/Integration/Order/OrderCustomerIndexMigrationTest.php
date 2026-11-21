<?php
/**
 * Tests the migration that adds the index of a customer's orders
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Checkout\Infrastructure\Migrations\AddSessionFieldValues;
use SEOCart\Customer\Infrastructure\Migrations\CreateCustomers;
use SEOCart\Order\Infrastructure\Migrations\AddOrderCustomerIndex;
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
 * A site whose orders were created before the index gets it, exactly as declared, with one statement and no row changed, and a site installed since sends no DDL for it.
 *
 * The statement the migration sent is printed, so a run on each server shows the DDL it got.
 *
 * Planted violation: in AddOrderCustomerIndex::up(), do nothing. The migrator's post-condition
 * then fails the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class OrderCustomerIndexMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the index is added to orders created without it, by one statement, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_index_is_added_to_orders_created_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the index was declared.
		$this->db->execute( 'ALTER TABLE %i DROP INDEX customer', $this->db->table( OrderTables::ORDERS ) );
		$this->assertSame( array(), $this->indexColumns(), 'The older site has no index.' );

		$chain  = array_merge( $before, array( new AddOrderCustomerIndex() ) );
		$report = null;
		$log    = $this->captureQueries(
			function () use ( $chain, &$report ): void {
				$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);
		$ddl    = $log->ofType( 'CREATE', 'ALTER', 'DROP' )->sqls();

		$this->assertNotNull( $report );
		$this->assertSame( array( AddOrderCustomerIndex::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddOrderCustomerIndex::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array( 'customer_id', 'id' ), $this->indexColumns() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::orders() ), 'orders matches its declaration exactly.' );
		$this->assertCount( 1, $ddl, 'The index is added by one statement: ' . implode( "\n", $ddl ) );

		fwrite( STDOUT, sprintf( "\nThe DDL AddOrderCustomerIndex sent on %s:\n%s\n", $this->db->server()->kind()->name, $ddl[0] ) );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );
	}

	/**
	 * Tests that a site installed with the index sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( 'customer_id', 'id' ), $this->indexColumns(), 'The order tables\' own migration creates the index.' );

		$log = $this->captureQueries( fn() => ( new AddOrderCustomerIndex() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the index' );
	}

	/**
	 * Tests the migration's place and what it declares: after the customer records' migration, the table it declares, trading on while it runs, in the order module's contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_after_the_customer_records_and_declares_the_orders(): void {
		$migration = new AddOrderCustomerIndex();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddOrderCustomerIndex::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( CreateCustomers::ID, $migration->id(), 'The index sorts after the records whose orders it lists.' );
		$this->assertLessThan( AddSessionFieldValues::ID, $migration->id(), 'The index sorts before the migrations added after it, so a release that has it has every migration before it.' );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'The store keeps trading while the index is added.' );
		$this->assertEquals( array( OrderTables::orders() ), $migration->tables() );
	}

	/**
	 * Reads the columns of the orders' `customer` index from information_schema, in order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The columns; none when there is no such index.
	 */
	private function indexColumns(): array {
		return array_map(
			'strval',
			array_column(
				$this->db->fetchAll( "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'customer' ORDER BY SEQ_IN_INDEX", $this->db->table( OrderTables::ORDERS ) ),
				'COLUMN_NAME'
			)
		);
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-11-14 12:00:00' ), $this->reporter() );
	}
}
