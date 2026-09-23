<?php
/**
 * CreateOutboxMigration: creates the `outbox` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Events\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Events\OutboxTable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the table every event that must not be lost is stored in.
 *
 * Owns one fact: when the outbox table comes into existence. It runs after the platform
 * bootstrap. The store cannot trade without it: an order placement could not record its
 * events, so writes stay blocked until it is applied.
 *
 * @since 0.1.0
 */
final class CreateOutboxMigration implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260923_0001_events_outbox';

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
	 * Tells whether the store may trade without the table. It may not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declaration of the table.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `outbox`.
	 */
	public function tables(): array {
		return array( OutboxTable::definition() );
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
