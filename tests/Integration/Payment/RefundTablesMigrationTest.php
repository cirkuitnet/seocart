<?php
/**
 * Tests the migration that creates the refund tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundTables;
use SEOCart\Payment\Infrastructure\RefundTables;
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
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The refund tables are created exactly as declared, after every migration before them, and a second run changes nothing.
 *
 * The keys a refund relies on exist in the database as declared: one document per ledger row
 * (`transaction_id`), a line once per refund, and a component once per refund.
 *
 * Planted violation: at the end of CreateRefundTables::up(), drop the `transaction_id` unique key
 * of `refunds` directly. The migrator's post-condition then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class RefundTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables(), new CreateRefundTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( CreateRefundTables::ID, array_column( $report->applied(), 'id' )[4] ?? null );
		$this->assertSame( CreateRefundTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( RefundTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$this->assertSame(
			array(
				'refund_components.refund_component' => 'refund_id,order_tax_component_id',
				'refund_lines.refund_line'           => 'refund_id,order_line_id',
				'refunds.transaction_id'             => 'transaction_id',
				'refunds.uuid'                       => 'uuid',
			),
			$this->uniqueKeys(),
			'The keys a refund relies on exist in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateRefundTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: three append-only tables of financial retention, after the order and payment tables they refer to, which the production registry lists.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_three_tables_after_those_it_refers_to(): void {
		$migration = new CreateRefundTables();

		$this->assertContains( CreateRefundTables::ID, array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() ) );
		$this->assertGreaterThan( max( CreateOrderTables::ID, CreatePaymentTables::ID ), $migration->id(), 'The refund tables\' migration sorts after the order and payment tables a refund refers to.' );
		$this->assertTrue( $migration->canOperateHalfApplied(), 'Nothing a customer does needs the refund tables.' );
		$this->assertEquals( RefundTables::all(), $migration->tables() );

		$shape = array();

		foreach ( RefundTables::all() as $table ) {
			$this->assertSame( 'Payment', $table->module() );

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention() );
		}

		$this->assertSame(
			array(
				'refunds'           => array( MutationPattern::AppendOnly, 'financial' ),
				'refund_lines'      => array( MutationPattern::AppendOnly, 'financial' ),
				'refund_components' => array( MutationPattern::AppendOnly, 'financial' ),
			),
			$shape
		);
		$this->assertSame( RefundTables::names(), array_keys( $shape ) );
	}

	/**
	 * Reads the refund tables' unique keys from information_schema, the primary keys aside.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Each key's columns in order, comma-separated, by `table.key`.
	 */
	private function uniqueKeys(): array {
		$keys = array();

		foreach ( RefundTables::names() as $name ) {
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-26 12:00:00' ), $this->reporter() );
	}
}
