<?php
/**
 * Tests the migration that adds the outcome to the payment ledger's claim key
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Payment\Infrastructure\Migrations\AddIntentMode;
use SEOCart\Payment\Infrastructure\Migrations\AddRefundClaimSettlement;
use SEOCart\Payment\Infrastructure\Migrations\CreatePaymentTables;
use SEOCart\Payment\Infrastructure\Migrations\KeyLedgerByOutcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Database\Exception\DuplicateKey;
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
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * A site whose ledger was created with the three-column claim key ends with the four-column key, exactly as declared, the rows it had kept; the key then admits another outcome of a provider object and still refuses the same one; a site installed since sends no DDL; and the store does not trade until the key is replaced.
 *
 * Planted violations, each shown red and removed: in KeyLedgerByOutcome::up(), do nothing: the
 * migrator's post-condition fails the migration on the older site; in
 * SchemaOperations::replaceUniqueKey(), send nothing when the key exists: the same; in
 * KeyLedgerByOutcome::canOperateHalfApplied(), answer true: the gate stays open, and the approval
 * delivered while the key is outstanding goes on.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class LedgerOutcomeKeyMigrationTest extends KernelTestCase {

	/**
	 * The migrations a site with a ledger has run before the outcome joined the key.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Migration> The chain.
	 */
	private static function before(): array {
		return array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateOrderTables(), new CreatePaymentTables(), new AddIntentMode() );
	}

	/**
	 * Tests that the key of a ledger created with three columns gets the fourth, the rows are kept, the head moves, and running again sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_outcome_joins_the_key_of_a_ledger_created_without_it(): void {
		$ledger = $this->db->table( PaymentTables::TRANSACTIONS );

		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the outcome joined the key, with a declined authorization on it.
		$this->db->execute( 'ALTER TABLE %i DROP INDEX %i, ADD UNIQUE KEY %i ( provider, provider_object_id, operation )', $ledger, PaymentTables::LEDGER_KEY, PaymentTables::LEDGER_KEY );
		$this->recordAuthorization( 'declined' );

		$this->assertSame( array( 'provider', 'provider_object_id', 'operation' ), $this->keyColumns(), 'The older site keys three columns.' );

		$chain  = array_merge( self::before(), array( new KeyLedgerByOutcome() ) );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( KeyLedgerByOutcome::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( KeyLedgerByOutcome::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array( 'provider', 'provider_object_id', 'operation', 'result' ), $this->keyColumns() );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( PaymentTables::transactions() ), 'The ledger matches its declaration exactly.' );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $ledger ), 'The row is kept.' );

		// The approval of the object the decline named is a result of its own; the same approval again meets the key.
		$this->recordAuthorization( 'approved' );

		try {
			$this->recordAuthorization( 'approved' );
			$this->fail( 'The same outcome of the same object was recorded twice.' );
		} catch ( DuplicateKey $refused ) {
			unset( $refused );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new KeyLedgerByOutcome() )->up( $this->operations() ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on a ledger keyed by the outcome' );
	}

	/**
	 * Tests that the store does not trade while the replacement is outstanding: the schema gate is closed, and an approval delivered meanwhile is refused with the gate's error before any statement, for its provider to deliver again once the key has the outcome.
	 *
	 * Under three columns that approval, of an object whose decline is recorded, would be answered
	 * as a duplicate with no row and no flag.
	 *
	 * @since 0.2.0
	 */
	public function test_the_gate_holds_commerce_writes_until_the_key_is_replaced(): void {
		$this->plantRecord( self::installedRecord( AddRefundClaimSettlement::ID ) );

		$chain  = OwnedData::registry()->migrations();
		$report = $this->reporter();
		$kernel = $this->container( array( Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, $chain, $report ) ) );

		$this->assertSame( GateState::CodeNewer, $kernel->get( SchemaGate::class )->state(), 'The site is one migration behind, which cannot run half-applied.' );

		$approval = new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, '0192a4b3-7c5d-7e8f-9a0b-1c2d3e4f5a6e', Money::of( 3080, Currency::of( 'USD' ) ), 'pi_same' );
		$refused  = null;
		$log      = $this->captureQueries(
			static function () use ( $kernel, $approval, &$refused ): void {
				try {
					$kernel->get( SettlePlacement::class )->apply( $approval, SettlePlacement::store() );
				} catch ( CodedException $error ) {
					$refused = $error;
				}
			}
		);

		$this->assertInstanceOf( CodedException::class, $refused, 'The delivery went on with the gate closed.' );
		$this->assertSame( array( KernelError::StoreUnavailable, array( 'reason' => GateState::CodeNewer->value ) ), array( $refused->errorCode(), $refused->context() ) );
		$this->assertQueryCount( 0, $log, 'A delivery refused by the gate' );
	}

	/**
	 * Tests that a site installed with the four-column key sends no DDL for the migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( 'provider', 'provider_object_id', 'operation', 'result' ), $this->keyColumns(), 'The ledger\'s own migration creates the four-column key.' );

		$log = $this->captureQueries( fn() => ( new KeyLedgerByOutcome() )->up( $this->operations() ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site keyed by the outcome' );
	}

	/**
	 * Tests the migration's place and what it declares: after every migration before it, the refund claims' settlement included; the ledger in its end state; the store not trading while it is outstanding; in the payment tables' contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_migration_sorts_last_and_declares_the_ledger(): void {
		$migration = new KeyLedgerByOutcome();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );

		$this->assertContains( KeyLedgerByOutcome::ID, $ids, 'The registry runs the migration.' );
		$this->assertSame( KeyLedgerByOutcome::ID, max( $ids ), 'The migration sorts after every migration registered before it.' );
		$this->assertGreaterThan( AddRefundClaimSettlement::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'Under three columns a second outcome of the same object is lost, so the store waits for the key.' );
		$this->assertEquals( array( PaymentTables::transactions() ), $migration->tables() );
	}

	/**
	 * Reads the claim key's columns, in key order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function keyColumns(): array {
		$rows = $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s ORDER BY SEQ_IN_INDEX', $this->db->table( PaymentTables::TRANSACTIONS ), PaymentTables::LEDGER_KEY );

		return array_map( 'strval', array_column( $rows, 'COLUMN_NAME' ) );
	}

	/**
	 * Writes a ledger row of an authorization of the provider object `pi_same`, with an outcome.
	 *
	 * @since 0.2.0
	 *
	 * @param string $result The outcome.
	 */
	private function recordAuthorization( string $result ): void {
		$this->db->execute(
			"INSERT INTO %i SET uuid = %s, intent_id = 1, order_id = 1, operation = 'authorize', amount_minor = 3080, currency = 'USD', conversion_context_id = 1, base_currency = 'USD', base_amount_minor = 3080, "
				. "provider = 'stub', provider_object_id = 'pi_same', result = %s, applied = 1, actor_type = 'system', created_at = UTC_TIMESTAMP(6)",
			$this->db->table( PaymentTables::TRANSACTIONS ),
			wp_generate_uuid4(),
			$result
		);
	}

	/**
	 * Builds the DDL operations over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @return SchemaOperations The operations.
	 */
	private function operations(): SchemaOperations {
		return new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-10 12:00:00' ), $this->reporter() );
	}
}
