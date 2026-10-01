<?php
/**
 * Tests the migration of the checkout's tables, and what they declare
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Infrastructure\MysqlCartRepository;
use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Migrations\CreateCheckoutTables;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The checkout's tables are created exactly as declared, after every other migration, and a second run changes nothing.
 *
 * Planted violation: at the end of CreateCheckoutTables::up(), drop the `cart_id` unique key of
 * `checkout_sessions` directly, so the table no longer has a key its declaration names. The
 * migrator's post-condition then fails the migration.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class CheckoutTablesMigrationTest extends DatabaseTestCase {

	/**
	 * Tests that the tables are created as declared, the head moves to the migration, and running again sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateCheckoutTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateCheckoutTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateCheckoutTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( CheckoutTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new CreateCheckoutTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests the migration's place, after every migration the plugin had before it, and that the production registry lists the two tables.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_comes_last_and_the_registry_lists_its_tables(): void {
		$migration = new CreateCheckoutTables();
		$others    = array_filter( array_map( static fn( Migration $other ): string => $other->id(), OwnedData::registry()->migrations() ), static fn( string $id ): bool => CreateCheckoutTables::ID !== $id );

		$this->assertNotSame( array(), $others );

		foreach ( $others as $id ) {
			$this->assertGreaterThan( $id, $migration->id(), "The checkout's migration sorts before {$id}." );
		}

		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( CheckoutTables::all(), $migration->tables() );

		$registered = array();

		foreach ( OwnedData::registry()->tables() as $table ) {
			if ( 'Checkout' === $table->module() ) {
				$registered[] = $table->name();
			}
		}

		$this->assertSame( array( CheckoutTables::SESSIONS, CheckoutTables::IDEMPOTENCY_KEYS ), $registered );
	}

	/**
	 * Tests what the tables declare: their patterns and retention, the addresses as personal data, the stored answer as a secret, and the name the cart's sweep deletes a session by.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_classify_the_addresses_and_the_answer(): void {
		$sessions = CheckoutTables::sessions();
		$keys     = CheckoutTables::idempotencyKeys();

		$this->assertSame( array( MutationPattern::MutableTransactional, 'carts' ), array( $sessions->mutationPattern(), $sessions->retention() ) );
		$this->assertSame( array( MutationPattern::MutableTransactional, 'idempotency_keys' ), array( $keys->mutationPattern(), $keys->retention() ) );
		$this->assertSame(
			array(
				'billing_address_json'  => Classification::Pii,
				'shipping_address_json' => Classification::Pii,
			),
			self::classified( $sessions->columns(), Classification::Pii )
		);
		$this->assertSame( array(), self::classified( $sessions->columns(), Classification::Secret ) );
		$this->assertSame( array( 'response_json' => Classification::Secret ), self::classified( $keys->columns(), Classification::Secret ) );
		$this->assertSame( array(), self::classified( $keys->columns(), Classification::Pii ) );
		$this->assertSame( CheckoutTables::SESSIONS, MysqlCartRepository::CHECKOUT_SESSIONS, 'The cart\'s sweep deletes sessions from another table than the checkout\'s.' );
	}

	/**
	 * Returns the columns of a classification, by name.
	 *
	 * @since 0.1.0
	 *
	 * @param \SEOCart\Platform\Database\Schema\ColumnSpec[] $columns        The columns.
	 * @param Classification                                 $classification The classification.
	 * @return array<string, Classification> The columns of that classification.
	 */
	private static function classified( array $columns, Classification $classification ): array {
		$found = array();

		foreach ( $columns as $column ) {
			if ( $classification === $column->classification() ) {
				$found[ $column->name() ] = $column->classification();
			}
		}

		return $found;
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-30 12:00:00' ), $this->reporter() );
	}
}
