<?php
/**
 * Tests the production data registry: what it lists, that its tables and migrations agree, and that it is pure data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Support\ChildProcessProbe;

/**
 * The production list, checked without a database.
 *
 * DRY rule 12: the registry is built and read in a PHP process of its own that has loaded
 * Composer's autoloader and nothing else (tests/Support/data-registry-probe.php, started by
 * ChildProcessProbe). An in-process check would prove nothing, because Brain Monkey leaves
 * stand-ins for WordPress functions behind in this process once another test has used them.
 *
 * DRY rule 11: a module registers its tables beside the migrations that create them, which is
 * two lists, so the two are held equal here: every registered table is declared, with the same
 * shape, by a production schema migration, and every table a production schema migration
 * declares is registered. The integration coverage test proves the same against a real
 * database.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class OwnedDataTest extends TestCase {

	/**
	 * Tests that the production registry is built and read, in full, where WordPress does not exist.
	 *
	 * Planted violation: a get_option() call at the top of OwnedData::registry().
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_registry_is_built_and_read_with_wordpress_absent(): void {
		$probe    = ChildProcessProbe::run( dirname( __DIR__, 3 ) . '/Support/data-registry-probe.php' );
		$registry = OwnedData::registry();

		$this->assertFalse( $probe['wordpress_loaded'], 'WordPress is loaded in the probe process, so its clean result would prove nothing.' );
		$this->assertSame( array(), $probe['wordpress_functions'], 'WordPress functions, or stand-ins for them, exist in the probe process, so its clean result would prove nothing.' );

		$this->assertSame( $registry->tableNames(), $probe['tables'], 'The probe built a different registry.' );
		$this->assertSame( array_map( static fn( $migration ): string => $migration->id(), $registry->migrations() ), $probe['migrations'] );
		$this->assertSame( $registry->retention()->ids(), array_keys( $probe['retention'] ) );
		$this->assertSame( $registry->capabilities()->roles(), array_keys( $probe['roles'] ) );

		$root    = (string) realpath( dirname( __DIR__, 4 ) ) . '/';
		$foreign = array();
		$read    = array();

		foreach ( $probe['files'] as $file ) {
			$real     = realpath( $file );
			$file     = false === $real ? $file : $real;
			$relative = str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : $file;

			if ( str_starts_with( $relative, 'src/' ) ) {
				$read[] = $relative;
			} elseif ( ! str_starts_with( $relative, 'vendor/' ) && ! in_array( $relative, array( 'tests/Support/data-registry-probe.php', 'tests/bootstrap-unit.php' ), true ) ) {
				$foreign[] = $relative;
			}
		}

		$this->assertContains( 'src/Platform/DataRegistry/DataRegistry.php', $read, 'The probe recorded no registry class among the files it loaded: the recording is broken.' );
		$this->assertSame( array(), $foreign, "Building the registry loaded files that are neither the plugin's classes nor Composer's:\n  " . implode( "\n  ", $foreign ) . "\n" );
	}

	/**
	 * Tests that the production list registers the migrator's own tables, their migration and the capability declaration.
	 *
	 * @since 0.1.0
	 */
	public function test_the_production_list_registers_the_platform_tables_and_the_capabilities(): void {
		$registry = OwnedData::registry();

		$this->assertEquals( PlatformTables::migrations(), $registry->tableNamed( 'migrations' ), 'The registry must hold the declaration exactly as the owning module\'s factory returns it.' );
		$this->assertEquals( PlatformTables::locks(), $registry->tableNamed( 'locks' ) );
		$this->assertInstanceOf( PlatformBootstrapMigration::class, $registry->migrations()[0] ?? null, 'The chain starts with the migration that creates the migrator\'s own tables.' );

		$declaration = new CapabilityDeclaration();

		$this->assertSame( $declaration->roles(), $registry->capabilities()->roles() );
		$this->assertSame( $declaration->primitives(), $registry->capabilities()->primitives() );
	}

	/**
	 * Tests that the registered tables are exactly those the production schema migrations declare, each in the same shape.
	 *
	 * Where several migrations declare one table, the latest (by id) states its current shape.
	 *
	 * Planted violations: registering `locks` without its migration's declaring it (drop it from
	 * PlatformBootstrapMigration::tables()); and registering a table no migration declares.
	 *
	 * @since 0.1.0
	 */
	public function test_the_registered_tables_are_those_the_production_migrations_create(): void {
		$registry = OwnedData::registry();
		$declared = array();

		foreach ( $registry->migrations() as $migration ) {
			if ( $migration instanceof SchemaMigration ) {
				foreach ( $migration->tables() as $table ) {
					$declared[ $table->name() ] = $table;
				}
			}
		}

		$this->assertSame( array(), array_values( array_diff( $registry->tableNames(), array_keys( $declared ) ) ), 'These tables are registered, but no production migration creates them.' );
		$this->assertSame( array(), array_values( array_diff( array_keys( $declared ), $registry->tableNames() ) ), 'A production migration creates these tables, but no module registers them.' );

		foreach ( $registry->tables() as $table ) {
			$this->assertEquals( $declared[ $table->name() ], $table, sprintf( 'The registered declaration of %s is not the one its migration creates.', $table->name() ) );
		}
	}
}
