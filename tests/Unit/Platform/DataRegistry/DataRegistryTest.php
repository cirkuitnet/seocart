<?php
/**
 * Tests the data registry: what it collects from the modules, and the registrations it refuses
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\DataRegistry;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\DataRegistry\Contribution;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;

/**
 * The registry collects each module's contribution once and refuses what could not be right.
 *
 * Each refusal names its planted violation: the check in DataRegistry, OptionDefinition or
 * Contribution that the case exists for, removed.
 *
 * @since 0.1.0
 */
final class DataRegistryTest extends TestCase {

	/**
	 * Tests that the registry holds what every contribution names, and the capability declaration it was given.
	 *
	 * @since 0.1.0
	 */
	public function test_it_collects_what_every_module_contributes(): void {
		$capabilities = new CapabilityDeclaration();
		$orders       = self::table( 'orders' );
		$lines        = self::table( 'order_lines' );
		$boot         = self::option( 'seocart_boot', true );
		$currency     = self::option( 'seocart_international_base_currency' );

		$registry = new DataRegistry(
			$capabilities,
			new Contribution( tables: array( $orders ), options: array( $boot ), jobGroups: array( 'seocart' => 'Jobs' ) ),
			new Contribution( tables: array( $lines ), options: array( $currency ) ),
		);

		$this->assertSame( array( $orders, $lines ), $registry->tables() );
		$this->assertSame( array( 'orders', 'order_lines' ), $registry->tableNames() );
		$this->assertSame( $lines, $registry->tableNamed( 'order_lines' ) );
		$this->assertNull( $registry->tableNamed( 'refunds' ), 'A table no module registers is not there.' );
		$this->assertSame( array( $boot, $currency ), $registry->options() );
		$this->assertSame( array( 'seocart_boot', 'seocart_international_base_currency' ), $registry->optionNames() );
		$this->assertSame( array( 'seocart' => 'Jobs' ), $registry->jobGroups() );
		$this->assertSame( $capabilities, $registry->capabilities(), 'Capabilities and roles are the declaration itself, never a copy.' );
		$this->assertTrue( $registry->retention()->has( 'permanent' ) );
	}

	/**
	 * Tests that the migration chain is in id order, whatever order the modules are listed in.
	 *
	 * Planted violation: the ksort() of the migrations in the constructor removed.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_chain_is_in_id_order(): void {
		$late  = new CreatesTestTable( '20990102_0001_late', 'late' );
		$early = new CreatesTestTable( '20990101_0002_early', 'early' );
		$first = new CreatesTestTable( '20990101_0001_first', 'first' );

		$registry = self::registry(
			new Contribution( migrations: array( $late ) ),
			new Contribution( migrations: array( $early, $first ) ),
		);

		$this->assertSame( array( $first, $early, $late ), $registry->migrations() );
	}

	/**
	 * Tests that columns are listed by class, and that the four classes between them list every column once.
	 *
	 * @since 0.1.0
	 */
	public function test_columns_are_listed_by_class(): void {
		$email  = new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Where order mail goes.', erasure: ColumnSpec::ERASE_DESTROY );
		$total  = new ColumnSpec( 'total_minor', 'bigint', Classification::Financial, 'The total, in minor units.' );
		$key    = new ColumnSpec( 'access_key_hash', 'varchar(255)', Classification::Secret, 'Hash of the access key.' );
		$body   = new ColumnSpec( 'body', 'longtext', Classification::Pii, 'The note.', erasure: ColumnSpec::ERASE_DESTROY );
		$orders = self::table( 'orders', array( $email, $total, $key ) );
		$notes  = self::table( 'order_notes', array( $body ) );

		$registry = self::registry( new Contribution( tables: array( $orders, self::table( 'tax_classes' ), $notes ) ) );

		$this->assertSame(
			array(
				'orders'      => array( $email ),
				'order_notes' => array( $body ),
			),
			$registry->columnsClassified( Classification::Pii ),
			'Each table with a column of the class, in registration order; a table with none is left out.'
		);
		$this->assertSame( array( 'orders' => array( $total ) ), $registry->columnsClassified( Classification::Financial ) );
		$this->assertSame( array( 'orders' => array( $key ) ), $registry->columnsClassified( Classification::Secret ) );

		$listed = 0;

		foreach ( Classification::cases() as $classification ) {
			foreach ( $registry->columnsClassified( $classification ) as $columns ) {
				$listed += count( $columns );
			}
		}

		$this->assertSame( 4 + 1 + 2, $listed, 'Every column is listed under exactly one class.' );
	}

	/**
	 * Tests that a table kept permanently may have a created_at column when its purpose says why, and needs no reason without one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_permanent_table_with_a_created_at_column_is_accepted_when_its_purpose_says_why(): void {
		$created = new ColumnSpec( 'created_at', 'datetime', Classification::Public, 'UTC.' );

		$registry = self::registry(
			new Contribution(
				tables: array(
					self::table( 'rates', array( $created ), 'permanent', 'Versioned rates, kept permanently because a frozen order references its rate version.' ),
					self::table( 'currencies', array(), 'permanent', 'Enabled currencies.' ),
					self::table( 'holds', array( $created ), 'stock_holds', 'Expiring checkout holds.' ),
				)
			)
		);

		$this->assertSame( array( 'rates', 'currencies', 'holds' ), $registry->tableNames() );
	}

	/**
	 * Lists registrations the registry must refuse, each with a fragment of the message that says why.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{\Closure(): mixed, string}> The attempts.
	 */
	public static function refusedRegistrations(): array {
		$created = static fn(): ColumnSpec => new ColumnSpec( 'created_at', 'datetime', Classification::Public, 'UTC.' );

		return array(
			// Planted violation: the duplicate check in registerTable() removed.
			'a table twice, across modules'            => array(
				static fn() => self::registry( new Contribution( tables: array( self::table( 'orders' ) ) ), new Contribution( tables: array( self::table( 'orders' ) ) ) ),
				'Table orders is registered twice.',
			),
			// Planted violation: the duplicate check on migrations removed.
			'a migration id twice'                     => array(
				static fn() => self::registry( new Contribution( migrations: array( new CreatesTestTable( '20990101_0001_a', 'a' ) ) ), new Contribution( migrations: array( new CreatesTestTable( '20990101_0001_a', 'b' ) ) ) ),
				'Migration 20990101_0001_a is registered twice.',
			),
			// Planted violation: the duplicate check on options removed.
			'an option twice'                          => array(
				static fn() => self::registry( new Contribution( options: array( self::option( 'seocart_store_name' ) ) ), new Contribution( options: array( self::option( 'seocart_store_name' ) ) ) ),
				'Option seocart_store_name is registered twice.',
			),
			// Planted violation: the duplicate check on job groups removed.
			'a job group twice'                        => array(
				static fn() => self::registry( new Contribution( jobGroups: array( 'seocart' => 'Jobs' ) ), new Contribution( jobGroups: array( 'seocart' => 'Events' ) ) ),
				'Job group seocart is registered twice.',
			),
			// Planted violation: the retention check in registerTable() removed.
			'an unknown retention policy'              => array(
				static fn() => self::registry( new Contribution( tables: array( self::table( 'orders', array(), '7 years' ) ) ) ),
				'names the retention policy "7 years", which the catalog does not declare',
			),
			// Planted violation: the permanence check in registerTable() removed.
			'permanent with created_at and no reason'  => array(
				static fn() => self::registry( new Contribution( tables: array( self::table( 'ledger', array( $created() ), 'permanent', 'Every stock movement.' ) ) ) ),
				'Table ledger has a created_at column and keeps its rows permanently, so its purpose must say why',
			),
			'permanent with created_at and a bare "permanent"' => array(
				static fn() => self::registry( new Contribution( tables: array( self::table( 'ledger', array( $created() ), 'permanent', 'Every stock movement, kept permanently.' ) ) ) ),
				'so its purpose must say why',
			),
			// Planted violation: the autoload count check removed.
			'two autoloaded options'                   => array(
				static fn() => self::registry( new Contribution( options: array( self::option( 'seocart_boot', true ), self::option( 'seocart_other', true ) ) ) ),
				'Only one plugin option may autoload, and seocart_boot, seocart_other all do.',
			),
			// Planted violation: the secret check in OptionDefinition removed.
			'an autoloaded secret'                     => array(
				static fn() => new OptionDefinition( 'seocart_secrets_data_key', 'Secrets', 'The data key.', true, Classification::Secret ),
				'holds a secret, and a secret never autoloads',
			),
			'an option outside the plugin prefix'      => array(
				static fn() => self::option( 'store_name' ),
				'must be seocart_ followed by lowercase snake_case',
			),
			'an option name in capitals'               => array(
				static fn() => self::option( 'seocart_StoreName' ),
				'must be seocart_ followed by lowercase snake_case',
			),
			'an option name longer than its column'    => array(
				static fn() => self::option( 'seocart_' . str_repeat( 'x', 184 ) ),
				'191 characters at most',
			),
			'an option without a purpose'              => array(
				static fn() => new OptionDefinition( 'seocart_boot', 'Kernel', ' ', true, Classification::Public ),
				'Option seocart_boot needs a purpose.',
			),
			'an option without a module'               => array(
				static fn() => new OptionDefinition( 'seocart_boot', '', 'The boot record.', true, Classification::Public ),
				'Option seocart_boot needs a module.',
			),
			// Planted violation: the name check in Contribution's constructor removed.
			'a job group outside the plugin namespace' => array(
				static fn() => new Contribution( jobGroups: array( 'woocommerce' => 'Jobs' ) ),
				'The job group "woocommerce" is not in the plugin\'s namespace',
			),
			'a job group without its module'           => array(
				static fn() => new Contribution( jobGroups: array( 'seocart' => ' ' ) ),
				'The job group seocart needs an owning module.',
			),
		);
	}

	/**
	 * Tests that a registration that could not be right is refused, and says why.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider refusedRegistrations
	 *
	 * @param \Closure $register Builds the registration.
	 * @param string   $message  A fragment of the message that must explain the refusal.
	 */
	public function test_a_registration_that_could_not_be_right_is_refused( \Closure $register, string $message ): void {
		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( $message );

		$register();
	}

	/**
	 * Builds a registry over the production capability declaration.
	 *
	 * @since 0.1.0
	 *
	 * @param Contribution ...$contributions What each module owns.
	 * @return DataRegistry The registry.
	 */
	private static function registry( Contribution ...$contributions ): DataRegistry {
		return new DataRegistry( new CapabilityDeclaration(), ...$contributions );
	}

	/**
	 * Declares a fixture table: an id, then the given columns.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $name      The unprefixed name.
	 * @param ColumnSpec[] $columns   Optional. Columns after the id. Default none.
	 * @param string       $retention Optional. The retention policy id. Default `entity_lifetime`.
	 * @param string       $purpose   Optional. The purpose. Default a fixed sentence.
	 * @return TableDefinition The declaration.
	 */
	private static function table( string $name, array $columns = array(), string $retention = 'entity_lifetime', string $purpose = 'A fixture table.' ): TableDefinition {
		return new TableDefinition(
			$name,
			'Tests',
			$purpose,
			MutationPattern::Config,
			array_merge( array( new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ) ), $columns ),
			array( 'id' ),
			array(),
			array(),
			$retention,
			array()
		);
	}

	/**
	 * Declares a fixture option of class `public`.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name     The option name.
	 * @param bool   $autoload Optional. Whether it autoloads. Default false.
	 * @return OptionDefinition The declaration.
	 */
	private static function option( string $name, bool $autoload = false ): OptionDefinition {
		return new OptionDefinition( $name, 'Tests', 'A fixture option.', $autoload, Classification::Public );
	}
}
