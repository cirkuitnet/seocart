<?php
/**
 * Tests the migration that adds the checkout fields' values to the checkout sessions
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Infrastructure\CheckoutTables;
use SEOCart\Checkout\Infrastructure\Migrations\AddSessionFieldValues;
use SEOCart\Checkout\Infrastructure\Migrations\CreateCheckoutTables;
use SEOCart\Order\Infrastructure\Migrations\AddOrderCustomerIndex;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * A site whose checkout sessions were created before the checkout fields' values gets the column from one statement, exactly as declared and as approved, with every session it has holding none; a site installed since sends no DDL for it.
 *
 * The ALTER the step sends is compared byte for byte with the statement written out here, the
 * one approved: a change to the declaration or to how the column is added fails here before it
 * reaches a store.
 *
 * Planted violation, shown red and removed: in AddSessionFieldValues::up(), do nothing. The
 * step then sends no ALTER, and the migrator's post-condition names the column the sessions lack.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class SessionFieldValuesMigrationTest extends DatabaseTestCase {

	/**
	 * The statement that adds the column, as approved: `{prefix}` is the site's table prefix.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const COLUMN_DDL = 'ALTER TABLE {prefix}seocart_checkout_sessions ADD COLUMN `field_values_json` text NULL';

	/**
	 * Tests that, on a site whose sessions were created without the column, the step adds it once with the approved statement, an existing session holds no value, the head moves, and a second run sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_column_is_added_to_sessions_created_without_it(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateCheckoutTables() );
		$table  = $this->db->table( CheckoutTables::SESSIONS );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A site migrated before the column was declared, with a session on it.
		$this->db->execute( 'ALTER TABLE %i DROP COLUMN field_values_json', $table );
		$this->db->execute( "INSERT INTO %i ( cart_id, billing_address_json, created_at, updated_at ) VALUES ( 7, '{\"country\":\"GB\"}', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) )", $table );
		$this->assertNotContains( CheckoutTables::FIELD_VALUES, $this->columns(), 'The older site has no field values.' );

		$chain  = array_merge( $before, array( new AddSessionFieldValues() ) );
		$report = null;
		$log    = $this->captureQueries(
			function () use ( $chain, &$report ): void {
				$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);

		fwrite( STDOUT, "\nThe session step sent: " . implode( "\n", $log->ofType( 'ALTER' )->sqls() ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The statement sent is printed for the report.

		$this->assertSame( array( AddSessionFieldValues::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddSessionFieldValues::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );
		$this->assertSame( array( $this->approved( self::COLUMN_DDL ) ), $log->ofType( 'ALTER' )->sqls(), 'The column is added once, as approved.' );
		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'DROP' ), 'CREATE and DROP statements of the step' );
		$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( CheckoutTables::sessions() ), 'checkout_sessions matches its declaration exactly.' );
		$this->assertSame(
			array(
				'billing_address_json' => '{"country":"GB"}',
				'field_values_json'    => null,
			),
			(array) $this->db->fetchRow( 'SELECT billing_address_json, field_values_json FROM %i', $table ),
			'A session written before the column keeps its address and holds no field value.'
		);

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$rerun = $this->captureQueries( fn() => ( new AddSessionFieldValues() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the table that has the column' );
	}

	/**
	 * Tests that a site installed with the column, from the checkout tables' own migration, sends no DDL for the step.
	 *
	 * @since 0.2.0
	 */
	public function test_a_site_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateCheckoutTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertContains( CheckoutTables::FIELD_VALUES, $this->columns(), 'The checkout tables\' own migration creates the column.' );

		$log = $this->captureQueries( fn() => ( new AddSessionFieldValues() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a site that has the column' );
	}

	/**
	 * Tests the step's place and what it declares: after every migration before it, the sessions in their end state, the store not trading while it is outstanding, in the checkout module's contribution; the column personal data the eraser destroys.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_sorts_last_and_declares_the_column_personal_data(): void {
		$migration = new AddSessionFieldValues();
		$ids       = array_map( static fn( Migration $registered ): string => $registered->id(), OwnedData::registry()->migrations() );
		$column    = null;

		foreach ( CheckoutTables::sessions()->columns() as $declared ) {
			if ( CheckoutTables::FIELD_VALUES === $declared->name() ) {
				$column = $declared;
			}
		}

		$this->assertContains( AddSessionFieldValues::ID, $ids, 'The registry runs the step.' );
		$this->assertGreaterThan( AddOrderCustomerIndex::ID, $migration->id(), 'The step sorts after every migration before it, so an earlier release\'s migrations stay a prefix.' );
		$this->assertFalse( $migration->canOperateHalfApplied(), 'The session write and the placement name the column, so the store waits for it.' );
		$this->assertEquals( array( CheckoutTables::sessions() ), $migration->tables() );
		$this->assertNotNull( $column );
		$this->assertSame( array( 'text', true, Classification::Pii, ColumnSpec::ERASE_DESTROY ), array( $column->type(), $column->nullable(), $column->classification(), $column->erasure() ) );
	}

	/**
	 * Reads the columns of the session table.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The column names.
	 */
	private function columns(): array {
		return array_map( 'strval', array_column( $this->db->fetchAll( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->db->table( CheckoutTables::SESSIONS ) ), 'COLUMN_NAME' ) );
	}

	/**
	 * Returns an approved statement with the site's table prefix.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement The statement, with `{prefix}`.
	 * @return string The statement as the site sends it.
	 */
	private function approved( string $statement ): string {
		return str_replace( '{prefix}', $this->db->prefix(), $statement );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-11-15 12:00:00' ), $this->reporter() );
	}
}
