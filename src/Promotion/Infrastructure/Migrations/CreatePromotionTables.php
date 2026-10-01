<?php
/**
 * CreatePromotionTables: creates the three promotion tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Promotion\Infrastructure\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Promotion\Infrastructure\PromotionTables;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the promotion, condition and usage tables, in their final shape.
 *
 * Owns one fact: when the promotion tables come into existence. A cart that holds a code cannot
 * be priced without them, and an order that uses a promotion cannot be placed, so writes stay
 * blocked until the migration is applied.
 *
 * @since 0.1.0
 */
final class CreatePromotionTables implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260926_0003_promotion_tables';

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
	 * Tells whether the store may trade without the tables. It may not.
	 *
	 * @since 0.1.0
	 *
	 * @return bool False.
	 */
	public function canOperateHalfApplied(): bool {
		return false;
	}

	/**
	 * Returns the declarations of the tables.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> The three promotion tables.
	 */
	public function tables(): array {
		return PromotionTables::all();
	}

	/**
	 * Creates the tables.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaOperations $operations The DDL operations.
	 */
	public function up( SchemaOperations $operations ): void {
		$operations->createTables( $this->tables() );
	}
}
