<?php
/**
 * Tests the migration that records a transfer's promised days on orders and indexes their fulfilment location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\Migrations\AddFulfilmentLocation;
use SEOCart\Order\Infrastructure\Migrations\AddOrderRoutingSnapshot;
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

defined( 'ABSPATH' ) || exit;

/**
 * A site whose orders were created before the step gets the column and the index once, exactly as declared; a site installed since sends no DDL.
 *
 * Planted violation: in AddOrderRoutingSnapshot::up(), do nothing. The migrator's post-condition
 * then fails the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class OrderRoutingMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the column and the index are added to a site without them, the head moves to the step, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_column_and_the_index_are_added_once_to_an_older_site(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new AddFulfilmentLocation() );
		$table  = $this->db->table( OrderTables::ORDERS );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the step was declared.
		$this->db->execute( 'ALTER TABLE %i DROP INDEX fulfilment_location, DROP COLUMN transfer_lead_days', $table );
		$this->assertNotContains( 'transfer_lead_days', $this->columns(), 'The older site has no column.' );
		$this->assertNotContains( 'fulfilment_location', $this->indexes(), 'The older site has no index.' );

		$chain  = array_merge( $before, array( new AddOrderRoutingSnapshot() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddOrderRoutingSnapshot::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddOrderRoutingSnapshot::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::orders() ), 'orders matches its declaration exactly.' );
		$this->assertContains( 'fulfilment_location', $this->indexes() );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddOrderRoutingSnapshot() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the table that has both' );
	}

	/**
	 * Tests that a site installed since has both from the order tables' own migration, and the step sends no DDL there.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'transfer_lead_days', $this->columns(), 'The order tables\' own migration creates the column.' );

		$log = $this->captureQueries( fn() => ( new AddOrderRoutingSnapshot() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the column and the index' );
	}

	/**
	 * Tests that the step is registered, sorts last, keeps the store from trading while outstanding, and declares `orders`.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_sorts_last_and_holds_the_store_until_done(): void {
		$migration = new AddOrderRoutingSnapshot();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddOrderRoutingSnapshot::ID, $ids, 'The registry runs the step.' );
		$this->assertGreaterThan( max( array_filter( $ids, static fn( string $id ): bool => $id < '20261031' ) ), $migration->id(), 'The step sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertGreaterThan( array_search( AddFulfilmentLocation::ID, $ids, true ), array_search( AddOrderRoutingSnapshot::ID, $ids, true ), 'The step is registered after the step that added the fulfilment location.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'An order\'s placement names the column, so the store waits for it.' );
		$this->assertEquals( array( OrderTables::orders() ), $migration->tables() );
	}

	/**
	 * Returns the columns of `orders`.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( OrderTables::ORDERS ) ), 'COLUMN_NAME' ) );
	}

	/**
	 * Returns the indexes of `orders`.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The names.
	 */
	private function indexes(): array {
		return array_values( array_unique( array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( OrderTables::ORDERS ) ), 'INDEX_NAME' ) ) ) );
	}

	/**
	 * Returns a migrator over the test's database and the migrations given.
	 *
	 * @since 0.2.0
	 *
	 * @param array $migrations The migrations, in order.
	 * @return Migrator The migrator.
	 *
	 * @phpstan-param list<Migration> $migrations
	 */
	private function migrator( array $migrations ): Migrator {
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-31 12:00:00' ), $this->reporter() );
	}
}
