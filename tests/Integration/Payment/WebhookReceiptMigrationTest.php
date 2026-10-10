<?php
/**
 * Tests the migration that creates the webhook receipts
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimRequest;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimSettlement;
use SEOCart\Payment\Infrastructure\Migrations\CreateWebhookReceipts;
use SEOCart\Payment\Infrastructure\WebhookReceiptTables;
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
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The webhook receipts are created exactly as declared, and a second run changes nothing.
 *
 * The keys a receipt relies on exist in the database as declared: one receipt per event of a
 * gateway's address, and the prune's and doctor's keys.
 *
 * Planted violation: at the end of CreateWebhookReceipts::up(), drop the `provider_mode_event`
 * unique key of `webhook_receipts` directly. The migrator's post-condition then fails the
 * migration.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class WebhookReceiptMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the table is created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_table_is_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateWebhookReceipts() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( CreateWebhookReceipts::ID, array_column( $report->applied(), 'id' )[1] ?? null );
		$this->assertSame( CreateWebhookReceipts::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( WebhookReceiptTables::receipts() ), 'webhook_receipts matches its declaration exactly.' );

		$keys = $this->db->fetchAll(
			"SELECT INDEX_NAME, NON_UNIQUE, GROUP_CONCAT( COLUMN_NAME ORDER BY SEQ_IN_INDEX ) AS columns_in_order FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME, NON_UNIQUE ORDER BY INDEX_NAME",
			$this->db->table( WebhookReceiptTables::RECEIPTS )
		);

		$this->assertSame(
			array(
				array(
					'INDEX_NAME'       => 'expires_at',
					'NON_UNIQUE'       => '1',
					'columns_in_order' => 'expires_at',
				),
				array(
					'INDEX_NAME'       => 'provider_mode_event',
					'NON_UNIQUE'       => '0',
					'columns_in_order' => 'provider,mode,event_id',
				),
				array(
					'INDEX_NAME'       => 'result_received',
					'NON_UNIQUE'       => '1',
					'columns_in_order' => 'result,received_at',
				),
			),
			$keys,
			'The keys a delivery, the prune and doctor rely on exist in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateWebhookReceipts() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing table' );
	}

	/**
	 * Tests the migration's place and what it declares: one mutable table under its own retention policy, after every migration before it, which the production registry lists, and which the store cannot trade without.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_declares_the_receipts_after_every_migration_before_it(): void {
		$migration = new CreateWebhookReceipts();
		$table     = WebhookReceiptTables::receipts();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( CreateWebhookReceipts::ID, $ids );
		$this->assertGreaterThan( AddRefundClaimRequest::ID, $migration->id(), 'The receipts\' migration sorts after the refund claim\'s request.' );
		$this->assertGreaterThan( AddRefundClaimSettlement::ID, $migration->id(), 'The receipts\' migration sorts after every migration that was there when it was written: an upgrade applies them first.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'A delivery about a payment the store holds is recorded in the table before its result is applied.' );
		$this->assertEquals( WebhookReceiptTables::all(), $migration->tables() );
		$this->assertSame( array( 'Payment', MutationPattern::MutableTransactional, WebhookReceiptTables::RETENTION ), array( $table->module(), $table->mutationPattern(), $table->retention() ) );
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
