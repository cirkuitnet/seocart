<?php
/**
 * Tests the chain that keys an existing store's stock by location
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Inventory;

use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Inventory\Infrastructure\Migrations\AddLocations;
use SEOCart\Inventory\Infrastructure\Migrations\CreateStockTablesMigration;
use SEOCart\Inventory\Infrastructure\Migrations\RekeyStockByLocation;
use SEOCart\Inventory\Infrastructure\Migrations\SeedDefaultLocation;
use SEOCart\Order\Infrastructure\Migrations\AddFulfilmentLocation;
use SEOCart\Order\Infrastructure\Migrations\CreateOrderTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Database\Exception\MigrationFailed;
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
use SEOCart\Tests\Support\Inventory\EarlierStockTables;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL -- The test writes the earlier store's rows by hand, as the earlier release left them, and runs the statements the chain's error names.

/**
 * A store that kept stock per variant, with stock, a split of holds, an allocation of an order and a ledger, ends with every row at its new default location, every table as declared and every earlier ledger value unchanged; a fresh install runs the same chain with no DDL after the stock tables' own migration; and a row the previous version wrote meanwhile stops the chain until a person runs the statements its error names.
 *
 * Planted violations, each shown red and removed: in SeedDefaultLocation::runBatch(), skip the
 * fill of a page: step 3 refuses the rows without a location and the chain stops on it; in
 * RekeyStockByLocation::up(), hand the tables to their declarations before the ALTERs: dbDelta
 * adds the new primary key beside the old one and the server refuses it (1068); in
 * SchemaOperations::alterTable(), send the ALTER without the diff() before it: the second run sends
 * it again; in AddLocations::tables(), include `stock_items`: the migrator's check of step 1 meets
 * the items in their intermediate shape and fails it.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class LocationsMigrationTest extends DatabaseTestCase {

	/**
	 * The order the earlier store allocated stock to.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const ORDER = 77;

	/**
	 * The ledger columns recorded before the chain, which it must leave as they were.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LEDGER_COLUMNS = 'id, variant_id, delta, on_hand_after, reason, order_id, allocation_id, actor_type, actor_id, correlation_id, created_at';

	/**
	 * Tests that the earlier store's rows end at the default location, the tables as declared, the policies copied and the ledger's earlier values unchanged, and that a second run sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_an_earlier_store_ends_with_every_row_at_its_default_location(): void {
		$this->earlierStore();

		$ledger = $this->ledgerHash();
		$counts = $this->counters();
		$ids    = $this->rowIds();
		$report = $this->migrator( self::chain() )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( AddLocations::ID, SeedDefaultLocation::ID, RekeyStockByLocation::ID, AddFulfilmentLocation::ID ), array_column( $report->applied(), 'id' ) );

		$default = $this->db->fetchRow( 'SELECT id, name, country, active, fulfils_online, offers_pickup, is_default FROM %i', $this->db->table( InventoryTables::LOCATIONS ) );

		$this->assertSame( '1', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->db->table( InventoryTables::LOCATIONS ) ), 'The store has one location.' );
		$this->assertSame(
			array( wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ), '', '1', '1', '0', '1' ),
			array( $default['name'] ?? null, $default['country'] ?? null, $default['active'] ?? null, $default['fulfils_online'] ?? null, $default['offers_pickup'] ?? null, $default['is_default'] ?? null ),
			'The default location is named after the site, has no address, is active, fulfils online orders and offers no pickup.'
		);

		foreach ( AddLocations::LOCATED as $table ) {
			$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE location_id <> %d', $this->db->table( $table ), (int) $default['id'] ), "Every row of {$table} is at the default location." );
		}

		$this->assertSame( $ids, $this->rowIds(), 'Every row is kept, by its id.' );
		$this->assertSame( $counts, $this->counters(), 'Every item keeps its counters.' );
		$this->assertSame( $ledger, $this->ledgerHash(), 'Every value the ledger recorded before is unchanged.' );
		$this->assertSame(
			array( array( '201', '1', 'no', null ), array( '202', '0', 'allow', '3' ), array( '203', '1', 'notify', null ) ),
			array_map( static fn( array $row ): array => array_values( $row ), $this->db->fetchAll( 'SELECT variant_id, track, backorder_policy, low_stock_threshold FROM %i ORDER BY variant_id', $this->db->table( InventoryTables::POLICIES ) ) ),
			'Each variant\'s policy is copied to its own row.'
		);

		foreach ( array_merge( InventoryTables::all(), array( OrderTables::orders() ) ) as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( self::chain() )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries(
			function (): void {
				foreach ( array( new AddLocations(), new RekeyStockByLocation(), new AddFulfilmentLocation() ) as $migration ) {
					$migration->up( $this->operations() );
				}
			}
		);

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when each step\'s up() runs again' );

		$status = $this->migrator( self::chain() )->status();

		$this->assertSame( array( array(), null, null, false ), array( $status->pending(), $status->failed(), $status->running(), $status->writesBlocked() ), 'The chain is applied, and nothing holds the store.' );
	}

	/**
	 * Tests that a fresh install runs the whole chain, the default location created, with no DDL after the stock tables' own migration.
	 *
	 * @since 0.2.0
	 */
	public function test_a_fresh_install_runs_the_chain_with_no_ddl_after_the_stock_tables(): void {
		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		$log    = $this->captureQueries( fn() => $this->migrator( self::chain() )->migrate( new MigrationRunOptions( 0 ) ) );
		$status = $this->migrator( self::chain() )->status();

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL of the chain on a fresh install' );
		$this->assertSame( array( array(), null ), array( $status->pending(), $status->failed() ), 'Every step is recorded applied.' );
		$this->assertSame( '1', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE is_default = 1', $this->db->table( InventoryTables::LOCATIONS ) ), 'The default location is created.' );
		$this->assertSame( '0', $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->db->table( InventoryTables::POLICIES ) ), 'Nothing is copied.' );
	}

	/**
	 * Tests that a stock row the previous version wrote after the default location filled its table stops the chain at step 3, the table left as it was, until a person runs the statements the error names, which also give a new variant its policy.
	 *
	 * The previous version writes an item for a new variant, with no location and its policy on
	 * the item, and a hold of an existing item with no location, after step 2 ran.
	 *
	 * @since 0.2.0
	 */
	public function test_a_row_the_previous_version_wrote_meanwhile_stops_the_chain_until_a_person_runs_what_it_names(): void {
		$this->earlierStore();
		$this->migrator( array_merge( self::before(), array( new AddLocations(), new SeedDefaultLocation() ) ) )->migrate( new MigrationRunOptions( 0 ) );

		$items = $this->db->table( InventoryTables::ITEMS );

		$this->db->execute( "INSERT INTO %i ( variant_id, on_hand, track, backorder_policy, low_stock_threshold, updated_at ) VALUES ( 204, 0, 0, 'allow', 2, UTC_TIMESTAMP(6) )", $items );
		$this->db->execute( "INSERT INTO %i ( variant_id, hold_group, quantity, expires_at, created_at ) VALUES ( 201, '01928c3e-7b3c-7d1e-9a2b-00000000000c', 1, UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP(6) )", $this->db->table( InventoryTables::HOLDS ) );
		$this->db->execute( 'UPDATE %i SET held = held + 1 WHERE variant_id = 201', $items );

		$named = array();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			try {
				$this->migrator( self::chain() )->migrate( new MigrationRunOptions( 0 ) );

				break;
			} catch ( MigrationFailed $stopped ) {
				$this->assertSame( RekeyStockByLocation::ID, $stopped->migrationId() );
				$this->assertTrue( $this->migrator( self::chain() )->status()->writesBlocked(), 'The store stays closed while step 3 is outstanding.' );

				$statements = self::statementsNamedIn( $stopped->detail() );
				$named[]    = array_map( static fn( string $sql ): string => (string) strtok( $sql, ' ' ) . ' ' . (string) strtok( ' ' ) . ' ' . (string) strtok( ' ' ), $statements );

				foreach ( $statements as $sql ) {
					$this->assertNotFalse( $GLOBALS['wpdb']->query( $sql ), $sql );
				}
			}
		}

		$this->assertSame(
			array(
				array( 'INSERT INTO `' . $this->db->table( InventoryTables::POLICIES ) . '`', 'UPDATE `' . $items . '` SET' ),
				array( 'UPDATE `' . $this->db->table( InventoryTables::HOLDS ) . '` SET' ),
			),
			$named,
			'The items\' error names the copy of the missing policy and their update; the holds\' error their update.'
		);
		$this->assertSame( array( '0', 'allow', '2' ), array_values( (array) $this->db->fetchRow( 'SELECT track, backorder_policy, low_stock_threshold FROM %i WHERE variant_id = 204', $this->db->table( InventoryTables::POLICIES ) ) ), 'The new variant\'s policy is copied from its item.' );

		foreach ( InventoryTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$this->assertFalse( $this->migrator( self::chain() )->status()->writesBlocked(), 'The store trades again.' );
	}

	/**
	 * Tests the chain's place and what it declares: after every migration before it, the store waiting for the three inventory steps and not for the order's, each in its module's contribution.
	 *
	 * @since 0.2.0
	 */
	public function test_the_chain_sorts_after_its_predecessors_and_holds_the_store_until_stock_is_keyed(): void {
		$registered = array_map( static fn( Migration $migration ): string => $migration->id(), OwnedData::registry()->migrations() );
		$chain      = array( AddLocations::ID, SeedDefaultLocation::ID, RekeyStockByLocation::ID, AddFulfilmentLocation::ID );

		$this->assertSame( $chain, array_slice( $registered, -4 ), 'The four steps are the newest migrations, in order.' );
		$this->assertGreaterThan( '20261010_0003_payment_provider_references', AddLocations::ID );
		$this->assertSame( array( false, false, false, true ), array_map( static fn( Migration $migration ): bool => $migration->canOperateHalfApplied(), array( new AddLocations(), new SeedDefaultLocation(), new RekeyStockByLocation(), new AddFulfilmentLocation() ) ) );
		$this->assertEquals( InventoryTables::all(), ( new RekeyStockByLocation() )->tables() );
		$this->assertEquals( array( OrderTables::orders() ), ( new AddFulfilmentLocation() )->tables() );
		$this->assertSame(
			array( InventoryTables::POLICIES, InventoryTables::LOCATIONS, InventoryTables::LOCATION_MARKETS, InventoryTables::PICKUP_HOURS, InventoryTables::TRANSFERS, InventoryTables::TRANSFER_LINES ),
			array_map( static fn( $table ): string => $table->name(), ( new AddLocations() )->tables() ),
			'Step 1 verifies the six tables it creates; the four it adds a column to are verified by step 3.'
		);
	}

	/**
	 * Builds the earlier store: its migrations applied, the stock tables of the earlier shape, and rows for three variants and an order.
	 *
	 * Variant 201 is tracked with 5 on hand, 1 of them allocated to the order and 2 held by two
	 * holds; 202 is untracked and may be backordered, with a threshold; 203 is tracked, may notify,
	 * and has 10 on hand from two ledger entries and 3 held.
	 *
	 * @since 0.2.0
	 */
	private function earlierStore(): void {
		$this->migrator( self::before() )->migrate( new MigrationRunOptions( 0 ) );

		EarlierStockTables::create( $this->db );

		$this->db->execute( 'ALTER TABLE %i DROP COLUMN fulfilment_location_id', $this->db->table( OrderTables::ORDERS ) );

		$items = $this->db->table( InventoryTables::ITEMS );

		$this->db->execute( "INSERT INTO %i ( variant_id, on_hand, allocated, held, track, backorder_policy, low_stock_threshold, updated_at ) VALUES ( 201, 5, 1, 2, 1, 'no', NULL, UTC_TIMESTAMP(6) ), ( 202, 0, 0, 0, 0, 'allow', 3, UTC_TIMESTAMP(6) ), ( 203, 10, 0, 3, 1, 'notify', NULL, UTC_TIMESTAMP(6) )", $items );
		$this->db->execute(
			"INSERT INTO %i ( variant_id, cart_id, hold_group, quantity, expires_at, created_at ) VALUES ( 201, 9, '01928c3e-7b3c-7d1e-9a2b-00000000000a', 1, UTC_TIMESTAMP() + INTERVAL 1 HOUR, UTC_TIMESTAMP(6) ), ( 201, 8, '01928c3e-7b3c-7d1e-9a2b-00000000000b', 1, UTC_TIMESTAMP() - INTERVAL 1 HOUR, UTC_TIMESTAMP(6) ), ( 203, 8, '01928c3e-7b3c-7d1e-9a2b-00000000000b', 3, UTC_TIMESTAMP() - INTERVAL 1 HOUR, UTC_TIMESTAMP(6) )",
			$this->db->table( InventoryTables::HOLDS )
		);
		$this->db->execute( "INSERT INTO %i ( variant_id, order_id, order_line_id, quantity, posted_quantity, state, created_at ) VALUES ( 201, %d, 1, 1, 0, 'open', UTC_TIMESTAMP(6) )", $this->db->table( InventoryTables::ALLOCATIONS ), self::ORDER );
		$this->db->execute(
			"INSERT INTO %i ( variant_id, delta, on_hand_after, reason, order_id, allocation_id, actor_type, actor_id, correlation_id, created_at ) VALUES ( 201, 5, 5, 'received', NULL, NULL, 'user', 1, '01928c3e-7b3c-7d1e-9a2b-0000000000c1', '2026-10-01 09:00:00.000001' ), ( 203, 12, 12, 'received', NULL, NULL, 'user', 1, NULL, '2026-10-01 09:00:00.000002' ), ( 203, -2, 10, 'damaged', NULL, NULL, 'system', NULL, NULL, '2026-10-02 09:00:00.000003' )",
			$this->db->table( InventoryTables::LEDGER )
		);
	}

	/**
	 * Hashes every ledger value recorded before the chain added its columns.
	 *
	 * @since 0.2.0
	 *
	 * @return string The hash of the rows, by id.
	 */
	private function ledgerHash(): string {
		return md5( (string) wp_json_encode( $this->db->fetchAll( 'SELECT ' . self::LEDGER_COLUMNS . ' FROM %i ORDER BY id', $this->db->table( InventoryTables::LEDGER ) ) ) );
	}

	/**
	 * Reads every item's counters.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array<string, mixed>> Each item's variant and counters, by variant.
	 */
	private function counters(): array {
		return $this->db->fetchAll( 'SELECT variant_id, on_hand, allocated, held FROM %i ORDER BY variant_id', $this->db->table( InventoryTables::ITEMS ) );
	}

	/**
	 * Reads the ids of the hold, allocation and ledger rows.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each table's ids, comma-separated, by table.
	 */
	private function rowIds(): array {
		$ids = array();

		foreach ( array( InventoryTables::HOLDS, InventoryTables::ALLOCATIONS, InventoryTables::LEDGER ) as $table ) {
			$ids[ $table ] = (string) $this->db->fetchValue( 'SELECT GROUP_CONCAT( id ORDER BY id ) FROM %i', $this->db->table( $table ) );
		}

		return $ids;
	}

	/**
	 * Reads the statements a refusal of step 3 names, in the order it names them.
	 *
	 * @since 0.2.0
	 *
	 * @param string $detail What the migration recorded.
	 * @return list<string> The statements.
	 */
	private static function statementsNamedIn( string $detail ): array {
		self::assertMatchesRegularExpression( '/Run, in this order: (.+;) Then run `wp seocart migrate` again\./', $detail );

		preg_match( '/Run, in this order: (.+;) Then run `wp seocart migrate` again\./', $detail, $found );

		return array_map( static fn( string $sql ): string => trim( $sql ) . ';', array_values( array_filter( explode( ';', $found[1] ), static fn( string $part ): bool => '' !== trim( $part ) ) ) );
	}

	/**
	 * Returns the migrations a store has run before the chain, in id order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Migration> The migrations.
	 */
	private static function before(): array {
		return array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateStockTablesMigration(), new CreateOrderTables() );
	}

	/**
	 * Returns the migrations before the chain and the chain, in id order.
	 *
	 * @since 0.2.0
	 *
	 * @return list<Migration> The migrations.
	 */
	private static function chain(): array {
		return array_merge( self::before(), array( new AddLocations(), new SeedDefaultLocation(), new RekeyStockByLocation(), new AddFulfilmentLocation() ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-24 12:00:00' ), $this->reporter() );
	}
}
