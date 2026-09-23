<?php
/**
 * DeclaresMissingIndex: a fixture schema migration that promises an index it never creates
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;

/**
 * Declares `test_{suffix}` with its `value` index, then creates the table without it.
 *
 * Owns one fact: a migration whose post-conditions must fail. It is the permanent planted
 * violation behind the migrator's verification: dbDelta succeeds, nothing throws, and only the
 * information_schema comparison can notice that the declared index is missing. It declares
 * that the store cannot operate while it is incomplete.
 *
 * @since 0.1.0
 */
final class DeclaresMissingIndex implements SchemaMigration {

	/**
	 * The migration id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The table's name after `test_`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $suffix;

	/**
	 * Creates the fixture.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id     The migration id.
	 * @param string $suffix The table's name after `test_`.
	 */
	public function __construct( string $id, string $suffix ) {
		$this->id     = $id;
		$this->suffix = $suffix;
	}

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Tells whether the store may trade while this is incomplete. It may not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Declares the table with its `value` index.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The one table.
	 */
	public function tables(): array {
		return array( TestTables::simple( $this->suffix ) );
	}

	/**
	 * Creates the table without the index it declares.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTable( TestTables::simple( $this->suffix, false ) );
	}
}
