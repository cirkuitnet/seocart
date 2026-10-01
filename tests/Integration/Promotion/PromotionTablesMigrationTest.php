<?php
/**
 * Tests the promotion tables' migration: created as declared, after the platform's, and idempotent
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Promotion;

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
use SEOCart\Platform\RateLimiter\Migrations\CreateRateCountersMigration;
use SEOCart\Promotion\Infrastructure\Migrations\CreatePromotionTables;
use SEOCart\Promotion\Infrastructure\PromotionTables;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The promotion tables are created exactly as declared, after the platform's, and a second run changes nothing.
 *
 * Planted violation, shown red and removed: at the end of CreatePromotionTables::up(), drop the
 * `code` unique key of `promotions` directly, so the table no longer has a key its declaration
 * names. The migrator's post-condition then fails the migration.
 *
 * @group migration
 *
 * @since 0.1.0
 */
final class PromotionTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreatePromotionTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreatePromotionTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreatePromotionTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( PromotionTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreatePromotionTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: three tables, their patterns, retention, money and privacy columns.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_three_tables(): void {
		$migration = new CreatePromotionTables();

		$this->assertGreaterThan( CreateRateCountersMigration::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( PromotionTables::all(), $migration->tables() );

		$shape = array();

		foreach ( PromotionTables::all() as $table ) {
			$classified = array();

			foreach ( $table->columns() as $column ) {
				if ( Classification::Public !== $column->classification() ) {
					$classified[] = $column->name() . ':' . $column->classification()->value;
				}
			}

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention(), $classified );
		}

		$this->assertSame(
			array(
				'promotions'           => array( MutationPattern::MutableTransactional, 'permanent', array( 'effect_amount_minor:financial', 'threshold_amount_minor:financial', 'used:financial' ) ),
				'promotion_conditions' => array( MutationPattern::Config, 'entity_lifetime', array() ),
				'promotion_usage'      => array( MutationPattern::MutableTransactional, 'financial', array( 'customer_id:pii', 'cart_token_hash:secret', 'amount_minor:financial', 'base_amount_minor:financial' ) ),
			),
			$shape
		);

		$types = array();

		foreach ( PromotionTables::promotions()->columns() as $column ) {
			$types[ $column->name() ] = $column;
		}

		$this->assertSame( 'datetime(6)', $types['updated_at']->type(), 'A conditional update always changes updated_at, so one affected row means the WHERE matched.' );
		$this->assertSame( 'int', $types['used']->type(), 'Signed, so a count driven below zero is visible to doctor rather than an error.' );
		$this->assertInstanceOf( ColumnSpec::class, $types['code'] );
		$this->assertSame( 'utf8mb4_bin', $types['code']->collation(), 'A code is compared exactly as stored.' );
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
