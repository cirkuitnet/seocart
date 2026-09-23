<?php
/**
 * CreateSecretKeysMigration: creates the data key registry
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Secrets\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Secrets\SecretKeysTable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates `secret_keys`.
 *
 * This class owns one fact: when the data key registry comes into being. The store cannot seal a
 * secret before it exists, so the migration cannot be half-applied while the store trades.
 *
 * @since 0.1.0
 */
final class CreateSecretKeysMigration implements SchemaMigration {

	/**
	 * The migration id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260924_0001_secrets_keys';

	/**
	 * Returns the migration id.
	 *
	 * @since 0.1.0
	 *
	 * @return string The id.
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Tells whether the store may trade while the migration is not applied.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False: no secret can be sealed without the registry.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the tables the migration creates.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The tables.
	 */
	public function tables(): array {
		return array( SecretKeysTable::definition() );
	}

	/**
	 * Creates the table.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The schema operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
