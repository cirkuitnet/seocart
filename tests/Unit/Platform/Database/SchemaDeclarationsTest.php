<?php
/**
 * Tests that table declarations are pure data, complete, and refuse to be incomplete
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Platform\Database;

use PHPUnit\Framework\TestCase;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\Database\Schema\ColumnSpec;
use SEOCart\Platform\Database\Schema\IndexSpec;
use SEOCart\Platform\Database\Schema\MutationPattern;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Platform\Database\Schema\TableDefinition;

/**
 * DRY rule 12 for the Database module's declarations, and the shape of its two tables.
 *
 * This suite never loads WordPress, so building the declarations here proves they are data:
 * no I/O, no container, no translation and no WordPress call. The platform tables are held to
 * the columns and keys each must have, and nothing unclassified.
 *
 * @since 0.1.0
 *
 * @group contract
 */
final class SchemaDeclarationsTest extends TestCase {

	/**
	 * Tests that the declarations build in a process where WordPress does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_the_declarations_build_without_wordpress(): void {
		$this->assertFalse( function_exists( 'add_filter' ), 'This test proves nothing if WordPress is loaded.' );

		$tables = ( new PlatformBootstrapMigration() )->tables();

		$this->assertSame( array( 'migrations', 'locks' ), array_map( static fn( TableDefinition $table ): string => $table->name(), $tables ) );
	}

	/**
	 * Tests that `migrations` carries its key columns and keys, and that every column is classified.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migrations_table_has_its_columns_and_keys(): void {
		$table = PlatformTables::migrations();

		$this->assertSame( 'Platform', $table->module() );
		$this->assertSame( MutationPattern::MutableTransactional, $table->mutationPattern() );
		$this->assertSame( 'permanent', $table->retention() );
		$this->assertSame( array( 'id' ), $table->primaryKey() );

		foreach ( array( 'migration_id', 'kind', 'can_operate_half_applied', 'state', 'plugin_version', 'checksum', 'batch_cursor', 'applied_at', 'duration_ms', 'error_code', 'error_message', 'postcondition_json', 'created_at', 'updated_at' ) as $column ) {
			$this->assertContains( $column, self::columnNames( $table ), 'migrations lacks ' . $column );
		}

		$this->assertSame( array( 'migration_id' ), self::indexNames( $table->uniqueKeys() ) );
		$this->assertSame( array( 'state', 'applied_at' ), self::indexNames( $table->indexes() ) );
		$this->assertAllPublic( $table );
	}

	/**
	 * Tests that `locks` carries its columns and its primary key only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_locks_table_has_its_columns_and_key(): void {
		$table = PlatformTables::locks();

		$this->assertSame( array( 'name', 'owner_token', 'acquired_at', 'expires_at', 'holder', 'created_at' ), self::columnNames( $table ) );
		$this->assertSame( array( 'name' ), $table->primaryKey() );
		$this->assertSame( array(), $table->uniqueKeys() );
		$this->assertSame( array(), $table->indexes() );
		$this->assertSame( 'permanent', $table->retention() );
		$this->assertAllPublic( $table );
	}

	/**
	 * Lists declarations that must be refused, each as a closure that builds one.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{\Closure(): mixed}> The attempts.
	 */
	public static function invalid(): array {
		$id = static fn(): ColumnSpec => new ColumnSpec( 'id', 'bigint unsigned', Classification::Public, 'Key.' );

		return array(
			'uppercase column type'           => array( static fn() => new ColumnSpec( 'id', 'BIGINT', Classification::Public, 'Key.' ) ),
			'column name not snake_case'      => array( static fn() => new ColumnSpec( 'orderId', 'bigint', Classification::Public, 'Key.' ) ),
			'column without a note'           => array( static fn() => new ColumnSpec( 'id', 'bigint', Classification::Public, ' ' ) ),
			'default with a quote'            => array( static fn() => new ColumnSpec( 'name', 'varchar(9)', Classification::Public, 'A name.', defaultValue: "it's" ) ),
			'nullable AUTO_INCREMENT'         => array( static fn() => new ColumnSpec( 'id', 'bigint', Classification::Public, 'Key.', nullable: true, autoIncrement: true ) ),
			'index called primary'            => array( static fn() => IndexSpec::key( 'primary', array( 'id' ), 'Lookups.' ) ),
			'index without a reason'          => array( static fn() => IndexSpec::key( 'id', array( 'id' ), '' ) ),
			'index column with an expression' => array( static fn() => IndexSpec::key( 'id', array( 'LOWER(id)' ), 'Lookups.' ) ),
			'table without a retention'       => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'id' ), array(), array(), '', array() ) ),
			'table without columns'           => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array(), array( 'id' ), array(), array(), 'permanent', array() ) ),
			'primary key on a missing column' => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'uuid' ), array(), array(), 'permanent', array() ) ),
			'column declared twice'           => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id(), $id() ), array( 'id' ), array(), array(), 'permanent', array() ) ),
			'plain index among unique keys'   => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'id' ), array( IndexSpec::key( 'i', array( 'id' ), 'Q.' ) ), array(), 'permanent', array() ) ),
			'index on a missing column'       => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'id' ), array(), array( IndexSpec::key( 'i', array( 'uuid' ), 'Q.' ) ), 'permanent', array() ) ),
			'index declared twice'            => array( static fn() => new TableDefinition( 't', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'id' ), array( IndexSpec::unique( 'i', array( 'id' ), 'I.' ) ), array( IndexSpec::key( 'i', array( 'id' ), 'Q.' ) ), 'permanent', array() ) ),
			'table name not snake_case'       => array( static fn() => new TableDefinition( 'OrderLines', 'M', 'P.', MutationPattern::Config, array( $id() ), array( 'id' ), array(), array(), 'permanent', array() ) ),
		);
	}

	/**
	 * Tests that an incomplete or inconsistent declaration cannot be built.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider invalid
	 *
	 * @param \Closure $build Builds the declaration.
	 */
	public function test_an_invalid_declaration_is_refused( \Closure $build ): void {
		$this->expectException( \InvalidArgumentException::class );

		$build();
	}

	/**
	 * Asserts that every column of a table is classified public.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $table The table.
	 */
	private function assertAllPublic( TableDefinition $table ): void {
		foreach ( $table->columns() as $column ) {
			$this->assertSame( Classification::Public, $column->classification(), $table->name() . '.' . $column->name() );
			$this->assertNotSame( '', $column->note() );
		}
	}

	/**
	 * Returns a table's column names.
	 *
	 * @since 0.1.0
	 *
	 * @param TableDefinition $table The table.
	 * @return list<string> In table order.
	 */
	private static function columnNames( TableDefinition $table ): array {
		return array_map( static fn( ColumnSpec $column ): string => $column->name(), $table->columns() );
	}

	/**
	 * Returns index names.
	 *
	 * @since 0.1.0
	 *
	 * @param IndexSpec[] $indexes The indexes.
	 * @return list<string> In order.
	 */
	private static function indexNames( array $indexes ): array {
		return array_values( array_map( static fn( IndexSpec $index ): string => $index->name(), $indexes ) );
	}
}
