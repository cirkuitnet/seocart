<?php
/**
 * Tests the migration that adds the reference to the order events
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\Migrations\AddOrderEventReference;
use SEOCart\Order\Infrastructure\Migrations\AddOrderStatusIndex;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddIntentMode;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundClaimTable;
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
 * A site whose order events were recorded before the reference gets it, exactly as declared, with every event it already has naming nothing; a site installed since sends no DDL for it.
 *
 * Planted violation: in AddOrderEventReference::up(), do nothing. The migrator's post-condition
 * then fails the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class OrderEventReferenceMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the column is added to events recorded without it, an existing event names nothing, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_reference_is_added_to_events_recorded_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );
		$table  = $this->db->table( OrderTables::EVENTS );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the reference was declared, with an order's first event on it.
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN reference', $table );
		$this->db->execute( "INSERT INTO %i SET order_id = 1, machine = 'order', from_status = '', to_status = 'pending_payment', reason = 'placed', actor_type = 'user', created_at = UTC_TIMESTAMP(6)", $table );
		$this->assertNotContains( 'reference', $this->columns(), 'The older site has no reference.' );

		$chain  = array_merge( $before, array( new AddOrderEventReference() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddOrderEventReference::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddOrderEventReference::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::events() ), 'order_events matches its declaration exactly.' );
		$this->assertNull( $this->db->fetchValue( 'SELECT reference FROM %i', $table ), 'An event recorded before the reference names nothing.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddOrderEventReference() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the table that has the reference' );
	}

	/**
	 * Tests that a site installed with the reference sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'reference', $this->columns(), 'The order tables\' own migration creates the reference.' );

		$log = $this->captureQueries( fn() => ( new AddOrderEventReference() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the reference' );
	}

	/**
	 * Tests the migration's place and what it declares: after every migration before it, the events in their end state, the store not trading while it is outstanding, in the order module's contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_events(): void {
		$migration = new AddOrderEventReference();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddOrderEventReference::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( max( AddIntentMode::ID, CreateRefundClaimTable::ID, AddOrderStatusIndex::ID ), $migration->id(), 'The migration sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'A refund\'s event names the column, so the store waits for it.' );
		$this->assertEquals( array( OrderTables::events() ), $migration->tables() );
	}

	/**
	 * Reads the columns of the events table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( OrderTables::EVENTS ) ), 'COLUMN_NAME' ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-04 12:00:00' ), $this->reporter() );
	}
}
