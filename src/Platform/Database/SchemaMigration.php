<?php
/**
 * SchemaMigration: a migration that changes tables and declares the shape they end in
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Schema\TableDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * A fast, synchronous change of table structure, verified against its declared end state.
 *
 * Owns one fact: the tables a structural migration promises, and how it gets there. After
 * up() the migrator compares every table tables() returns with the server's information_schema;
 * any difference fails the migration. up() runs again when a failed or interrupted migration
 * is resumed, so it must be idempotent: creates go through dbDelta, which is naturally
 * re-runnable.
 *
 * @since 0.1.0
 */
interface SchemaMigration extends Migration {

	/**
	 * Returns the declarations of the tables this migration creates or changes, in their end state.
	 *
	 * @since 0.1.0
	 *
	 * @return list<TableDefinition> The declarations, from the owning module's static factories.
	 */
	public function tables(): array;

	/**
	 * Performs the change. Runs outside any transaction, because DDL commits implicitly.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations Creates tables from their declarations.
	 */
	public function up( SchemaOperations $operations ): void;
}
