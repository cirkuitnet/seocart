<?php
/**
 * Tests the migration that creates the refund claims
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\AddOrderStatusIndex;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundClaimTable;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The refund claims are created exactly as declared, on a site whose refund tables exist already, and a second run changes nothing.
 *
 * The key a claim relies on exists in the database as declared: one claim per refund uuid.
 *
 * Planted violation: at the end of CreateRefundClaimTable::up(), drop the `uuid` unique key of
 * `refund_claims` directly. The migrator's post-condition then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class RefundClaimTableMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the table is created as declared after the refund tables, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_table_is_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables(), new CreateRefundTables(), new CreateRefundClaimTable() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( CreateRefundClaimTable::ID, array_column( $report->applied(), 'id' )[5] ?? null );
		$this->assertSame( CreateRefundClaimTable::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( RefundClaimTables::claims() ), 'refund_claims matches its declaration exactly.' );

		$unique = $this->db->fetchAll(
			"SELECT INDEX_NAME, GROUP_CONCAT( COLUMN_NAME ORDER BY SEQ_IN_INDEX ) AS columns_in_order FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME ORDER BY INDEX_NAME",
			$this->db->table( RefundClaimTables::CLAIMS )
		);

		$this->assertSame(
			array(
				array(
					'INDEX_NAME'       => 'key_hash',
					'columns_in_order' => 'key_hash',
				),
				array(
					'INDEX_NAME'       => 'uuid',
					'columns_in_order' => 'uuid',
				),
			),
			$unique,
			'The keys a claim and a retry rely on exist in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateRefundClaimTable() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing table' );
	}

	/**
	 * Tests the migration's place and what it declares: one mutable table of financial retention, after every migration on main before it, which the production registry lists.
	 *
	 * Settled claims are kept with the refund they asked for, for as long as the order is, so the
	 * table's retention is the order's money's, and nothing prunes it; who asked is kept, as on a
	 * refund, once the user is erased.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_claims_after_the_refund_tables(): void {
		$migration = new CreateRefundClaimTable();
		$table     = RefundClaimTables::claims();

		$this->assertContains( CreateRefundClaimTable::ID, array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() ) );
		$this->assertGreaterThan( max( CreateRefundTables::ID, AddOrderStatusIndex::ID ), $migration->id(), 'The claims\' migration sorts after the refund tables and every migration before it.' );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'Nothing a customer does needs the refund claims.' );
		$this->assertEquals( RefundClaimTables::all(), $migration->tables() );
		$this->assertSame( array( 'Payment', MutationPattern::MutableTransactional, 'financial' ), array( $table->module(), $table->mutationPattern(), $table->retention() ) );

		$actor = null;

		foreach ( $table->columns() as $column ) {
			if ( 'actor_id' === $column->name() ) {
				$actor = $column;
			}
		}

		$this->assertNotNull( $actor );
		$this->assertSame( ColumnSpec::ERASE_RETAIN, $actor->erasure(), 'Who asked for a refund is kept with it, as on the refund itself.' );
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
