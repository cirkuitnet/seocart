<?php
/**
 * Tests the migration that creates the payment tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\PaymentTables;
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
 * The payment tables are created exactly as declared, after the order tables, and a second run changes nothing.
 *
 * The keys that carry the money path's rules exist in the database as declared: the ledger's
 * `provider_object_operation`, which applies a gateway result at most once, and the intents'
 * `uuid` and `gateway_provider_intent`.
 *
 * Planted violation: at the end of CreatePaymentTables::up(), drop the `provider_object_operation`
 * unique key of `payment_transactions` directly. The migrator's post-condition then fails the
 * migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class PaymentTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateOutboxMigration::ID, CreateOrderTables::ID, CreatePaymentTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreatePaymentTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( PaymentTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$this->assertSame(
			array(
				'payment_intents.gateway_provider_intent' => 'gateway_id,provider_intent_id',
				'payment_intents.uuid'                    => 'uuid',
				'payment_transactions.provider_object_operation' => 'provider,provider_object_id,operation',
				'payment_transactions.uuid'               => 'uuid',
			),
			$this->uniqueKeys(),
			'The keys the money path relies on exist in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreatePaymentTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place and what it declares: two tables, their patterns and retention, and that the production registry lists them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_declares_the_two_tables(): void {
		$migration = new CreatePaymentTables();

		$this->assertGreaterThan( CreateOrderTables::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( PaymentTables::all(), $migration->tables() );

		$shape = array();

		foreach ( PaymentTables::all() as $table ) {
			$this->assertSame( 'Payment', $table->module() );

			$shape[ $table->name() ] = array( $table->mutationPattern(), $table->retention() );
		}

		$this->assertSame(
			array(
				'payment_intents'      => array( MutationPattern::MutableTransactional, 'financial' ),
				'payment_transactions' => array( MutationPattern::AppendOnly, 'financial' ),
			),
			$shape
		);
		$this->assertSame( PaymentTables::names(), array_keys( $shape ) );

		$registered = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Payment' === $table->module() ) {
				$registered[] = $table->name();
			}
		}

		$this->assertSame( PaymentTables::names(), $registered, 'The production registry lists exactly the payment tables.' );
	}

	/**
	 * Reads the payment tables' unique keys from information_schema, the primary keys aside.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> Each key's columns in order, comma-separated, by `table.key`.
	 */
	private function uniqueKeys(): array {
		$keys = array();

		foreach ( PaymentTables::names() as $name ) {
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
