<?php
/**
 * Tests the migration that creates the option tables and adds the variant's label
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Catalog;

use SEOCart\Catalog\Infrastructure\CatalogTables;
use SEOCart\Catalog\Infrastructure\Migrations\AddProductOptions;
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
 * A store installed before product options gets the three option tables and the variant's label from one step, exactly as declared and with the approved DDL; a store installed since sends none; writes wait for it.
 *
 * Each CREATE TABLE the step sends, and its ALTER, are compared byte for byte with the statements
 * written out here, which are the ones the owner approved: a change to a declaration or to the
 * generator that changes them fails here before it reaches a store.
 *
 * Planted violation: in AddProductOptions::up(), leave out the addColumn() of the label. The step
 * then sends no ALTER, and the migrator's post-condition names the column `variants` lacks.
 *
 * @since 0.2.0
 *
 * @group migration
 */
final class ProductOptionsMigrationTest extends KernelTestCase {

	/**
	 * The statement that creates the option axes, as approved: `{prefix}` and `{charset_collate}` are the site's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OPTION_AXES_DDL = "CREATE TABLE `{prefix}seocart_option_axes` (\n"
		. "\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
		. "\t`product_id` bigint unsigned NOT NULL,\n"
		. "\t`axis_key` varchar(32) COLLATE ascii_bin NOT NULL,\n"
		. "\t`label` varchar(100) NOT NULL,\n"
		. "\t`position` int unsigned NOT NULL DEFAULT '0',\n"
		. "\t`created_at` datetime NOT NULL,\n"
		. "\t`updated_at` datetime NOT NULL,\n"
		. "\tPRIMARY KEY  (`id`),\n"
		. "\tUNIQUE KEY `product_axis` (`product_id`,`axis_key`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * The statement that creates the option values, as approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OPTION_VALUES_DDL = "CREATE TABLE `{prefix}seocart_option_values` (\n"
		. "\t`id` bigint unsigned NOT NULL AUTO_INCREMENT,\n"
		. "\t`axis_id` bigint unsigned NOT NULL,\n"
		. "\t`value_key` varchar(32) COLLATE ascii_bin NOT NULL,\n"
		. "\t`label` varchar(100) NOT NULL,\n"
		. "\t`position` int unsigned NOT NULL DEFAULT '0',\n"
		. "\t`image_attachment_id` bigint unsigned NULL,\n"
		. "\t`swatch_hex` char(7) COLLATE ascii_bin NULL,\n"
		. "\t`created_at` datetime NOT NULL,\n"
		. "\t`updated_at` datetime NOT NULL,\n"
		. "\tPRIMARY KEY  (`id`),\n"
		. "\tUNIQUE KEY `axis_value` (`axis_id`,`value_key`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * The statement that creates the table of the values each variant selects, as approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const VARIANT_OPTION_VALUES_DDL = "CREATE TABLE `{prefix}seocart_variant_option_values` (\n"
		. "\t`variant_id` bigint unsigned NOT NULL,\n"
		. "\t`option_value_id` bigint unsigned NOT NULL,\n"
		. "\t`axis_id` bigint unsigned NOT NULL,\n"
		. "\tPRIMARY KEY  (`variant_id`,`option_value_id`),\n"
		. "\tKEY `option_value` (`option_value_id`),\n"
		. "\tKEY `variant_axis` (`variant_id`,`axis_id`)\n"
		. ') ENGINE=InnoDB {charset_collate}';

	/**
	 * The statement that adds the label to `variants`, as approved.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LABEL_DDL = 'ALTER TABLE `{prefix}seocart_variants` ADD COLUMN `label` varchar(255) NULL';

	/**
	 * Tests that, on a store whose catalog was created before options, the step creates the three tables and adds the label once with the approved statements, the head moves, and a second run sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_creates_the_tables_and_adds_the_label_on_an_earlier_store(): void {
		$before = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables(), new CreatePriceProjections(), new CreateSearchIndex() );

		$this->migrator( $before )->migrate( new MigrationRunOptions( 0 ) );

		// A store migrated before options were declared.
		foreach ( array( CatalogTables::VARIANT_OPTION_VALUES, CatalogTables::OPTION_VALUES, CatalogTables::OPTION_AXES ) as $table ) {
			$this->db->execute( 'DROP TABLE %i', $this->db->table( $table ) );
		}

		$this->db->execute( 'ALTER TABLE %i DROP COLUMN label', $this->db->table( CatalogTables::VARIANTS ) );

		$chain  = array_merge( $before, array( new AddProductOptions() ) );
		$report = null;
		$log    = $this->captureQueries(
			function () use ( $chain, &$report ): void {
				$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);

		$this->assertSame( array( AddProductOptions::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( AddProductOptions::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		$generator = new DdlGenerator();
		$created   = array();

		foreach ( array( array( CatalogTables::optionAxes(), self::OPTION_AXES_DDL ), array( CatalogTables::optionValues(), self::OPTION_VALUES_DDL ), array( CatalogTables::variantOptionValues(), self::VARIANT_OPTION_VALUES_DDL ) ) as list( $table, $approved ) ) {
			$sent = $this->approved( $approved );

			$this->assertSame( $sent, $generator->createTable( $table, $this->db->table( $table->name() ), $this->db->charsetCollate() ), "The declaration of {$table->name()} writes the approved statement, byte for byte." );

			$created[] = trim( (string) preg_replace( '/\s+/', ' ', $sent ) );
		}

		$this->assertSame( $created, $log->ofType( 'CREATE' )->sqls(), 'The step sends each, once, in order; the log keeps them with their white space collapsed.' );
		$this->assertSame( array( $this->approved( self::LABEL_DDL ) ), $log->ofType( 'ALTER' )->sqls(), 'The label is added once, as approved.' );
		$this->assertQueryCount( 0, $log->ofType( 'DROP' ), 'DROP statements of the step' );

		$verifier = new SchemaVerifier( $this->db );

		foreach ( ( new AddProductOptions() )->tables() as $table ) {
			$this->assertSame( array(), $verifier->diff( $table ), "{$table->name()} matches its declaration exactly." );
		}

		$again = $this->captureQueries( fn() => $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertQueryCount( 0, $again->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );

		$operations = new SchemaOperations( $this->db, $generator, $verifier );
		$rerun      = $this->captureQueries( static fn() => ( new AddProductOptions() )->up( $operations ) );

		$this->assertQueryCount( 0, $rerun->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when up() runs again' );
	}

	/**
	 * Tests that a store installed since gets the tables and the label from the catalog's own migration, and the step then sends no DDL.
	 *
	 * @since 0.2.0
	 */
	public function test_a_store_installed_since_sends_no_ddl(): void {
		$this->migrator( array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables() ) )->migrate( new MigrationRunOptions( 0 ) );

		$verifier = new SchemaVerifier( $this->db );

		foreach ( ( new AddProductOptions() )->tables() as $table ) {
			$this->assertSame( array(), $verifier->diff( $table ), "{$table->name()} is created as declared by the catalog's own migration." );
		}

		$operations = new SchemaOperations( $this->db, new DdlGenerator(), $verifier );
		$log        = $this->captureQueries( static fn() => ( new AddProductOptions() )->up( $operations ) );

		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a store that has the tables and the label' );
	}

	/**
	 * Tests the step's place and what it declares: after the catalog's other steps, the store not trading while it is pending.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_follows_the_catalog_and_holds_the_store_while_pending(): void {
		$catalog = array( CreateCatalogTables::ID, CreatePriceProjections::ID, CreateSearchIndex::ID, AddProductOptions::ID );
		$ids     = array_map( static fn( Migration $migration ): string => $migration->id(), OwnedData::registry()->migrations() );

		$this->assertSame( $catalog, array_values( array_intersect( $ids, $catalog ) ) );
		$this->assertSame( '20261101_0001_catalog_options', AddProductOptions::ID );
		$this->assertEquals( array( CatalogTables::optionAxes(), CatalogTables::optionValues(), CatalogTables::variantOptionValues(), CatalogTables::variants() ), ( new AddProductOptions() )->tables() );
		$this->assertFalse( ( new AddProductOptions() )->canOperateHalfApplied() );

		$chain  = array( new PlatformBootstrapMigration(), new CreateOutboxMigration(), new CreateCatalogTables(), new CreatePriceProjections(), new CreateSearchIndex(), new AddProductOptions() );
		$report = $this->reporter();
		$states = array();

		foreach ( array( CreateSearchIndex::ID, AddProductOptions::ID ) as $head ) {
			$this->plantRecord( self::installedRecord( $head ) );

			$gate = $this->container( array( Migrator::class => static fn( Container $c ): Migrator => KernelContainer::migrator( $c, $chain, $report ) ) )->get( SchemaGate::class );

			$states[ $head ] = array( $gate->state(), $gate->writesBlocked() );
		}

		$this->assertSame(
			array(
				CreateSearchIndex::ID => array( GateState::CodeNewer, true ),
				AddProductOptions::ID => array( GateState::Ready, false ),
			),
			$states,
			'Writes wait while the step is pending.'
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
