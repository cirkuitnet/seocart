<?php
/**
 * DatabaseState: the port through which the kernel reads and caches what the database is
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

defined( 'ABSPATH' ) || exit;

/**
 * The schema head and the lock mode of the current site.
 *
 * Owns one fact: the two answers the schema gate and the lock service need on every request,
 * and the promise that recording them keeps a cache in step. MigrationsTableState answers from
 * the `migrations` table and the lock probe; the kernel decorates it with a write-through
 * cache in its boot option, so the gate reads both at zero queries.
 *
 * @since 0.1.0
 */
interface DatabaseState {

	/**
	 * Returns the id of the newest applied migration.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when nothing is applied or the table does not exist yet.
	 */
	public function schemaHead(): ?string;

	/**
	 * Records the schema head after a migration run.
	 *
	 * @since 0.1.0
	 *
	 * @param string $migrationId The id of the newest applied migration.
	 */
	public function recordSchemaHead( string $migrationId ): void;

	/**
	 * Returns how locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode|null The mode, or null when it has not been decided.
	 */
	public function lockMode(): ?LockMode;

	/**
	 * Records how locks are held on this host.
	 *
	 * @since 0.1.0
	 *
	 * @param LockMode $mode The mode the probe chose.
	 */
	public function recordLockMode( LockMode $mode ): void;
}
