<?php
/**
 * Tests the migration that adds the mode to the payment intents
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\AddOrderStatusIndex;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddIntentMode;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\CreateRefundClaimTable;
use SEOCart\Payment\Infrastructure\PaymentTables;
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
 * A site whose intents were created before the mode gets it, exactly as declared, with every intent it already has in test mode, which is all the stand-in ever made; a site installed since sends no DDL for it.
 *
 * Planted violation: in AddIntentMode::up(), do nothing. The migrator's post-condition then fails
 * the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class IntentModeMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the column is added to intents created without it, the existing intent is in test mode, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_mode_is_added_to_intents_created_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables() );
		$table  = $this->db->table( PaymentTables::INTENTS );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the mode was declared, with an intent of the stand-in on it.
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN mode', $table );
		$this->db->execute(
			"INSERT INTO %i SET uuid = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', order_id = 1, gateway_id = 'stub', status = 'authorized', amount_minor = 3080, currency = 'EUR', conversion_context_id = 1, "
				. "base_currency = 'USD', base_amount_minor = 2464, created_at = UTC_TIMESTAMP(6), updated_at = UTC_TIMESTAMP(6)",
			$table
		);
		$this->assertNotContains( 'mode', $this->columns(), 'The older site has no mode.' );

		$chain  = array_merge( $before, array( new AddIntentMode() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddIntentMode::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddIntentMode::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( PaymentTables::intents() ), 'payment_intents matches its declaration exactly.' );
		$this->assertSame( 'test', $this->db->fetchValue( 'SELECT mode FROM %i', $table ), 'An intent made before the mode is a test one.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddIntentMode() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the table that has the mode' );
	}

	/**
	 * Tests that a site installed with the mode sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'mode', $this->columns(), 'The payment tables\' own migration creates the mode.' );

		$log = $this->captureQueries( fn() => ( new AddIntentMode() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the mode' );
	}

	/**
	 * Tests the migration's place and what it declares: after every migration before it, the intents in their end state, the store not trading while it is outstanding, in the payment module's contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_intents(): void {
		$migration = new AddIntentMode();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddIntentMode::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( max( CreatePaymentTables::ID, CreateRefundClaimTable::ID, AddOrderStatusIndex::ID ), $migration->id(), 'The migration sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'The store does not trade while the mode is added: the payment statements name it.' );
		$this->assertEquals( array( PaymentTables::intents() ), $migration->tables() );
	}

	/**
	 * Reads the columns of the intents table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( PaymentTables::INTENTS ) ), 'COLUMN_NAME' ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-03 12:00:00' ), $this->reporter() );
	}
}
