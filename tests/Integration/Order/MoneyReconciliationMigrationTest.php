<?php
/**
 * Tests the migration that adds to the orders when a person last cleared their unreconciled money, and why
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Infrastructure\Migrations\AddMoneyReconciliation;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimRequest;
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
 * A site whose orders were placed before the reconciliation columns were declared gets them, exactly as declared, with every order it already has never cleared; a site installed since sends no DDL for them.
 *
 * Planted violation, shown red and removed: in AddMoneyReconciliation::up(), do nothing. The
 * migrator's post-condition then fails the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class MoneyReconciliationMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the columns are added to orders placed without them, an existing order was never cleared and keeps its flag, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_columns_are_added_to_orders_placed_without_them(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() );
		$table  = $this->db->table( OrderTables::ORDERS );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the columns were declared, with a flagged order on it.
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN money_reconciled_at, DROP COLUMN money_reconciliation_note', $table );
		$this->db->execute(
			"INSERT INTO %i SET uuid = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', order_number = '1001', channel = 'storefront', actor_type = 'user', status = 'on_hold', currency = 'EUR', base_currency = 'EUR', "
				. "conversion_context_id = 1, locale = 'en_US', email = 'ada@example.com', cross_zone_policy_snapshot = 'fixed_gross', tax_rounding_mode_snapshot = 'per_line', has_unreconciled_money = 1, "
				. 'placed_at = UTC_TIMESTAMP(), created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)',
			$table
		);
		$this->assertNotContains( 'money_reconciled_at', $this->columns(), 'The older site has no clearance.' );

		$chain  = array_merge( $before, array( new AddMoneyReconciliation() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddMoneyReconciliation::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddMoneyReconciliation::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( OrderTables::orders() ), 'orders matches its declaration exactly.' );
		$this->assertSame(
			array(
				'has_unreconciled_money'    => '1',
				'money_reconciled_at'       => null,
				'money_reconciliation_note' => null,
			),
			(array) $this->db->fetchRow( 'SELECT has_unreconciled_money, money_reconciled_at, money_reconciliation_note FROM %i', $table ),
			'An order placed before the columns was never cleared, and keeps its flag.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddMoneyReconciliation() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the table that has the columns' );
	}

	/**
	 * Tests that a site installed with the columns sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'money_reconciled_at', $this->columns(), 'The order tables\' own migration creates the columns.' );

		$log = $this->captureQueries( fn() => ( new AddMoneyReconciliation() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the columns' );
	}

	/**
	 * Tests the migration's place and what it declares: after every migration on the branch it starts from, the orders in their end state, the store not trading while it is outstanding, in the order module's contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_orders(): void {
		$migration = new AddMoneyReconciliation();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddMoneyReconciliation::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( AddRefundClaimRequest::ID, $migration->id(), 'The migration sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'Every refund reads the clearance, so the store waits for it.' );
		$this->assertEquals( array( OrderTables::orders() ), $migration->tables() );
	}

	/**
	 * Reads the columns of the orders table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( OrderTables::ORDERS ) ), 'COLUMN_NAME' ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-09 12:00:00' ), $this->reporter() );
	}
}
