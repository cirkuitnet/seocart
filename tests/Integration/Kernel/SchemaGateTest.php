<?php
/**
 * Tests the schema gate and the transaction manager that refuses writes while it is closed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Kernel;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\BootOption;
use SEOCart\Platform\Kernel\BootRecord;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GatedTransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureStockService;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;

/**
 * The gate answers from the cached head at no query, and the transaction manager applications
 * receive refuses every write while it is closed, with one 503 on every surface.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - In Migrator::writesBlocked(), plant `return false;` as the first line: the blocking cases turn
 *   Ready, and the refusal tests' writes go through.
 * - In SchemaGate::writesBlocked(), plant `return false;`: the transaction manager, which asks it,
 *   lets test_the_transaction_manager_refuses_the_outermost_level_only write, and the degraded-mode
 *   notice of DegradedNoticeTest, which asks it too, is not shown.
 * - In SchemaGate::state(), call `$migrator->status();` before deciding: the query counter of
 *   test_each_state_comes_from_the_cached_head_without_a_query counts it.
 * - In KernelError::definitions(), remove `any_write: true`: the invoker calls the refusal an
 *   undeclared code on every surface, and test_the_refusal_reaches_every_surface_as_the_same_503 fails
 *   on the unexpected notice.
 * - In BootRecord::fromJson(), accept a record without a lock mode: the gate builds the lock service,
 *   which probes the host and writes the record, and
 *   test_a_record_without_a_lock_mode_is_refused_before_any_query counts the queries and the write.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class SchemaGateTest extends KernelTestCase {

	/**
	 * The id of the fixture migration that sorts after the bootstrap migration.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NEXT = '20990101_0001_gate_next';

	/**
	 * Discards the REST server and the Abilities registries a surface test built.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Returns the planted heads and chains, and the state each must give.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string|null, 1: bool|null, 2: GateState}> The recorded head, whether the
	 *         chain adds a migration after the bootstrap one and whether it may run half-applied (null: no
	 *         such migration), and the expected state.
	 */
	public static function states(): array {
		return array(
			'the head is the code\'s'          => array( PlatformBootstrapMigration::ID, null, GateState::Ready ),
			'one migration behind, which cannot run half-applied' => array( PlatformBootstrapMigration::ID, false, GateState::CodeNewer ),
			'one migration behind, which can run half-applied' => array( PlatformBootstrapMigration::ID, true, GateState::Ready ),
			'no head recorded'                 => array( null, null, GateState::CodeNewer ),
			'the head sorts after the code\'s' => array( '20991231_0001_from_a_newer_version', null, GateState::SchemaNewer ),
		);
	}

	/**
	 * Tests every state, from a planted record, without a single query.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider states
	 *
	 * @param string|null $head      The recorded head.
	 * @param bool|null   $half      Whether the chain's second migration may run half-applied; null for no second migration.
	 * @param GateState   $expected  The state.
	 */
	public function test_each_state_comes_from_the_cached_head_without_a_query( ?string $head, ?bool $half, GateState $expected ): void {
		$this->plantRecord( self::installedRecord( $head )->withLockMode( LockMode::GetLock ) );

		$gate = $this->container( $this->chain( $half ) )->get( SchemaGate::class );
		$log  = $this->captureQueries(
			static function () use ( $gate, $expected ): void {
				self::assertSame( $expected, $gate->state() );
				self::assertSame( GateState::Ready !== $expected, $gate->writesBlocked() );
			}
		);

		$this->assertQueryCount( 0, $log, 'The schema gate' );
	}

	/**
	 * Tests that a site without a record is not installed, and refuses writes.
	 *
	 * @since 0.1.0
	 */
	public function test_a_site_without_a_record_is_not_installed(): void {
		$gate = $this->container()->get( SchemaGate::class );

		$this->assertSame( GateState::NotInstalled, $gate->state() );
		$this->assertTrue( $gate->writesBlocked() );
	}

	/**
	 * Tests the refusal: the outermost level raises store.unavailable and sends nothing; a nested level
	 * passes; the raw connection, which the migrator uses, still writes.
	 *
	 * @since 0.1.0
	 */
	public function test_the_transaction_manager_refuses_the_outermost_level_only(): void {
		$this->plantRecord( self::installedRecord( PlatformBootstrapMigration::ID )->withLockMode( LockMode::GetLock ) );

		$manager = $this->container( $this->chain( false ) )->get( TransactionManager::class );

		$this->assertInstanceOf( GatedTransactionManager::class, $manager, 'Applications receive the gated manager.' );

		$refused = null;
		$ran     = false;
		$log     = $this->captureQueries(
			static function () use ( $manager, &$refused, &$ran ): void {
				try {
					$manager->transaction(
						static function () use ( &$ran ): void {
							$ran = true;
						}
					);
				} catch ( CodedException $error ) {
					$refused = $error;
				}
			}
		);

		$this->assertInstanceOf( CodedException::class, $refused );
		$this->assertSame( KernelError::StoreUnavailable, $refused->errorCode() );
		$this->assertSame( array( 'reason' => GateState::CodeNewer->value ), $refused->context() );
		$this->assertFalse( $ran, 'The refused unit of work ran.' );
		$this->assertQueryCount( 0, $log, 'A refused unit of work' );

		$nested = $this->db->transaction( static fn(): string => $manager->transaction( static fn(): string => 'nested' ) );

		$this->assertSame( 'nested', $nested, 'A level inside a unit of work already let through is not refused.' );

		$this->db->transaction(
			function (): void {
				$this->insertRow( 1, 'written by the migrator\'s connection' );
			}
		);

		$this->assertSame( '1', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->rowsTable() ), 'The raw connection must keep writing, or the site could not repair itself.' );
	}

	/**
	 * Tests that a record without a lock mode, which no writer stores, cannot make the gate probe the
	 * host or write the record before it refuses: the text reads as corrupt, so the site reads as not
	 * installed, and the refusal costs no query and leaves the text as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_a_record_without_a_lock_mode_is_refused_before_any_query(): void {
		global $wpdb;

		$this->plantRecord( self::installedRecord() );

		$text    = (string) $this->storedRecord();
		$without = str_replace( '"lock_mode":"get_lock",', '', $text );

		$this->assertNotSame( $text, $without, 'The planted record has no lock mode to take out.' );

		$wpdb->update( $wpdb->options, array( 'option_value' => $without ), array( 'option_name' => BootOption::NAME ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The test stores a text no writer would, past every cache.
		wp_cache_flush();
		wp_load_alloptions();

		$manager = $this->container()->get( TransactionManager::class );
		$refused = null;
		$log     = $this->captureQueries(
			static function () use ( $manager, &$refused ): void {
				try {
					$manager->transaction( static fn(): bool => true );
				} catch ( CodedException $error ) {
					$refused = $error;
				}
			}
		);

		$this->assertInstanceOf( CodedException::class, $refused, 'The write went through.' );
		$this->assertSame( KernelError::StoreUnavailable, $refused->errorCode() );
		$this->assertQueryCount( 0, $log, 'A refused write on a record without a lock mode' );
		$this->assertSame( $without, $this->storedRecord(), 'The gate wrote the record before it refused.' );
	}

	/**
	 * Tests that the same manager lets writes through once the gate is open.
	 *
	 * @since 0.1.0
	 */
	public function test_the_transaction_manager_writes_while_the_gate_is_open(): void {
		$this->plantRecord( self::installedRecord( self::codeHead() )->withLockMode( LockMode::GetLock ) );

		$manager = $this->container()->get( TransactionManager::class );

		$manager->transaction(
			function (): void {
				$this->insertRow( 2, 'written through the gate' );
			}
		);

		$this->assertSame( '1', (string) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE id = 2', $this->rowsTable() ) );
	}

	/**
	 * Tests that a write refused by the gate reaches every surface as the same coded error: 503 on
	 * REST, the same error from the ability, and the code with a failure on the command line.
	 *
	 * @since 0.1.0
	 */
	public function test_the_refusal_reaches_every_surface_as_the_same_503(): void {
		$this->plantRecord( self::installedRecord( PlatformBootstrapMigration::ID )->withLockMode( LockMode::GetLock ) );

		$manager = $this->container( $this->chain( false ) )->get( TransactionManager::class );

		add_filter(
			'user_has_cap',
			static function ( array $allcaps ): array {
				$allcaps['seocart_manage_inventory'] = true;

				return $allcaps;
			}
		);
		wp_set_current_user( 1 );

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		/*
		 * The fixture operation does not declare store.unavailable, and needs not: the code's row
		 * says any operation that changes the store may raise it. So the invoker raises no
		 * undeclared-code notice here, and the test harness fails the test if one appears.
		 */
		$service  = new GatedStockService( $manager, new FixtureStockService() );
		$surfaces = new OperationSurfaces( $registry, $service );
		$outcomes = $surfaces->everywhere(
			FixtureStockOperation::definition(),
			array(
				'item_id' => FixtureStockOperation::EXAMPLE_ITEM,
				'delta'   => 1,
			)
		);

		$this->assertSame( 0, $service->ran, 'The refused write ran on some surface.' );

		$this->assertSame( 503, $outcomes['rest']['result']->get_status() );
		$this->assertSame( KernelError::StoreUnavailable->value, $outcomes['rest']['result']->get_data()['code'] );

		$ability = $outcomes['ability']['result'];

		$this->assertInstanceOf( WP_Error::class, $ability );
		$this->assertSame( KernelError::StoreUnavailable->value, $ability->get_error_code() );
		$this->assertSame( 503, $ability->get_error_data()['status'] );

		$this->assertNull( $outcomes['cli']['result']['printed'] );
		$this->assertStringStartsWith( KernelError::StoreUnavailable->value . ': ', (string) $outcomes['cli']['result']['failure'], 'The command must fail, which ends it with a non-zero exit status.' );
	}

	/**
	 * Returns the container overrides for a chain of the bootstrap migration and, optionally, one more.
	 *
	 * @since 0.1.0
	 *
	 * @param bool|null $half Whether the second migration may run half-applied; null for no second migration.
	 * @return array<string, callable(Container): object> The overrides.
	 */
	private function chain( ?bool $half ): array {
		$chain = array( new PlatformBootstrapMigration() );

		if ( null !== $half ) {
			$chain[] = new CreatesTestTable( self::NEXT, 'gate_next', $half );
		}

		$report = $this->reporter();

		return array(
			Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, $chain, $report ),
		);
	}
}
