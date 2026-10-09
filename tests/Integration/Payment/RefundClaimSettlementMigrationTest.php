<?php
/**
 * Tests the migration that adds to the refund claims how a person settled one
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\AddMoneyReconciliation;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimSettlement;
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
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * A site whose refund claims were made before a person could settle one gets the four columns, exactly as declared; every claim it already has keeps how it ended, settled by no person; a site installed since sends no DDL.
 *
 * Planted violation, shown red and removed: in AddRefundClaimSettlement::up(), do nothing. The
 * migrator's post-condition then fails the migration on the older site.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class RefundClaimSettlementMigrationTest extends DatabaseTestCase {

	/**
	 * The migrations a site whose refund claims predate the settlement has run.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Migration> The chain.
	 */
	private static function before(): array {
		return array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables(), new CreateRefundTables(), new CreateRefundClaimTable() );
	}

	/**
	 * Tests that the columns are added to a site whose claims were made without them, the existing claim keeps how it ended, the head moves, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_settlement_is_added_to_claims_made_without_it(): void {
		$claims = $this->db->table( RefundClaimTables::CLAIMS );

		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the settlement was declared, with a refund claimed and ended on it.
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN statement, DROP COLUMN gateway_reading, DROP COLUMN settled_by, DROP COLUMN settlement_note', $claims );
		$this->db->execute(
			"INSERT INTO %i SET uuid = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', intent_id = 1, order_id = 1, state = 'recorded', amount_minor = 1234, currency = 'EUR', base_amount_minor = 1234, base_currency = 'EUR', "
				. "reason_code = 'customer_return', actor_type = 'user', actor_id = 7, transaction_id = 3, created_at = UTC_TIMESTAMP(6), settled_at = UTC_TIMESTAMP(6)",
			$claims
		);
		$this->assertNotContains( 'statement', $this->columns(), 'The older site has no settlement.' );

		$chain  = array_merge( self::before(), array( new AddRefundClaimSettlement() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddRefundClaimSettlement::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddRefundClaimSettlement::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( RefundClaimTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), $table->name() . ' matches its declaration exactly.' );
		}

		$this->assertSame(
			array(
				'state'           => 'recorded',
				'statement'       => null,
				'gateway_reading' => null,
				'settled_by'      => null,
				'settlement_note' => null,
			),
			(array) $this->db->fetchRow( 'SELECT state, statement, gateway_reading, settled_by, settlement_note FROM %i', $claims ),
			'A claim ended before the settlement keeps how it ended, settled by no person.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddRefundClaimSettlement() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the claims that have the settlement' );
	}

	/**
	 * Tests that a site installed with the settlement sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'settlement_note', $this->columns(), 'The claims\' own migration creates the settlement.' );

		$log = $this->captureQueries( fn() => ( new AddRefundClaimSettlement() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the settlement' );
	}

	/**
	 * Tests the migration's place and what it declares: after the order module's migration of the same day, so a site with the settlement has the clearance too; the three claim tables in their end state; the store not trading while it is outstanding; in the refund claims' contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_claims(): void {
		$migration = new AddRefundClaimSettlement();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddRefundClaimSettlement::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( AddMoneyReconciliation::ID, $migration->id(), 'The migration sorts after every migration before it, the order module\'s of the same day included.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'Settling a claim writes the new columns, so the store waits for them.' );
		$this->assertEquals( RefundClaimTables::all(), $migration->tables() );
	}

	/**
	 * Reads the columns of the claims table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( RefundClaimTables::CLAIMS ) ), 'COLUMN_NAME' ) );
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
