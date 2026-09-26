<?php
/**
 * CreateRateCountersMigration: creates the `rate_counters` table
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\RateLimiter\Migrations;

use SEOCart\Platform\Database\SchemaMigration;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\RateLimiter\RateCountersTable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the table the rate limiter counts in when the site has no persistent object cache.
 *
 * Owns one fact: when the counter table comes into existence. It runs after the platform
 * bootstrap and holds no data another table refers to, so it can be applied half-way and again.
 *
 * @since 0.1.0
 */
final class CreateRateCountersMigration implements SchemaMigration {

	/**
	 * The id.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const ID = '20260925_0002_rate_limiter_counters';

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
	 * Tells whether the store may trade without the table. It may: only a Store API write counts
	 * in it, and until the table exists such a write fails with an internal error rather than
	 * running uncounted.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True.
	 */
	public function canOperateHalfApplied(): bool {
		return true;
	}

	/**
	 * Returns the tables the migration creates.
	 *
	 * @since 0.1.0
	 *
	 * @return list<\SEOCart\Platform\Database\Schema\TableDefinition> `rate_counters`.
	 */
	public function tables(): array {
		return array( RateCountersTable::definition() );
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
