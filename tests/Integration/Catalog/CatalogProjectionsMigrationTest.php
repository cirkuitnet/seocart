<?php
/**
 * Tests the two migrations that create the catalog's derived tables: the price projections and the search index
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreateCatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\CreatePriceProjections;
use SEOCart\Catalog\Infrastructure\Migrations\CreateSearchIndex;
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
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelTestCase;

/**
 * A store installed before the derived tables gets each from its own step, exactly as declared and with the DDL its declaration writes; a store installed since sends none; writes wait for both.
 *
 * The CREATE TABLE each step sends is compared byte for byte with the statement written out
 * here, which is the one the owner approved: a change to a declaration or to the generator that
 * changes it fails here before it reaches a store.
 *
 * Planted violation: in CatalogTables::priceProjections(), leave out the `currency_price` key.
 * The statement then differs from the approved one, and on a store that has the table the
 * migrator's post-condition names the key as not declared.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class CatalogProjectionsMigrationTest extends KernelTestCase {

	/**
	 * The statement that creates the price projections, as approved: `{prefix}` and `{charset_collate}` are the site's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PRICE_PROJECTIONS_DDL = "CREATE TABLE `{prefix}seocart_product_price_projections` (\n"
		. "\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
		. "\t`product_id` bigint unsigned NOT NULL,\n"
		. "\t`currency` char(3) COLLATE ascii_bin NOT NULL,\n"
		. "\t`sort_price_minor` bigint NOT NULL,\n"
		. "\t`amount_basis` varchar(5) COLLATE ascii_bin NOT NULL,\n"
		. "\t`has_explicit_price` tinyint(1) NOT NULL,\n"
		. "\t`rate_version` bigint unsigned NULL,\n"
		. "\t`created_at` datetime NOT NULL,\n"
		. "\t`updated_at` datetime(6) NOT NULL,\n"
		. "\tPRIMARY KEY  (`id`),\n"
		. "\tUNIQUE KEY `product_currency` (`product_id`,`currency`),\n"
		. "\tKEY `currency_price` (`currency`,`sort_price_minor`,`product_id`),\n"
		. "\tKEY `stale_converted` (`has_explicit_price`,`rate_version`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * The statement that creates the search index, as approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SEARCH_INDEX_DDL = "CREATE TABLE `{prefix}seocart_product_search_index` (\n"
		. "\t`product_id` bigint unsigned NOT NULL,\n"
		. "\t`locale` varchar(20) COLLATE ascii_bin NOT NULL,\n"
		. "\t`search_document` text NOT NULL,\n"
		. "\t`created_at` datetime NOT NULL,\n"
		. "\t`updated_at` datetime(6) NOT NULL,\n"
		. "\tPRIMARY KEY  (`product_id`,`locale`),\n"
		. "\tFULLTEXT KEY `search_document` (`search_document`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * Tests that, on a store whose catalog was created before them, each step creates its table once with the approved statement, the head moves, and a second run sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_each_step_creates_its_table_on_an_earlier_store(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables() );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A store migrated before the derived tables were declared.
		foreach ( array( CatalogTables::PRICE_PROJECTIONS, CatalogTables::SEARCH_INDEX ) as $table ) {
			$this->db->execute( 'DROP TABLE %i', $this->db->table( $table ) );
		}

		$verifier = new SchemaVerifier( $this->db );
		$steps    = array(
			array( new CreatePriceProjections(), CatalogTables::priceProjections(), self::PRICE_PROJECTIONS_DDL ),
			array( new CreateSearchIndex(), CatalogTables::searchIndex(), self::SEARCH_INDEX_DDL ),
		);
		$chain    = $before;

		foreach ( $steps as list( $step, $table, $approved ) ) {
			$chain[] = $step;
			$report  = null;
			$log     = $this->captureQueries(
				function () use ( $chain, &$report ): void {
					$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
				}
			);

			$this->assertSame( array( $step->id() ), array_column( $report->applied(), 'id' ) );
			$this->assertSame( $step->id(), ( new MigrationsTableState( $this->db ) )->schemaHead() );
			$sent = $this->approved( $approved );

			$this->assertSame( $sent, ( new DdlGenerator() )->createTable( $table, $this->db->table( $table->name() ), $this->db->charsetCollate() ), 'The declaration writes the approved statement, byte for byte.' );
			$this->assertSame( array( trim( (string) preg_replace( '/\s+/', ' ', $sent ) ) ), $log->ofType( 'CREATE' )->sqls(), 'The step sends it, once; the log keeps it with its white space collapsed.' );
			$this->assertQueryCount( 0, $log->ofType( 'ALTER', 'DROP' ), 'Other DDL of the step' );
			$this->assertSame( array(), $verifier->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), $verifier );
		$rerun      = $this->captureQueries(
			static function () use ( $operations ): void {
				( new CreatePriceProjections() )->up( $operations );
				( new CreateSearchIndex() )->up( $operations );
			}
		);

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when each up() runs again on its table' );
	}

	/**
	 * Tests that a store installed since gets both tables from the catalog's own migration, and the two steps then send no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_a_store_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) );
		$log        = $this->captureQueries(
			static function () use ( $operations ): void {
				( new CreatePriceProjections() )->up( $operations );
				( new CreateSearchIndex() )->up( $operations );
			}
		);

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a store that has both tables' );
	}

	/**
	 * Tests the steps' places and what they declare: after the catalog's tables, in id order, each its one table, the store not trading while either is pending.
	 *
	 * @since 0.2.0
	 */
	public function test_the_steps_follow_the_catalog_and_hold_the_store_while_pending(): void {
		$ids = array_map( static fn( Migration $migration ): string => $migration->id(), OwnedData::registry()->migrations() );

		$this->assertSame( array( CreateCatalogTables::ID, CreatePriceProjections::ID, CreateSearchIndex::ID ), array_values( array_intersect( $ids, array( CreateCatalogTables::ID, CreatePriceProjections::ID, CreateSearchIndex::ID ) ) ) );
		$this->assertSame( '20261031_0002_catalog_price_projections', CreatePriceProjections::ID );
		$this->assertSame( '20261031_0003_catalog_search_index', CreateSearchIndex::ID );
		$this->assertEquals( array( CatalogTables::priceProjections() ), ( new CreatePriceProjections() )->tables() );
		$this->assertEquals( array( CatalogTables::searchIndex() ), ( new CreateSearchIndex() )->tables() );
		$this->assertFalse( ( new CreatePriceProjections() )->canOperateHalfApplied() );
		$this->assertFalse( ( new CreateSearchIndex() )->canOperateHalfApplied() );

		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables(), new CreatePriceProjections(), new CreateSearchIndex() );
		$report = $this->reporter();
		$states = array();

		foreach ( array( CreateCatalogTables::ID, CreatePriceProjections::ID, CreateSearchIndex::ID ) as $head ) {
			$this->plantRecord( self::installedRecord( $head ) );

			$gate = $this->container( array( Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, $chain, $report ) ) )->get( SchemaGate::class );

			$states[ $head ] = array( $gate->state(), $gate->writesBlocked() );
		}

		$this->assertSame(
			array(
				CreateCatalogTables::ID    => array( GateState::CodeNewer, true ),
				CreatePriceProjections::ID => array( GateState::CodeNewer, true ),
				CreateSearchIndex::ID      => array( GateState::Ready, false ),
			),
			$states,
			'Writes wait while either step is pending.'
		);
	}

	/**
	 * Returns an approved statement as this site writes it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement The statement, with `{prefix}` and `{charset_collate}`.
	 * @return string The statement.
	 */
	private function approved( string $statement ): string {
		return rtrim( str_replace( array( '{prefix}', '{charset_collate}' ), array( $this->db->prefix(), $this->db->charsetCollate() ), $statement ) );
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
		return new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-10-31 12:00:00' ), $this->reporter() );
	}
}
