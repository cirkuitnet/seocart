<?php
/**
 * DataRegistrySnapshot: everything the data registry answers, as plain data
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\TableDefinition;
use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\DataRegistry\Contribution;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OptionDefinition;
use SEOCart\Platform\DataRegistry\OwnedData;

/**
 * Builds the data registry and asks it, and everything it returns, every question they answer.
 *
 * The registry must be pure data: building it and reading it may call no WordPress function.
 * OwnedDataTest proves that by taking this snapshot in a process without WordPress,
 * tests/Support/data-registry-probe.php, and comparing it with the snapshot taken in the test
 * process. Both sides run this same code, so a difference means the registry depends on its
 * surroundings, and a WordPress call on any path read here is a fatal error in the probe.
 *
 * Two registries are read. The production one, from OwnedData, is what the plugin runs with.
 * The fixture one registers what no module registers yet (an option, a job group, a table with
 * keys, an orphan policy and a column of every class, pii with each kind of privacy handling),
 * so that the accessors behind them are read too; it is replaced by the production list as the
 * modules arrive.
 *
 * @since 0.1.0
 */
final class DataRegistrySnapshot {

	/**
	 * Builds both registries and reads everything they answer.
	 *
	 * @since 0.1.0
	 *
	 * @return array{production: array<string, mixed>, fixture: array<string, mixed>} The answers, in a form
	 *                                                                                  that survives a JSON
	 *                                                                                  round trip unchanged.
	 */
	public static function take(): array {
		return array(
			'production' => self::read( OwnedData::registry() ),
			'fixture'    => self::read( self::fixture() ),
		);
	}

	/**
	 * Reads everything one registry answers.
	 *
	 * @since 0.1.0
	 *
	 * @param DataRegistry $registry The registry.
	 * @return array<string, mixed> The answers.
	 */
	private static function read( DataRegistry $registry ): array {
		$tables = array();

		foreach ( $registry->tableNames() as $name ) {
			$table           = $registry->tableNamed( $name );
			$tables[ $name ] = null === $table ? null : self::table( $table );
		}

		$classified = array();

		foreach ( Classification::cases() as $classification ) {
			foreach ( $registry->columnsClassified( $classification ) as $table => $columns ) {
				$classified[ $classification->value ][ $table ] = array_map( static fn( ColumnSpec $column ): string => $column->name(), $columns );
			}
		}

		$migrations = array();

		foreach ( $registry->migrations() as $migration ) {
			$migrations[ $migration->id() ] = array(
				'can_operate_half_applied' => $migration->canOperateHalfApplied(),
				'tables'                   => $migration instanceof SchemaMigration ? array_map( static fn( TableDefinition $table ): string => $table->name(), $migration->tables() ) : null,
			);
		}

		$options = array();

		foreach ( $registry->options() as $option ) {
			$options[ $option->name() ] = array(
				'module'         => $option->module(),
				'purpose'        => $option->purpose(),
				'autoloads'      => $option->autoloads(),
				'classification' => $option->classification()->value,
			);
		}

		$capabilities = $registry->capabilities();
		$roles        = array();

		foreach ( $capabilities->roles() as $role ) {
			$roles[ $role ] = $capabilities->bundle( $role );
		}

		$retention = array();

		foreach ( $registry->retention()->ids() as $policy ) {
			$retention[ $policy ] = array(
				'defaults' => $registry->retention()->defaults( $policy ),
			);
		}

		return array(
			'table_names'        => $registry->tableNames(),
			'tables_in_order'    => array_map( static fn( TableDefinition $table ): string => $table->name(), $registry->tables() ),
			'tables'             => $tables,
			'columns_classified' => $classified,
			'migrations'         => $migrations,
			'option_names'       => $registry->optionNames(),
			'options'            => $options,
			'job_groups'         => $registry->jobGroups(),
			'primitives'         => $capabilities->primitives(),
			'meta_capabilities'  => $capabilities->metaCapabilities(),
			'roles'              => $roles,
			'retention'          => $retention,
		);
	}

	/**
	 * Reads everything a table declaration answers, its columns and keys included.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $table The declaration.
	 * @return array<string, mixed> The answers.
	 */
	private static function table( TableDefinition $table ): array {
		$columns = array();

		foreach ( $table->columns() as $column ) {
			$columns[ $column->name() ] = array(
				'type'                 => $column->type(),
				'classification'       => $column->classification()->value,
				'note'                 => $column->note(),
				'nullable'             => $column->nullable(),
				'default'              => $column->defaultValue(),
				'collation'            => $column->collation(),
				'auto_increment'       => $column->autoIncrement(),
				'erasure'              => $column->erasure(),
				'retained_because'     => $column->retainedBecause(),
				'exported'             => $column->isExported(),
				'not_exported_because' => $column->notExportedBecause(),
			);
		}

		return array(
			'name'             => $table->name(),
			'module'           => $table->module(),
			'purpose'          => $table->purpose(),
			'mutation_pattern' => $table->mutationPattern()->value,
			'columns'          => $columns,
			'primary_key'      => $table->primaryKey(),
			'unique_keys'      => self::keys( $table->uniqueKeys() ),
			'indexes'          => self::keys( $table->indexes() ),
			'retention'        => $table->retention(),
			'orphan_policy'    => $table->orphanPolicy(),
		);
	}

	/**
	 * Reads everything a list of index declarations answers.
	 *
	 * @since 0.1.0
	 *
	 * @param IndexSpec[] $indexes The indexes.
	 * @return array<string, array<string, mixed>> Index name => its answers.
	 */
	private static function keys( array $indexes ): array {
		$read = array();

		foreach ( $indexes as $index ) {
			$read[ $index->name() ] = array(
				'columns' => $index->columns(),
				'unique'  => $index->isUnique(),
				'purpose' => $index->purpose(),
			);
		}

		return $read;
	}

	/**
	 * Builds a registry holding what no module registers yet, so its accessors are read too.
	 *
	 * @since 0.1.0
	 *
	 * @return DataRegistry The registry.
	 */
	private static function fixture(): DataRegistry {
		$table = new TableDefinition(
			'probe_orders',
			'Tests',
			'A fixture table with a column of every class and every kind of privacy handling.',
			MutationPattern::MutableTransactional,
			array(
				new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Surrogate key.', autoIncrement: true ),
				new ColumnSpec( 'uuid', 'char(36)', Classification::Public, 'Public id.', collation: 'ascii_bin' ),
				new ColumnSpec( 'email', 'varchar(191)', Classification::Pii, 'Where order mail goes.', erasure: ColumnSpec::ERASE_DESTROY ),
				new ColumnSpec( 'client_ip', 'varchar(45)', Classification::Pii, 'The address the order came from.', nullable: true, erasure: ColumnSpec::ERASE_ANONYMIZE, notExportedBecause: 'A fixture reason.' ),
				new ColumnSpec( 'presented_tax_id', 'varchar(64)', Classification::Pii, 'The tax id the buyer gave.', defaultValue: '', erasure: ColumnSpec::ERASE_RETAIN, retainedBecause: 'A fixture reason.' ),
				new ColumnSpec( 'total_minor', 'bigint', Classification::Financial, 'The total, in minor units.', defaultValue: '0' ),
				new ColumnSpec( 'access_key_hash', 'varchar(255)', Classification::Secret, 'Hash of the access key.' ),
				new ColumnSpec( 'created_at', 'datetime', Classification::Public, 'UTC.' ),
			),
			array( 'id' ),
			array( IndexSpec::unique( 'uuid', array( 'uuid' ), 'One row per public id.' ) ),
			array( IndexSpec::key( 'email_created', array( 'email(20)', 'created_at' ), 'Support lookup by email.' ) ),
			'financial',
			array( 'customer' => 'A fixture orphan policy.' )
		);

		return new DataRegistry(
			new CapabilityDeclaration(),
			new Contribution(
				tables: array( $table ),
				options: array( new OptionDefinition( 'seocart_probe_fixture', 'Tests', 'A fixture option.', false, Classification::Public ) ),
				jobGroups: array( 'seocart-probe' => 'Tests' )
			)
		);
	}
}
