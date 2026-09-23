<?php
/**
 * Tests that everything the plugin puts in a site is registered, and every registered column is classified
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\DataRegistry;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- These tests read information_schema and the options table directly, and drop the base class's fixture table, on purpose.

/**
 * The production data registry against a real database.
 *
 * Each test that needs tables applies the registry's own migration chain to an empty test
 * database, the way a new site is installed, and compares what the server then reports with
 * what the registry says: the set of plugin tables both ways, each table's shape, and every
 * column's classification. The two checks that need no database repeat, over the whole
 * production list, what construction already enforces: ColumnSpec refuses a `pii` column
 * without privacy handling, and the registry refuses an unknown retention id.
 *
 * The options check reads the options table for every `seocart_` option and requires each to
 * be registered. Nothing activates the plugin in the test site yet, so there is nothing of ours
 * to find today; the test first plants an unregistered option and requires the scan to find it,
 * so an empty result cannot pass by accident. It becomes the real check the day activation
 * writes options.
 *
 * Every test names its planted violation.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class CoverageTest extends DatabaseTestCase {

	/**
	 * The instant the frozen clock shows while migrating.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-23 12:00:00';

	/**
	 * The unregistered option the options check plants to prove it looks.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLANTED_OPTION = 'seocart_coverage_planted';

	/**
	 * Tests that every plugin table on the site is registered, and every registered table exists.
	 *
	 * Planted violations: `locks` left out of the production list's tables (a table nobody
	 * registers); a table registered with no migration to create it.
	 *
	 * @since 0.1.0
	 */
	public function test_every_plugin_table_is_registered_and_every_registered_table_exists(): void {
		$registry   = $this->migrateEmptyDatabase();
		$actual     = $this->pluginTables();
		$registered = array_map( fn( string $name ): string => $this->db->table( $name ), $registry->tableNames() );

		$this->assertNotSame( array(), $actual, 'The migrations created no plugin table at all.' );
		$this->assertSame( array(), array_values( array_diff( $actual, $registered ) ), 'These plugin tables exist on the site, but no module registers them.' );
		$this->assertSame( array(), array_values( array_diff( $registered, $actual ) ), 'These tables are registered, but the production migrations did not create them.' );
	}

	/**
	 * Tests that every registered table is exactly what its declaration says: SchemaVerifier finds no difference.
	 *
	 * Planted violation: the production list registers `locks` with an index the migration does
	 * not create.
	 *
	 * @since 0.1.0
	 */
	public function test_every_registered_table_matches_its_declaration(): void {
		$registry = $this->migrateEmptyDatabase();
		$verifier = new SchemaVerifier( $this->db );
		$diff     = array();

		foreach ( $registry->tables() as $table ) {
			$diff = array_merge( $diff, $verifier->diff( $table ) );
		}

		$this->assertSame( array(), $diff, 'A registered table differs from its declaration.' );
	}

	/**
	 * Tests that every column of every plugin table on the site is declared, and so classified.
	 *
	 * A ColumnSpec cannot be built without a classification, so a declared column is a classified
	 * one; what can go wrong is a column the server has and no declaration names. This reads the
	 * columns from information_schema, not from the declarations.
	 *
	 * Planted violation: the production list registers `locks` without its `holder` column.
	 *
	 * @since 0.1.0
	 */
	public function test_every_column_of_every_plugin_table_is_classified(): void {
		$registry     = $this->migrateEmptyDatabase();
		$prefix       = $this->db->table( '' );
		$unclassified = array();
		$classified   = array();

		foreach ( $this->pluginTables() as $table ) {
			$declared = array();

			foreach ( $registry->tableNamed( substr( $table, strlen( $prefix ) ) )?->columns() ?? array() as $column ) {
				$declared[ $column->name() ] = $column;
			}

			foreach ( $this->db->fetchAll( 'SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table ) as $row ) {
				$name = strtolower( (string) $row['name'] );

				if ( isset( $declared[ $name ] ) ) {
					$classified[ $declared[ $name ]->classification()->value ][] = $table . '.' . $name;
				} else {
					$unclassified[] = $table . '.' . $name;
				}
			}
		}

		$this->assertNotSame( array(), $classified, 'No column was read from information_schema, so the check proved nothing.' );
		$this->assertSame( array(), $unclassified, 'These columns exist on the site, but no declaration classifies them.' );
	}

	/**
	 * Tests that every registered pii column says how the privacy exporter and eraser treat it.
	 *
	 * ColumnSpec refuses to be built otherwise; this walks the production list anyway, through
	 * the same per-class listing the exporter and eraser will read, and checks that the listing
	 * covers every registered column so that no pii column can be missed by it.
	 *
	 * Planted violation: `locks.holder` declared pii without handling, with ColumnSpec's refusal
	 * removed.
	 *
	 * @since 0.1.0
	 */
	public function test_every_pii_column_declares_its_privacy_handling(): void {
		$registry  = OwnedData::registry();
		$columns   = 0;
		$listed    = 0;
		$unhandled = array();

		foreach ( $registry->tables() as $table ) {
			$columns += count( $table->columns() );
		}

		foreach ( Classification::cases() as $classification ) {
			foreach ( $registry->columnsClassified( $classification ) as $table => $classified ) {
				$listed += count( $classified );

				foreach ( $classified as $column ) {
					if ( Classification::Pii === $classification && ! self::handled( $column ) ) {
						$unhandled[] = $table . '.' . $column->name();
					}
				}
			}
		}

		$this->assertGreaterThan( 0, $columns, 'The production list registers no column.' );
		$this->assertSame( $columns, $listed, 'The per-class listing does not cover every registered column once, so a pii column could escape it.' );
		$this->assertSame( array(), $unhandled, 'These pii columns do not say how the privacy exporter and eraser treat them.' );
	}

	/**
	 * Tests that every registered table names a retention policy the catalog declares.
	 *
	 * Planted violation: `locks` declared with the retention id `forever`, with the registry's own
	 * refusal removed.
	 *
	 * @since 0.1.0
	 */
	public function test_every_retention_policy_is_in_the_catalog(): void {
		$registry = OwnedData::registry();
		$unknown  = array();

		foreach ( $registry->tables() as $table ) {
			if ( ! $registry->retention()->has( $table->retention() ) ) {
				$unknown[] = $table->name() . ': ' . $table->retention();
			}
		}

		$this->assertNotSame( array(), $registry->tables(), 'The production list registers no table.' );
		$this->assertSame( array(), $unknown, 'These tables name a retention policy the catalog does not declare.' );
	}

	/**
	 * Tests that every `seocart_` option in the options table is registered.
	 *
	 * Nothing activates the plugin in the test site yet, so today the only option the scan finds
	 * is the one planted here to prove it looks; see the class description.
	 *
	 * Planted violations: an unregistered `seocart_` option left in the options table; the scan's
	 * pattern broken, so that it no longer finds the planted option.
	 *
	 * @since 0.1.0
	 */
	public function test_every_plugin_option_is_registered(): void {
		$registered = OwnedData::registry()->optionNames();

		$this->assertNotContains( self::PLANTED_OPTION, $registered, 'The planted option must be one the registry does not know.' );

		add_option( self::PLANTED_OPTION, 'planted', '', false );

		try {
			$this->assertContains( self::PLANTED_OPTION, $this->pluginOptions(), 'The scan did not find an unregistered option planted for it, so an empty result would prove nothing.' );
		} finally {
			delete_option( self::PLANTED_OPTION );
		}

		$this->assertSame( array(), array_values( array_diff( $this->pluginOptions(), $registered ) ), 'These plugin options are in the options table, but no module registers them.' );
	}

	/**
	 * Applies the production migration chain to an empty test database, the way a new site is installed.
	 *
	 * The base class's fixture table is dropped first: it is not the plugin's, and the database
	 * must hold nothing but what the migrations create.
	 *
	 * @since 0.1.0
	 *
	 * @return DataRegistry The production registry whose migrations were applied.
	 */
	private function migrateEmptyDatabase(): DataRegistry {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->rowsTable() ) );

		$this->assertSame( array(), $this->pluginTables(), 'The test database already holds plugin tables, so the migrations would not start from an empty one.' );

		$registry = OwnedData::registry();
		$migrator = new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $registry->migrations(), FrozenClock::at( self::NOW ), $this->reporter() );
		$report   = $migrator->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( MigrationReport::APPLIED, $report->outcome() );
		$this->assertSame(
			array_map( static fn( $migration ): string => $migration->id(), $registry->migrations() ),
			array_column( $report->applied(), 'id' ),
			'Every production migration applies.'
		);

		return $registry;
	}

	/**
	 * Lists the names of every option in the options table that starts with `seocart_`.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Option names.
	 */
	private function pluginOptions(): array {
		global $wpdb;

		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'seocart_' ) . '%' )
			)
		);
	}

	/**
	 * Tells whether a pii column says how the privacy tools treat it, read from what it declares.
	 *
	 * Read from the column's accessors, not from ColumnSpec's own check, so that a broken check
	 * cannot pass itself: the eraser needs one of the three erasures and a reason to retain, the
	 * exporter needs either to include the column or a reason not to.
	 *
	 * @since 0.1.0
	 *
	 * @param ColumnSpec $column The column.
	 * @return bool True when both tools know what to do with it.
	 */
	private static function handled( ColumnSpec $column ): bool {
		$erasure = $column->erasure();

		return in_array( $erasure, array( ColumnSpec::ERASE_DESTROY, ColumnSpec::ERASE_ANONYMIZE, ColumnSpec::ERASE_RETAIN ), true )
			&& ( ColumnSpec::ERASE_RETAIN !== $erasure || null !== $column->retainedBecause() )
			&& ( $column->isExported() || null !== $column->notExportedBecause() );
	}
}
