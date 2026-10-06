<?php
/**
 * Tests the migration that adds the request and the key to the refund claims, with their lines and the actor locks
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\Migrations\AddOrderEventReference;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimRequest;
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
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Migrations\CreateOutboxMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * A site whose refund claims were made before the request and the key were declared gets them, exactly as declared, with the two tables beside the claims; every claim it already has keeps its state, with no key; a site installed since sends no DDL.
 *
 * The key a retry relies on exists in the database as declared: one refund per key hash.
 *
 * Planted violations, each shown red and removed:
 * - in AddRefundClaimRequest::up(), do nothing: the migrator's post-condition fails the migration
 *   on the older site;
 * - at the end of AddRefundClaimRequest::up(), drop the `key_hash` unique key of `refund_claims`
 *   directly: the post-condition fails it the same way.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class RefundClaimRequestMigrationTest extends DatabaseTestCase {

	/**
	 * The migrations a site whose refund claims predate the request has run.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Migration> The chain.
	 */
	private static function before(): array {
		return array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables(), new CreateRefundTables(), new CreateRefundClaimTable() );
	}

	/**
	 * Tests that the columns, the index and the two tables are added to a site whose claims were made without them, the existing claim keeps its state, the head moves, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_request_and_the_key_are_added_to_claims_made_without_them(): void {
		$claims = $this->db->table( RefundClaimTables::CLAIMS );

		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the request was declared, with a refund claimed on it.
		$this->db->execute( 'DROP TABLE %i, %i', $this->db->table( RefundClaimTables::CLAIM_LINES ), $this->db->table( RefundClaimTables::ACTOR_LOCKS ) );
		$this->db->execute(
			'ALTER TABLE %i DROP INDEX actor_created, DROP COLUMN key_hash, DROP COLUMN request_fingerprint, DROP COLUMN base_amount_minor, DROP COLUMN base_currency, DROP COLUMN shipping, DROP COLUMN reason_code, DROP COLUMN note',
			$claims
		);
		$this->db->execute(
			"INSERT INTO %i SET uuid = '01928c3e-7b3c-7d1e-9a2b-3c4d5e6f7a8c', intent_id = 1, order_id = 1, state = 'recorded', amount_minor = 1234, currency = 'EUR', actor_type = 'user', actor_id = 7, transaction_id = 3, "
				. 'created_at = UTC_TIMESTAMP(6), settled_at = UTC_TIMESTAMP(6)',
			$claims
		);
		$this->assertNotContains( 'key_hash', $this->columns(), 'The older site has no key.' );

		$chain  = array_merge( self::before(), array( new AddRefundClaimRequest() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddRefundClaimRequest::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddRefundClaimRequest::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( RefundClaimTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), $table->name() . ' matches its declaration exactly.' );
		}

		$this->assertSame(
			array(
				'state'    => 'recorded',
				'key_hash' => null,
				'note'     => null,
			),
			(array) $this->db->fetchRow( 'SELECT state, key_hash, note FROM %i', $claims ),
			'A claim made before the key keeps how it ended, with no key.'
		);
		$this->assertSame(
			array(
				'key_hash' => 'key_hash',
				'uuid'     => 'uuid',
			),
			array_column(
				$this->db->fetchAll( "SELECT INDEX_NAME, GROUP_CONCAT( COLUMN_NAME ORDER BY SEQ_IN_INDEX ) AS columns_in_order FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY' GROUP BY INDEX_NAME ORDER BY INDEX_NAME", $claims ),
				'columns_in_order',
				'INDEX_NAME'
			),
			'The keys a retry and a claim rely on exist in the database.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddRefundClaimRequest() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the tables that have the request' );
	}

	/**
	 * Tests that a site installed with the request sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( 'key_hash', $this->columns(), 'The claims\' own migration creates the key.' );

		$log = $this->captureQueries( fn() => ( new AddRefundClaimRequest() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the request' );
	}

	/**
	 * Tests the migration's place and what it declares: after every migration before it, the three tables in their end state, the store not trading while it is outstanding, in the refund claims' contribution.
	 *
	 * The claims' lines are written once with their claim and never changed; the actor locks are kept
	 * for good, one row per user, holding nothing but the user.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_three_tables(): void {
		$migration = new AddRefundClaimRequest();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( AddRefundClaimRequest::ID, $ids, 'The registry runs the migration.' );
		$this->assertGreaterThan( AddOrderEventReference::ID, $migration->id(), 'The migration sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'A refund\'s claim names the new columns, so the store waits for them.' );
		$this->assertEquals( RefundClaimTables::all(), $migration->tables() );
		$this->assertSame(
			array(
				array( 'Payment', MutationPattern::AppendOnly, 'financial' ),
				array( 'Payment', MutationPattern::MutableTransactional, 'permanent' ),
			),
			array_map(
				static fn( $table ): array => array( $table->module(), $table->mutationPattern(), $table->retention() ),
				array( RefundClaimTables::claimLines(), RefundClaimTables::actorLocks() )
			)
		);
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-04 12:00:00' ), $this->reporter() );
	}
}
