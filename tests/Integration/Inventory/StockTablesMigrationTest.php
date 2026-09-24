<?php
/**
 * Tests the migration that creates the four stock tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The stock tables are created exactly as declared, after the platform's, and a second run changes nothing.
 *
 * Planted violation: at the end of CreateStockTablesMigration::up(), drop the `variant_expires`
 * index of `stock_holds` directly, so the table no longer has an index its declaration names.
 * The migrator's post-condition then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class StockTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateStockTablesMigration() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateOutboxMigration::ID, CreateStockTablesMigration::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateStockTablesMigration::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( InventoryTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateStockTablesMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: four tables, their patterns, retention and money columns.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_four_tables(): void {
		$migration = new CreateStockTablesMigration();

		$this->assertGreaterThan( CreateOutboxMigration::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( InventoryTables::all(), $migration->tables() );

		$shape = array();

		foreach ( InventoryTables::all() as $table ) {
			$financial = array();

			foreach ( $table->columns() as $column ) {
				if ( Classification::Financial === $column->classification() ) {
					$financial[] = $column->name();
				}
			}

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention(), $financial );
		}

		$this->assertSame(
			array(
				'stock_items'       => array( MutationPattern::MutableTransactional, 'entity_lifetime', array( 'on_hand', 'allocated', 'held' ) ),
				'stock_ledger'      => array( MutationPattern::AppendOnly, 'permanent', array( 'delta', 'on_hand_after' ) ),
				'stock_holds'       => array( MutationPattern::MutableTransactional, 'stock_holds', array() ),
				'stock_allocations' => array( MutationPattern::MutableTransactional, 'entity_lifetime', array( 'quantity', 'posted_quantity' ) ),
			),
			$shape
		);

		$updatedAt = null;

		foreach ( InventoryTables::items()->columns() as $column ) {
			if ( 'updated_at' === $column->name() ) {
				$updatedAt = $column->type();
			}
		}

		$this->assertSame( 'datetime(6)', $updatedAt, 'A conditional update always changes updated_at, so one affected row means the WHERE matched.' );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-24 12:00:00' ), $this->reporter() );
	}
}
