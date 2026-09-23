<?php
/**
 * PlatformBootstrapMigration: migration #1, which creates the migrator's own tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Database\Schema\PlatformTables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `migrations` and `locks`.
 *
 * Owns one fact: that these two tables exist before anything else is migrated. The migrator
 * runs it before taking the schema lock, because a table-mode lock needs the `locks` table,
 * and tolerates a racing runner creating the same tables. It is otherwise an ordinary
 * migration: verified, checksummed and recorded.
 *
 * @since 0.1.0
 */
final class PlatformBootstrapMigration implements SchemaMigration {

	/**
	 * The id, which sorts before every other migration.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260922_0001_platform_bootstrap';

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
	 * Tells whether the store may trade without these tables. It may not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the two tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `migrations`, then `locks`.
	 */
	public function tables(): array {
		return array( PlatformTables::migrations(), PlatformTables::locks() );
	}

	/**
	 * Creates the two tables.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
