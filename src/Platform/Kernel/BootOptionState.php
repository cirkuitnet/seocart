<?php
/**
 * BootOptionState: the database state, cached in the boot record and written through to it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Kernel;

use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\MigrationsTableState;

defined( 'ABSPATH' ) || exit;

/**
 * Answers the schema head and the lock mode from the boot record, at no cost, and keeps the record current.
 *
 * Owns one fact: that the boot record is a cache of the database state, never its source of
 * truth. The `migrations` table and the lock probe are the truth, through MigrationsTableState.
 * Reading answers from the record when there is one and asks the table state otherwise;
 * recording writes the record, then tells the table state.
 *
 * The record may lag the table: a crash after the migrator committed a row and before the record
 * was written leaves an older head cached. The schema gate then refuses writes for a schema that
 * is in fact current, which is the safe direction, and the next admin, command-line or cron
 * request reconciles it. The record cannot lead the table, because the migrator records a head
 * only after the migration's row is committed.
 *
 * A site without a record is not given one here: recording a head or a lock mode on it creates
 * nothing, so an installation record is only ever created with an identity.
 *
 * @since 0.1.0
 */
final class BootOptionState implements DatabaseState {

	/**
	 * The boot record.
	 *
	 * @since 0.1.0
	 *
	 * @var BootOption
	 */
	private BootOption $bootOption;

	/**
	 * The state as the table and the probe give it.
	 *
	 * @since 0.1.0
	 *
	 * @var MigrationsTableState
	 */
	private MigrationsTableState $table;

	/**
	 * Creates the state. Reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param BootOption           $bootOption The boot record.
	 * @param MigrationsTableState $table      The state as the table and the probe give it.
	 */
	public function __construct( BootOption $bootOption, MigrationsTableState $table ) {
		$this->bootOption = $bootOption;
		$this->table      = $table;
	}

	/**
	 * Returns the newest applied migration id: the recorded one, or the table's when there is no record.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The id, or null when nothing is applied.
	 */
	public function schemaHead(): ?string {
		$record = $this->bootOption->read();

		return $record->isAbsent() ? $this->table->schemaHead() : $record->schemaHead();
	}

	/**
	 * Records the schema head in the boot record, then in the table state.
	 *
	 * @since 0.1.0
	 *
	 * @param string $migrationId The id of the newest applied migration.
	 */
	public function recordSchemaHead( string $migrationId ): void {
		if ( ! $this->bootOption->read()->isAbsent() ) {
			$this->bootOption->mutate( static fn( BootRecord $record ): BootRecord => $record->isAbsent() ? $record : $record->withSchemaHead( $migrationId ) );
		}

		$this->table->recordSchemaHead( $migrationId );
	}

	/**
	 * Returns how locks are held: the recorded mode, or the probe's answer, which is then recorded.
	 *
	 * @since 0.1.0
	 *
	 * @return LockMode The mode. Never null here.
	 */
	public function lockMode(): LockMode {
		$recorded = $this->bootOption->read()->lockMode();

		if ( null !== $recorded ) {
			return $recorded;
		}

		$mode = $this->table->lockMode();

		$this->recordLockMode( $mode );

		return $mode;
	}

	/**
	 * Records the lock mode in the boot record, then in the table state.
	 *
	 * @since 0.1.0
	 *
	 * @param LockMode $mode The mode the probe chose.
	 */
	public function recordLockMode( LockMode $mode ): void {
		if ( ! $this->bootOption->read()->isAbsent() ) {
			$this->bootOption->mutate( static fn( BootRecord $record ): BootRecord => $record->isAbsent() ? $record : $record->withLockMode( $mode ) );
		}

		$this->table->recordLockMode( $mode );
	}
}
