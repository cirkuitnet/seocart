<?php
/**
 * MigrationsCheck: every migration is applied, none failed or hangs, and none changed after it ran
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\MysqlErrno;
use SEOCart\Platform\DataRegistry\DataRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Reports where the current site's migrations stand, and every applied one whose file changed.
 *
 * Owns one fact: when doctor calls the migrations healthy. The migrator's status decides
 * pending, failed and running migrations. Whether commerce writes are refused is the migrator's
 * writesBlocked() answer for the newest applied id, the call the per-request gate makes, so
 * doctor never says writes are refused when the gate lets them through. A pending migration that
 * sorts before the newest applied one is such a case: the gate counts only migrations after the
 * head, so doctor names it as out of order, once, and says writes are not refused for it. A migration
 * whose class file no longer has the checksum recorded when it was applied is reported too:
 * the migrator only reports that during a run, and a release that edits an applied migration
 * ships a schema no site will ever get. The checksum is the migrator's own,
 * Migrator::checksum(), so doctor follows whatever rule the migrator records by. Checksums are
 * shown shortened; they are hashes of source files, never data.
 *
 * @since 0.1.0
 */
final class MigrationsCheck implements Check {

	/**
	 * The check's name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NAME = 'migrations';

	/**
	 * How many hexadecimal digits of a checksum are shown.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SHOWN_DIGITS = 12;

	/**
	 * The migrator.
	 *
	 * @since 0.1.0
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * What the plugin owns, the migration chain included.
	 *
	 * @since 0.1.0
	 *
	 * @var DataRegistry
	 */
	private DataRegistry $registry;

	/**
	 * Creates the check. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Migrator     $migrator The migrator, built from the registry's chain.
	 * @param Database     $db       The connection.
	 * @param DataRegistry $registry What the plugin owns, the migration chain included.
	 */
	public function __construct( Migrator $migrator, Database $db, DataRegistry $registry ) {
		$this->migrator = $migrator;
		$this->db       = $db;
		$this->registry = $registry;
	}

	/**
	 * Returns the check's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `migrations`.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Reads the migration status and compares each applied migration's checksum with its file.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed when every migration is applied and unchanged.
	 */
	public function run(): CheckResult {
		$status   = $this->migrator->status();
		$applied  = $this->appliedChecksums();
		$findings = array();

		if ( null !== $status->failed() ) {
			$findings[] = sprintf( 'Migration %s failed. Run `wp seocart migrate` to see why and to try again.', $status->failed() );
		}

		if ( null !== $status->running() ) {
			$findings[] = sprintf( 'Migration %s is recorded as running: a run is in progress, or one was interrupted.', $status->running() );
		}

		// A migration that is out of order has a line of its own below, which says more.
		$notApplied = array_diff( $status->pending(), $status->outOfOrder() );

		if ( array() !== $notApplied ) {
			$findings[] = sprintf( 'Not applied: %s. Run `wp seocart migrate`.', implode( ', ', $notApplied ) );
		}

		foreach ( $status->outOfOrder() as $id ) {
			$findings[] = sprintf( 'Migration %1$s is out of order: it is not applied, but %2$s, which sorts after it, is. Apply it with `wp seocart migrate`. Commerce writes are not refused for it.', $id, (string) $status->schemaHead() );
		}

		if ( $this->migrator->writesBlocked( $status->schemaHead() ) ) {
			$findings[] = 'Commerce writes are refused until the schema matches the code.';
		}

		$findings = array_merge( $findings, $this->changedSinceApplied( $applied ) );

		if ( array() === $findings ) {
			return CheckResult::pass( self::NAME, sprintf( 'All %d migrations are applied, and none changed since.', count( $this->registry->migrations() ) ) );
		}

		return CheckResult::fail( self::NAME, 'The schema is not where the code expects it.', $findings );
	}

	/**
	 * Reads the checksum recorded for each applied migration. Sends one query.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the recorded migrations cannot be read for a reason other than a missing table.
	 *
	 * @return array<string, string> The checksums, by migration id; empty when the table does not exist yet.
	 */
	private function appliedChecksums(): array {
		try {
			$rows = $this->db->fetchAll( "SELECT migration_id, checksum FROM %i WHERE state = 'applied'", $this->db->table( 'migrations' ) );
		} catch ( QueryFailed $failed ) {
			if ( MysqlErrno::NO_SUCH_TABLE === $failed->errno() ) {
				return array();
			}

			throw $failed;
		}

		$checksums = array();

		foreach ( $rows as $row ) {
			$checksums[ (string) $row['migration_id'] ] = (string) $row['checksum'];
		}

		return $checksums;
	}

	/**
	 * Lists every applied migration whose class file changed since it was applied.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $recorded The checksum recorded for each applied migration, by id.
	 * @return list<string> One line per changed migration.
	 */
	private function changedSinceApplied( array $recorded ): array {
		$findings = array();

		foreach ( $this->registry->migrations() as $migration ) {
			if ( ! isset( $recorded[ $migration->id() ] ) ) {
				continue;
			}

			$current = Migrator::checksum( $migration );

			if ( $current !== $recorded[ $migration->id() ] ) {
				$findings[] = sprintf(
					'Migration %1$s changed after it was applied: its file hashes to %2$s..., and %3$s... was recorded. An applied migration must never change; ship a new one.',
					$migration->id(),
					substr( $current, 0, self::SHOWN_DIGITS ),
					substr( $recorded[ $migration->id() ], 0, self::SHOWN_DIGITS )
				);
			}
		}

		return $findings;
	}
}
