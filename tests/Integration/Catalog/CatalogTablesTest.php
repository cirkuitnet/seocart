<?php
/**
 * Tests the migration that creates the catalog's tables, and the keys that carry its invariants
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Platform\Database\Exception\DuplicateKey;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

/**
 * The four catalog tables are created exactly as declared, a second run changes nothing, and the
 * SKU key refuses a second variant with the same SKU in another case.
 *
 * Planted violations, one at a time, each put back afterwards:
 *
 * - Remove the `IndexSpec::unique( 'sku', ... )` line from CatalogTables::variants(): both
 *   variants are stored, and test_a_sku_is_stored_once_whatever_its_case fails.
 * - Declare `products.updated_at` as `datetime`: test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl
 *   finds the wrong type.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class CatalogTablesTest extends DatabaseTestCase {

	/**
	 * Tests that the migration applies after the bootstrap, each table matches its declaration, and re-running sends no DDL.
	 *
	 * @since 0.1.0
	 */
	public function test_the_tables_are_created_as_declared_and_a_second_run_sends_no_ddl(): void {
		$chain  = array( new PlatformBootstrapMigration(), new CreateCatalogTables() );
		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, CreateCatalogTables::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( CreateCatalogTables::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		foreach ( CatalogTables::all() as $table ) {
			$this->assertSame( array(), ( new SchemaVerifier( $this->db ) )->diff( $table ), "The table {$table->name()} matches its declaration exactly." );
		}

		$this->assertSame( 'datetime(6)', $this->columnType( CatalogTables::PRODUCTS, 'updated_at' ), 'A rewrite of the marker must change the row even within one second.' );
		$this->assertSame( 'ascii_bin', $this->columnCollation( CatalogTables::PRODUCTS, 'generation_state' ) );
		$this->assertSame( $this->db->collation(), $this->columnCollation( CatalogTables::VARIANTS, 'sku' ), 'The SKU keeps the table\'s collation, so its uniqueness ignores case.' );

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		// A run interrupted after its DDL runs up() again: dbDelta must find every table matching the generated CREATE.
		$rerun = $this->captureQueries( fn() => ( new CreateCatalogTables() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again on the existing tables' );
	}

	/**
	 * Tests that two variants cannot share a SKU, whatever its case.
	 *
	 * @since 0.1.0
	 */
	public function test_a_sku_is_stored_once_whatever_its_case(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateCatalogTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->insertVariant( 1, 'TSHIRT-RED' );

		try {
			$this->insertVariant( 2, 'tshirt-red' );
		} catch ( DuplicateKey $duplicate ) {
			$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->db->table( CatalogTables::VARIANTS ) ) );

			return;
		}

		$this->fail( 'Two variants were stored with one SKU in different cases.' );
	}

	/**
	 * Tests the migration's place and the tables' classification, as the data registry lists them.
	 *
	 * @since 0.1.0
	 */
	public function test_the_registry_lists_the_tables_classified(): void {
		$migration = new CreateCatalogTables();
		$registry  = OwnedData::registry();

		$this->assertGreaterThan( PlatformBootstrapMigration::ID, $migration->id() );
		$this->assertFalse( $migration->canOperateHalfApplied() );
		$this->assertEquals( CatalogTables::all(), $migration->tables() );
		$this->assertContains( CreateCatalogTables::class, array_map( 'get_class', $registry->migrations() ) );

		foreach ( CatalogTables::all() as $table ) {
			$this->assertEquals( $table, $registry->tableNamed( $table->name() ), "The registry holds {$table->name()} exactly as declared." );
			$this->assertSame( 'entity_lifetime', $table->retention() );

			$expected = CatalogTables::VARIANT_PRICES === $table->name() ? Classification::Financial : Classification::Public;

			foreach ( $table->columns() as $column ) {
				$this->assertSame( $expected, $column->classification(), "{$table->name()}.{$column->name()}" );
			}
		}
	}

	/**
	 * Inserts a variant row directly.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $id  The variant id.
	 * @param string $sku The SKU.
	 */
	private function insertVariant( int $id, string $sku ): void {
		$this->db->execute(
			'INSERT INTO %i ( id, uuid, product_id, sku, combination_hash, generation, created_at, updated_at ) VALUES ( %d, %s, %d, %s, %s, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() )',
			$this->db->table( CatalogTables::VARIANTS ),
			$id,
			sprintf( '00000000-0000-7000-8000-%012d', $id ),
			$id,
			$sku,
			hash( 'sha256', '' )
		);
	}

	/**
	 * Returns a column's type as the server reports it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table  The unprefixed table name.
	 * @param string $column The column.
	 * @return string The type.
	 */
	private function columnType( string $table, string $column ): string {
		return (string) $this->db->fetchValue( 'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->db->table( $table ), $column );
	}

	/**
	 * Returns a column's collation as the server reports it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $table  The unprefixed table name.
	 * @param string $column The column.
	 * @return string The collation.
	 */
	private function columnCollation( string $table, string $column ): string {
		return (string) $this->db->fetchValue( 'SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->db->table( $table ), $column );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-25 12:00:00' ), $this->reporter() );
	}
}
