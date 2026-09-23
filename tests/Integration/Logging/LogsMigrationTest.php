<?php
/**
 * Tests the migration that creates the log table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

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
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The log table is created exactly as declared, after the bootstrap, and a second run changes nothing.
 *
 * Planted violation: at the end of CreateLogsMigration::up(), drop the `correlation_id` index
 * (`$operations->alter( 'logs', 'DROP INDEX correlation_id' )`); the migrator's post-condition
 * then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class LogsMigrationTest extends DatabaseTestCase {

	/**
	 * The migration applies after the bootstrap, the table matches its declaration, and re-running sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_table_is_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateLogsMigration() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateLogsMigration::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( LogsTable::definition() ), 'The table matches its declaration exactly.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing table' );
	}

	/**
	 * The migration sorts after the bootstrap, declares the one table, and leaves writes open while it is pending.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_its_place_and_its_table(): void {
		$migration = new CreateLogsMigration();

		$this->assertGreaterThan( PlatformBootstrapMigration::ID, $migration->id() );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'The store trades without its log.' );
		$this->assertEquals( array( LogsTable::definition() ), $migration->tables() );
		$this->assertSame( 'logs', LogsTable::definition()->retention() );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-23 12:00:00' ), $this->reporter() );
	}
}
