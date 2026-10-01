<?php
/**
 * Tests the migration that creates the currencies and exchange-rate tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Pricing;

use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
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
use SEOCart\Pricing\Infrastructure\Migrations\CreateRateTables;
use SEOCart\Pricing\Infrastructure\PricingTables;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The pricing tables are created exactly as declared, and a second run changes nothing.
 *
 * The key the exchange rates rely on exists in the database as declared: `pair_version`, one rate
 * per currency pair in each version, which makes a version's rates a set and lets two saves of the
 * same version meet.
 *
 * Planted violation, shown red and removed: at the end of CreateRateTables::up(), drop the
 * `pair_version` unique key of `exchange_rates` directly. The migrator's post-condition then fails
 * the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class RateTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateRateTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateRateTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateRateTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( PricingTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$this->assertSame(
			array( 'exchange_rates.pair_version' => 'base_currency,quote_currency,version' ),
			$this->uniqueKeys(),
			'The key the exchange rates rely on exists in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateRateTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: two tables, their patterns and retention, and that the production registry lists them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_two_tables(): void {
		$migration = new CreateRateTables();

		$this->assertGreaterThan( CreatePaymentTables::ID, $migration->id() );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'A store trades in its base currency while the tables are pending.' );
		$this->assertEquals( PricingTables::all(), $migration->tables() );

		$shape = array();

		foreach ( PricingTables::all() as $table ) {
			$this->assertSame( 'Pricing', $table->module() );

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention() );
		}

		$this->assertSame(
			array(
				'currencies'     => array( MutationPattern::Config, 'permanent' ),
				'exchange_rates' => array( MutationPattern::AppendOnly, 'permanent' ),
			),
			$shape
		);
		$this->assertSame( PricingTables::names(), array_keys( $shape ) );

		$registered = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Pricing' === $table->module() ) {
				$registered[] = $table->name();
			}
		}

		$this->assertSame( PricingTables::names(), $registered, 'The production registry lists exactly the pricing tables.' );
	}

	/**
	 * Reads the pricing tables' unique keys from information_schema, the primary keys aside.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Each key's columns in order, comma-separated, by `table.key`.
	 */
	private function uniqueKeys(): array {
		$keys = array();

		foreach ( PricingTables::names() as $name ) {
			$rows = $this->db->fetchAll(
				"SELECT INDEX_NAME, GROUP_CONCAT( COLUMN_NAME ORDER BY SEQ_IN_INDEX ) AS columns_in_order FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME ORDER BY INDEX_NAME",
				$this->db->table( $name )
			);

			foreach ( $rows as $row ) {
				$keys[ $name . '.' . $row['INDEX_NAME'] ] = (string) $row['columns_in_order'];
			}
		}

		ksort( $keys );

		return $keys;
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
