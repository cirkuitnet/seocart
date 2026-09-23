<?php
/**
 * MigrationsTableState: the database state read from its source of truth
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\QueryFailed;

defined( 'ABSPATH' ) || exit;

/**
 * Answers DatabaseState from the `migrations` table and the lock probe.
 *
 * Owns one fact: where the schema head and the lock mode come from when nothing caches them.
 * The head is the newest applied migration id in the current site's `migrations` table, or
 * null when that table does not exist yet (error 1146). The lock mode is what LockProbe finds.
 * Both are read once per request and remembered; the head is remembered per table prefix, so
 * a network run that switches sites never answers for the wrong site. Recording writes nothing
 * here, because the table is the record, but keeps the remembered answers current.
 *
 * @since 0.1.0
 */
final class MigrationsTableState implements DatabaseState {

	/**
	 * MySQL's error for a table that does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NO_SUCH_TABLE = 1146;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * The schema head per table prefix, once read.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string|null>
	 */
	private array $heads = array();

	/**
	 * The lock mode, once probed or recorded.
	 *
	 * @since 0.1.0
	 *
	 * @var LockMode|null
	 */
	private ?LockMode $lockMode = null;

	/**
	 * Creates the state. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database $db The connection.
	 */
	public function __construct( Database $db ) {
		$this->db = $db;
	}

	/**
	 * Returns the newest applied migration id of the current site. One query per site per request.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the query fails for any reason but a missing table.
	 *
	 * @return string|null The id, or null when nothing is applied or the table does not exist.
	 */
	public function schemaHead(): ?string {
		$prefix = $this->db->prefix();

		if ( ! array_key_exists( $prefix, $this->heads ) ) {
			try {
				$head = $this->db->fetchValue( 'SELECT MAX( migration_id ) FROM %i WHERE state = %s', $this->db->table( 'migrations' ), Migrator::APPLIED );
			} catch ( QueryFailed $failed ) {
				if ( self::NO_SUCH_TABLE !== $failed->errno() ) {
					throw $failed;
				}

				$head = null;
			}

			$this->heads[ $prefix ] = null === $head ? null : (string) $head;
		}

		return $this->heads[ $prefix ];
	}

	/**
	 * Remembers the head the migrator just recorded in the table.
	 *
	 * @since 0.1.0
	 *
	 * @param string $migrationId The id of the newest applied migration.
	 */
	public function recordSchemaHead( string $migrationId ): void {
		$this->heads[ $this->db->prefix() ] = $migrationId;
	}

	/**
	 * Returns how locks are held on this host, running the probe on the first call of the request.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode The mode. Never null here.
	 */
	public function lockMode(): LockMode {
		if ( null === $this->lockMode ) {
			$this->lockMode = LockProbe::run( $this->db );
		}

		return $this->lockMode;
	}

	/**
	 * Remembers the lock mode for the rest of the request.
	 *
	 * @since 0.1.0
	 *
	 * @param LockMode $mode The mode.
	 */
	public function recordLockMode( LockMode $mode ): void {
		$this->lockMode = $mode;
	}
}
