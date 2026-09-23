<?php
/**
 * Migration: one ordered, named step of the plugin's schema history
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * A migration: an id that orders it and a statement about whether the store can trade while it is incomplete.
 *
 * Owns one fact: a migration's identity and its effect on the schema gate. What it does is
 * the business of its kind, which is the interface it implements: SchemaMigration for DDL,
 * DataMigration for batched, resumable rewrites. There is no down-migration.
 *
 * @since 0.1.0
 */
interface Migration {

	/**
	 * Returns the id, which is also the sort key: `YYYYMMDD_NNNN_name`.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example `20260922_0001_platform_bootstrap`.
	 */
	public function id(): string;

	/**
	 * Tells whether the store may keep taking writes while this migration is running or failed.
	 *
	 * False holds the store in degraded mode until the migration completes. True is the normal
	 * case for the backfill step of expand, migrate, contract.
	 *
	 * @since 0.1.0
	 *
	 * @return bool The declared flag.
	 */
	public function canOperateHalfApplied(): bool;
}
