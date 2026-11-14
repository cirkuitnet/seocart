<?php
/**
 * Tests the migration of the customer records, and what the table declares
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Customer\Infrastructure\Migrations\CreateCustomers;
use SEOCart\Payment\Infrastructure\Migrations\CreateOfflinePayments;
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
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The customer records are created exactly as declared, by a migration the store cannot trade without, and a second run changes nothing.
 *
 * The statement the migration sent is printed, so a run on each server shows the DDL it got.
 *
 * Planted violation: at the end of CreateCustomers::up(), drop the `user` unique key directly, so
 * the table no longer has a key its declaration names. The migrator's post-condition then fails
 * the migration.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class CustomerTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the table is created as declared, with one statement, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_table_is_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateCustomers() );
		$report = null;
		$log    = $this->captureQueries(
			function () use ( $chain, &$report ): void {
				$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);
		$table  = $this->db->table( CustomerTables::CUSTOMERS );
		$create = array_values( array_filter( $log->sqls(), static fn( string $sql ): bool => str_contains( $sql, 'CREATE TABLE' ) && str_contains( $sql, $table ) ) );

		$this->assertNotNull( $report );
		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateCustomers::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateCustomers::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( CustomerTables::customers() ), 'customers matches its declaration exactly.' );
		$this->assertCount( 1, $create, 'The table is created by one statement.' );

		fwrite( STDOUT, sprintf( "\nThe DDL CreateCustomers sent on %s:\n%s\n", $this->db->server()->kind()->name, $create[0] ) );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateCustomers() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing table' );
	}

	/**
	 * Tests the migration's place, after the latest migration before it, that the store cannot trade without it, and that the production registry lists the table in the customer module.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_the_registry_lists_the_table(): void {
		$migration = new CreateCustomers();

		$this->assertGreaterThan( CreateOfflinePayments::ID, $migration->id(), 'The migration sorts after the latest one before it.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'An order placed while logged in writes the table.' );
		$this->assertEquals( CustomerTables::all(), $migration->tables() );

		$registered = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Customer' === $table->module() ) {
				$registered[] = $table->name();
			}
		}

		$ids = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertSame( array( CustomerTables::CUSTOMERS ), $registered );
		$this->assertContains( CreateCustomers::ID, $ids, 'The registry runs the migration.' );
	}

	/**
	 * Tests what the table declares: the link as personal data destroyed on erasure, everything else public, its pattern, its retention, and its two unique keys.
	 *
	 * @since 0.2.0
	 */
	public function test_the_link_is_personal_data_and_nothing_else_is(): void {
		$customers = CustomerTables::customers();
		$classes   = array();
		$erasure   = array();

		foreach ( $customers->columns() as $column ) {
			$classes[ $column->name() ] = $column->classification();

			if ( Classification::Pii === $column->classification() ) {
				$erasure[ $column->name() ] = $column->erasure();
			}
		}

		$this->assertSame(
			array(
				'id'                 => Classification::Public,
				'uuid'               => Classification::Public,
				'user_id'            => Classification::Pii,
				'user_registered_at' => Classification::Pii,
				'created_at'         => Classification::Public,
				'updated_at'         => Classification::Public,
			),
			$classes,
			'The table holds the link and two timestamps, and no column more.'
		);
		$this->assertSame(
			array(
				'user_id'            => ColumnSpec::ERASE_DESTROY,
				'user_registered_at' => ColumnSpec::ERASE_DESTROY,
			),
			$erasure
		);
		$this->assertSame( array( MutationPattern::MutableTransactional, 'entity_lifetime' ), array( $customers->mutationPattern(), $customers->retention() ) );
		$this->assertSame( array( 'users -> customers', 'customers -> orders' ), array_keys( $customers->orphanPolicy() ) );

		$unique = array();

		foreach ( $customers->uniqueKeys() as $key ) {
			$unique[ $key->name() ] = array_column( $key->columns(), 'name' );
		}

		$this->assertSame(
			array(
				'uuid' => array( 'uuid' ),
				'user' => array( 'user_id' ),
			),
			$unique
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
