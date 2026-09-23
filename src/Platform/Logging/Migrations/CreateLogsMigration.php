<?php
/**
 * CreateLogsMigration: creates the `logs` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Logging\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Logging\LogsTable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the table the plugin's log lines are written to.
 *
 * Owns one fact: when the log table comes into existence. It runs after the platform
 * bootstrap. The store can trade without it: until it exists, the logger writes one line per
 * failed write to PHP's error log instead, so writes are not blocked while it is pending.
 *
 * @since 0.1.0
 */
final class CreateLogsMigration implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260923_0002_logging_logs';

	/**
	 * Returns the id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade without the table. It may.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Returns the declaration of the table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `logs`.
	 */
	public function tables(): array {
		return array( LogsTable::definition() );
	}

	/**
	 * Creates the table.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
