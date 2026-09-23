<?php
/**
 * Migrator: applies the migration chain, once per migration, in order, under the schema lock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Database;

use SEOCart\Platform\Database\Exception\DatabaseException;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\LockNotAcquired;
use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\Exception\QueryFailed;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Support\Clock;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages go to logs and the command line, never into HTML; the REST layer answers with the translated message of the error code.

/**
 * Brings the current site's schema to the head of the migration chain, and says where it stands.
 *
 * Owns one fact: the rules of a migration run.
 *
 * 1. Never inside a transaction: DDL commits implicitly.
 * 2. The bootstrap migration runs first and unlocked, because a table-mode lock needs its
 *    table; if another runner creates the same table at the same moment, error 1050 is
 *    tolerated for this step only, because existence is then checked in information_schema.
 * 3. The schema lock is taken with a bounded wait. A runner that cannot take it reports
 *    "blocked" and changes nothing.
 * 4. Under the lock, the `migrations` table is read again: what another runner applied while
 *    this one waited is skipped, never repeated.
 * 5. Each pending migration, in id order: its row becomes `running`, the lease is renewed,
 *    and it runs. A schema migration's tables are then verified against their declarations;
 *    the diff, not dbDelta's word, decides. A data migration commits each batch together with
 *    its cursor, renews the lease per batch, and stops at the time budget. On success the row
 *    becomes `applied` with its timing, version, checksum and verified summary; on failure it
 *    becomes `failed` with the error and the diff, the chain stops, and MigrationFailed is
 *    thrown once the lock is released.
 * 6. The newest applied id is recorded as the schema head.
 *
 * Commerce writes are refused while the schema is newer than the code, or while a migration
 * that cannot operate half-applied is outstanding; writesBlocked() decides it from the head
 * alone, and status() from each migration's recorded state.
 *
 * The checksum of an applied migration's class file is compared on every run; a difference is
 * reported, not treated as a failure, because the verifier guards the shape.
 *
 * @since 0.1.0
 */
final class Migrator {

	/**
	 * Row state: the migration completed and its post-conditions held.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const APPLIED = 'applied';

	/**
	 * Row state: the migration is running, or was interrupted and resumes on the next run.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RUNNING = 'running';

	/**
	 * Row state: the migration failed; the next run starts again from it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const FAILED = 'failed';

	/**
	 * The name of the schema lock.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SCHEMA_LOCK = 'schema';

	/**
	 * How long a schema lease lasts without renewal, in seconds. Renewed per migration and per data batch.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const SCHEMA_LOCK_TTL_SECONDS = 300;

	/**
	 * The machine code reported when an applied migration's class file changed since it ran.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CHECKSUM_MISMATCH = 'database.migration_checksum_mismatch';

	/**
	 * A migration id: a date, a four-digit sequence and a snake_case name.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ID_PATTERN = '/^\d{8}_\d{4}_[a-z0-9_]+$/';

	/**
	 * MySQL's error for CREATE TABLE of a table that exists.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TABLE_EXISTS = 1050;

	/**
	 * MySQL's error for a table that does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const NO_SUCH_TABLE = 1146;

	/**
	 * How much of a failure message the row keeps.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const ERROR_MESSAGE_LENGTH = 500;

	/**
	 * The connection.
	 *
	 * @since 0.1.0
	 *
	 * @var Database
	 */
	private Database $db;

	/**
	 * Takes the schema lock.
	 *
	 * @since 0.1.0
	 *
	 * @var LockService
	 */
	private LockService $locks;

	/**
	 * Receives the schema head after a run.
	 *
	 * @since 0.1.0
	 *
	 * @var DatabaseState
	 */
	private DatabaseState $state;

	/**
	 * The registered migrations, in any order.
	 *
	 * @since 0.1.0
	 *
	 * @var iterable<Migration>
	 */
	private iterable $migrations;

	/**
	 * Tells the time for the row timestamps.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Receives a machine code and context for anything reported rather than thrown.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(string, array<string, mixed>): void
	 */
	private $report;

	/**
	 * The validated chain, in id order, once built.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Migration>|null
	 */
	private ?array $chain = null;

	/**
	 * Reads table shapes, once needed.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaVerifier|null
	 */
	private ?SchemaVerifier $verifier = null;

	/**
	 * Performs DDL, once needed.
	 *
	 * @since 0.1.0
	 *
	 * @var SchemaOperations|null
	 */
	private ?SchemaOperations $operations = null;

	/**
	 * Creates the migrator. Sends nothing and reads nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Database      $db         The connection.
	 * @param LockService   $locks      Takes the schema lock.
	 * @param DatabaseState $state      Receives the schema head.
	 * @param iterable      $migrations The registered Migration objects, the bootstrap migration among them.
	 * @param Clock         $clock      Tells the time for row timestamps.
	 * @param callable      $report     Receives a machine code (string) and its context (array).
	 */
	public function __construct( Database $db, LockService $locks, DatabaseState $state, iterable $migrations, Clock $clock, callable $report ) {
		$this->db         = $db;
		$this->locks      = $locks;
		$this->state      = $state;
		$this->migrations = $migrations;
		$this->clock      = $clock;
		$this->report     = $report;
	}

	/**
	 * Applies every pending migration of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @throws MigrationFailed|ForbiddenInsideTransaction When a migration fails, once its row records why
	 *                                                    and the schema lock is released (the migrations
	 *                                                    after it did not run); or when called inside a
	 *                                                    transaction, and nothing is sent.
	 *
	 * @param MigrationRunOptions $options How long to wait for the lock and to work.
	 * @return MigrationReport Applied, up to date, blocked, or incomplete.
	 */
	public function migrate( MigrationRunOptions $options ): MigrationReport {
		if ( 0 !== $this->db->depth() ) {
			throw new ForbiddenInsideTransaction( ForbiddenInsideTransaction::KIND_DDL, 'migrate' );
		}

		$started   = (int) hrtime( true );
		$chain     = $this->chain();
		$bootstrap = $chain[0];
		$rows      = $this->rows();
		$applied   = array();

		if ( $bootstrap instanceof PlatformBootstrapMigration && self::APPLIED !== ( $rows[ $bootstrap->id() ]['state'] ?? null ) ) {
			$applied[] = array(
				'id'          => $bootstrap->id(),
				'duration_ms' => $this->bootstrap( $bootstrap ),
			);

			$rows = $this->rows();
		}

		$pendingBefore = self::ids( $this->pending( $chain, $rows ) );

		try {
			// Every failure inside the work becomes MigrationFailed, so LockNotAcquired can only mean the schema lock.
			return $this->locks->withLock(
				self::SCHEMA_LOCK,
				self::SCHEMA_LOCK_TTL_SECONDS,
				$options->waitSeconds(),
				fn( Lease $lease ): MigrationReport => $this->runLocked( $chain, $pendingBefore, $applied, $lease, $options, $started )
			);
		} catch ( LockNotAcquired $notAcquired ) {
			return MigrationReport::blocked();
		}
	}

	/**
	 * Describes where the current site's schema stands. Sends one query.
	 *
	 * @since 0.1.0
	 *
	 * @return MigrationStatus The heads, the pending, failed and running migrations, and whether writes are blocked.
	 */
	public function status(): MigrationStatus {
		$chain       = $this->chain();
		$rows        = $this->rows();
		$appliedHead = self::newestApplied( $rows );
		$outstanding = array();
		$failed      = null;
		$running     = null;

		foreach ( $chain as $migration ) {
			$state = $rows[ $migration->id() ]['state'] ?? null;

			if ( self::APPLIED !== $state ) {
				$outstanding[] = $migration;
			}

			if ( self::FAILED === $state && null === $failed ) {
				$failed = $migration->id();
			}

			if ( self::RUNNING === $state && null === $running ) {
				$running = $migration->id();
			}
		}

		$codeHead = self::codeHead( $chain );

		return new MigrationStatus( $codeHead, $appliedHead, self::ids( $outstanding ), $failed, $running, self::blocksWrites( $outstanding, $appliedHead, $codeHead ) );
	}

	/**
	 * Tells whether commerce writes must be refused, from the schema head alone. Sends nothing.
	 *
	 * The kernel's schema gate calls this on every request with the head cached in its boot
	 * option, so it reads no table: every registered migration whose id sorts after the head
	 * counts as outstanding. That relies on the rule that a migration is never released with
	 * an id sorting before one already released. status() applies the same rule to each
	 * migration's recorded state instead.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $schemaHead The newest applied migration id, as DatabaseState::schemaHead() returns it.
	 * @return bool True when the schema is newer than the code, or when a migration after the head cannot operate half-applied.
	 */
	public function writesBlocked( ?string $schemaHead ): bool {
		$chain       = $this->chain();
		$outstanding = array();

		foreach ( $chain as $migration ) {
			if ( null === $schemaHead || strcmp( $migration->id(), $schemaHead ) > 0 ) {
				$outstanding[] = $migration;
			}
		}

		return self::blocksWrites( $outstanding, $schemaHead, self::codeHead( $chain ) );
	}

	/**
	 * Runs the pending migrations while holding the schema lock.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[]                               $chain         The chain.
	 * @param string[]                                  $pendingBefore The ids that were pending before the lock was taken.
	 * @param list<array{id: string, duration_ms: int}> $applied       What this run applied before the lock: the bootstrap, or nothing.
	 * @param Lease                                     $lease         The schema lease.
	 * @param MigrationRunOptions                       $options       The run's bounds.
	 * @param int                                       $started       hrtime() when the run began, in nanoseconds.
	 * @return MigrationReport The report.
	 */
	private function runLocked( array $chain, array $pendingBefore, array $applied, Lease $lease, MigrationRunOptions $options, int $started ): MigrationReport {
		// Re-read under the lock: another runner may have applied migrations while this one waited.
		$rows    = $this->rows();
		$pending = $this->pending( $chain, $rows );

		$this->reportChecksumMismatches( $chain, $rows );

		$elsewhere = array_values( array_diff( $pendingBefore, self::ids( $pending ) ) );

		foreach ( $pending as $migration ) {
			$duration = $this->apply( $migration, $rows[ $migration->id() ]['batch_cursor'] ?? null, $lease, $options, $started );

			if ( null === $duration ) {
				$this->recordHead( $rows, $applied );

				return new MigrationReport( MigrationReport::INCOMPLETE, $applied, $elsewhere, $migration->id() );
			}

			$applied[] = array(
				'id'          => $migration->id(),
				'duration_ms' => $duration,
			);
		}

		$this->recordHead( $rows, $applied );

		return new MigrationReport( array() === $applied ? MigrationReport::UP_TO_DATE : MigrationReport::APPLIED, $applied, $elsewhere );
	}

	/**
	 * Runs one migration and records the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @throws MigrationFailed When it fails. The row is `failed`.
	 *
	 * @param Migration           $migration The migration.
	 * @param string|null         $cursor    Where a data migration resumes.
	 * @param Lease               $lease     The schema lease.
	 * @param MigrationRunOptions $options   The run's bounds.
	 * @param int                 $started   hrtime() when the run began, in nanoseconds.
	 * @return int|null How long it took in milliseconds, or null when the budget interrupted a data migration.
	 */
	private function apply( Migration $migration, ?string $cursor, Lease $lease, MigrationRunOptions $options, int $started ): ?int {
		$began = (int) hrtime( true );

		$this->recordRunning( $migration );

		try {
			$lease->renew();

			if ( $migration instanceof SchemaMigration ) {
				$migration->up( $this->operations() );

				$summary = $this->verify( $migration );
			} else {
				if ( ! $migration instanceof DataMigration || ! $this->runBatches( $migration, $cursor, $lease, $options, $started ) ) {
					return null;
				}

				$summary = null;
			}
		} catch ( \Throwable $failure ) {
			$failed = $failure instanceof MigrationFailed ? $failure : MigrationFailed::threw( $migration->id(), $failure );

			$this->recordFailure( $migration, $failed );

			throw $failed;
		}

		$duration = self::elapsedMs( $began );

		$this->recordApplied( $migration, $duration, $summary );

		return $duration;
	}

	/**
	 * Runs the bootstrap migration before any lock exists, tolerating a runner that creates the same tables at the same moment.
	 *
	 * @since 0.1.0
	 *
	 * @throws MigrationFailed When the tables cannot be created or do not match their declarations.
	 *
	 * @param PlatformBootstrapMigration $bootstrap The bootstrap migration.
	 * @return int How long it took, in milliseconds.
	 */
	private function bootstrap( PlatformBootstrapMigration $bootstrap ): int {
		$began = (int) hrtime( true );

		try {
			$this->createRacing( $bootstrap );

			$summary = $this->verify( $bootstrap );
		} catch ( \Throwable $failure ) {
			$failed = $failure instanceof MigrationFailed ? $failure : MigrationFailed::threw( $bootstrap->id(), $failure );

			$this->recordFailure( $bootstrap, $failed, true );

			throw $failed;
		}

		$duration = self::elapsedMs( $began );

		$this->recordRunning( $bootstrap );
		$this->recordApplied( $bootstrap, $duration, $summary );

		return $duration;
	}

	/**
	 * Runs the bootstrap migration's up(), passing over error 1050 when another runner creates one of its tables first.
	 *
	 * Each pass creates the tables still missing; a table another runner created in between is
	 * found by the next pass. There is one pass per table plus one, so a race cannot loop.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the DDL fails for any other reason, or races on every pass.
	 *
	 * @param PlatformBootstrapMigration $bootstrap The bootstrap migration.
	 */
	private function createRacing( PlatformBootstrapMigration $bootstrap ): void {
		$passes = count( $bootstrap->tables() ) + 1;

		while ( true ) {
			try {
				$bootstrap->up( $this->operations() );

				return;
			} catch ( QueryFailed $raced ) {
				--$passes;

				if ( self::TABLE_EXISTS !== $raced->errno() || $passes < 1 ) {
					throw $raced;
				}
			}
		}
	}

	/**
	 * Runs a data migration's batches until it is done or the budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param DataMigration       $migration The migration.
	 * @param string|null         $cursor    Where to resume.
	 * @param Lease               $lease     The schema lease, renewed before every batch.
	 * @param MigrationRunOptions $options   The run's bounds.
	 * @param int                 $started   hrtime() when the run began, in nanoseconds.
	 * @return bool True when the migration is done; false when the budget ran out first.
	 */
	private function runBatches( DataMigration $migration, ?string $cursor, Lease $lease, MigrationRunOptions $options, int $started ): bool {
		while ( true ) {
			$cursor = $this->db->transaction(
				function () use ( $migration, $cursor ): ?string {
					$next = $migration->runBatch( $cursor, $this->db );

					// The cursor commits with the batch, so a crash can neither skip nor replay a committed batch.
					$this->recordCursor( $migration, $next );

					return $next;
				}
			);

			if ( null === $cursor ) {
				return true;
			}

			if ( $this->budgetSpent( $options, $started ) ) {
				return false;
			}

			$lease->renew();
		}
	}

	/**
	 * Verifies every table a schema migration declares.
	 *
	 * @since 0.1.0
	 *
	 * @throws MigrationFailed When any table differs from its declaration.
	 *
	 * @param SchemaMigration $migration The migration.
	 * @return array{tables: list<array<string, mixed>>} The verified summary of each table.
	 */
	private function verify( SchemaMigration $migration ): array {
		$diff    = array();
		$summary = array();

		foreach ( $migration->tables() as $definition ) {
			$tableDiff = $this->verifier()->diff( $definition );

			if ( array() === $tableDiff ) {
				$summary[] = $this->verifier()->summary( $definition );
			} else {
				$diff = array_merge( $diff, $tableDiff );
			}
		}

		if ( array() !== $diff ) {
			throw MigrationFailed::postconditions( $migration->id(), $diff );
		}

		return array( 'tables' => $summary );
	}

	/**
	 * Reports every applied migration whose class file changed since it ran.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[]                         $chain The chain.
	 * @param array<string, array<string, mixed>> $rows  The recorded rows, by migration id.
	 */
	private function reportChecksumMismatches( array $chain, array $rows ): void {
		foreach ( $chain as $migration ) {
			$row = $rows[ $migration->id() ] ?? null;

			if ( null === $row || self::APPLIED !== $row['state'] ) {
				continue;
			}

			$current = self::checksum( $migration );

			if ( $current !== (string) $row['checksum'] ) {
				( $this->report )(
					self::CHECKSUM_MISMATCH,
					array(
						'migration_id' => $migration->id(),
						'recorded'     => (string) $row['checksum'],
						'current'      => $current,
					)
				);
			}
		}
	}

	/**
	 * Records the newest applied migration as the schema head.
	 *
	 * Newest across every recorded row, not only this release's chain: an older release that
	 * runs after a newer one must not lower the head, or the gate would stop seeing that the
	 * schema is newer than the code.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>>       $rows    The rows read under the lock.
	 * @param list<array{id: string, duration_ms: int}> $applied The migrations this run applied.
	 */
	private function recordHead( array $rows, array $applied ): void {
		$head = self::newestApplied( $rows );

		foreach ( $applied as $entry ) {
			if ( null === $head || strcmp( $entry['id'], $head ) > 0 ) {
				$head = $entry['id'];
			}
		}

		if ( null !== $head ) {
			$this->state->recordSchemaHead( $head );
		}
	}

	/**
	 * Decides whether commerce writes must be refused. The one place that rule is written.
	 *
	 * Writes are refused when the schema is newer than the code, or when an outstanding
	 * migration (pending, running or failed) cannot operate half-applied. Outstanding
	 * migrations that can operate half-applied do not refuse writes: the store keeps trading
	 * while they backfill.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[] $outstanding The registered migrations that are not applied.
	 * @param string|null $appliedHead The newest applied migration id, or null.
	 * @param string      $codeHead    The newest registered migration id.
	 * @return bool True when writes must be refused.
	 */
	private static function blocksWrites( array $outstanding, ?string $appliedHead, string $codeHead ): bool {
		if ( null !== $appliedHead && strcmp( $appliedHead, $codeHead ) > 0 ) {
			return true;
		}

		foreach ( $outstanding as $migration ) {
			if ( ! $migration->canOperateHalfApplied() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the newest applied migration id among recorded rows.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, mixed>> $rows The recorded rows, by migration id.
	 * @return string|null The id that sorts last among applied rows, or null when none is applied.
	 */
	private static function newestApplied( array $rows ): ?string {
		$head = null;

		foreach ( $rows as $id => $row ) {
			$id = (string) $id;

			if ( self::APPLIED === $row['state'] && ( null === $head || strcmp( $id, $head ) > 0 ) ) {
				$head = $id;
			}
		}

		return $head;
	}

	/**
	 * Returns the newest registered migration id.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[] $chain The chain, in id order.
	 * @return string The id of its last migration.
	 */
	private static function codeHead( array $chain ): string {
		return $chain[ count( $chain ) - 1 ]->id();
	}

	/**
	 * Marks a migration as running, creating its row on the first run.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration $migration The migration.
	 */
	private function recordRunning( Migration $migration ): void {
		$now      = $this->now();
		$kind     = $migration instanceof DataMigration ? 'data' : 'schema';
		$half     = $migration->canOperateHalfApplied() ? 1 : 0;
		$version  = self::pluginVersion();
		$checksum = self::checksum( $migration );

		$this->db->execute(
			'INSERT INTO %i ( migration_id, kind, can_operate_half_applied, state, plugin_version, checksum, created_at, updated_at ) VALUES ( %s, %s, %d, %s, %s, %s, %s, %s ) ON DUPLICATE KEY UPDATE kind = %s, can_operate_half_applied = %d, state = %s, plugin_version = %s, checksum = %s, error_code = NULL, error_message = NULL, updated_at = %s',
			$this->db->table( 'migrations' ),
			$migration->id(),
			$kind,
			$half,
			self::RUNNING,
			$version,
			$checksum,
			$now,
			$now,
			$kind,
			$half,
			self::RUNNING,
			$version,
			$checksum,
			$now
		);
	}

	/**
	 * Marks a migration as applied.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration                 $migration  The migration.
	 * @param int                       $durationMs How long the run took.
	 * @param array<string, mixed>|null $summary    The verified summary of a schema migration, or null.
	 */
	private function recordApplied( Migration $migration, int $durationMs, ?array $summary ): void {
		$now = $this->now();

		$this->db->execute(
			"UPDATE %i SET state = %s, applied_at = %s, duration_ms = %d, plugin_version = %s, checksum = %s, batch_cursor = NULL, error_code = NULL, error_message = NULL, postcondition_json = NULLIF( %s, '' ), updated_at = %s WHERE migration_id = %s",
			$this->db->table( 'migrations' ),
			self::APPLIED,
			$now,
			$durationMs,
			self::pluginVersion(),
			self::checksum( $migration ),
			null === $summary ? '' : (string) wp_json_encode( $summary ),
			$now,
			$migration->id()
		);
	}

	/**
	 * Marks a migration as failed, with its error and diff. A failure to record is reported, never thrown.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration       $migration The migration.
	 * @param MigrationFailed $failed    Why it failed.
	 * @param bool            $createRow Optional. Whether the row may not exist yet. Default false.
	 */
	private function recordFailure( Migration $migration, MigrationFailed $failed, bool $createRow = false ): void {
		try {
			if ( $createRow ) {
				$this->recordRunning( $migration );
			}

			$this->db->execute(
				'UPDATE %i SET state = %s, error_code = %s, error_message = %s, postcondition_json = %s, updated_at = %s WHERE migration_id = %s',
				$this->db->table( 'migrations' ),
				self::FAILED,
				$failed->errorCode(),
				mb_substr( $failed->getMessage(), 0, self::ERROR_MESSAGE_LENGTH ),
				(string) wp_json_encode( $failed->diff() ),
				$this->now(),
				$migration->id()
			);
		} catch ( DatabaseException $unrecorded ) {
			( $this->report )(
				$unrecorded->code(),
				array(
					'migration_id' => $migration->id(),
					'while'        => 'recording a migration failure',
				) + $unrecorded->context()
			);
		}
	}

	/**
	 * Stores a data migration's cursor. Called inside the batch's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param DataMigration $migration The migration.
	 * @param string|null   $cursor    The next batch's cursor, or null when the migration is done.
	 */
	private function recordCursor( DataMigration $migration, ?string $cursor ): void {
		if ( null === $cursor ) {
			$this->db->execute(
				'UPDATE %i SET batch_cursor = NULL, updated_at = %s WHERE migration_id = %s',
				$this->db->table( 'migrations' ),
				$this->now(),
				$migration->id()
			);

			return;
		}

		$this->db->execute(
			'UPDATE %i SET batch_cursor = %s, updated_at = %s WHERE migration_id = %s',
			$this->db->table( 'migrations' ),
			$cursor,
			$this->now(),
			$migration->id()
		);
	}

	/**
	 * Reads the recorded rows of the current site.
	 *
	 * @since 0.1.0
	 *
	 * @throws QueryFailed When the read fails for any reason but a missing table.
	 *
	 * @return array<string, array<string, mixed>> The rows, by migration id; empty when the table does not exist yet.
	 */
	private function rows(): array {
		try {
			$rows = $this->db->fetchAll( 'SELECT migration_id, state, checksum, batch_cursor FROM %i', $this->db->table( 'migrations' ) );
		} catch ( QueryFailed $failed ) {
			if ( self::NO_SUCH_TABLE === $failed->errno() ) {
				return array();
			}

			throw $failed;
		}

		$byId = array();

		foreach ( $rows as $row ) {
			$byId[ (string) $row['migration_id'] ] = $row;
		}

		return $byId;
	}

	/**
	 * Returns the migrations of the chain that are not applied, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[]                         $chain The chain.
	 * @param array<string, array<string, mixed>> $rows  The recorded rows, by migration id.
	 * @return list<Migration> The pending migrations.
	 */
	private function pending( array $chain, array $rows ): array {
		$pending = array();

		foreach ( $chain as $migration ) {
			if ( self::APPLIED !== ( $rows[ $migration->id() ]['state'] ?? null ) ) {
				$pending[] = $migration;
			}
		}

		return $pending;
	}

	/**
	 * Builds and validates the chain on first use: kinds, ids, duplicates, and the bootstrap migration first.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the registered migrations cannot form a chain.
	 *
	 * @return list<Migration> The migrations in id order.
	 */
	private function chain(): array {
		if ( null !== $this->chain ) {
			return $this->chain;
		}

		$byId = array();

		foreach ( $this->migrations as $migration ) {
			if ( ! $migration instanceof SchemaMigration && ! $migration instanceof DataMigration ) {
				throw new \LogicException( 'Every migration must implement SchemaMigration or DataMigration.' );
			}

			$id = $migration->id();

			if ( 1 !== preg_match( self::ID_PATTERN, $id ) ) {
				throw new \LogicException( sprintf( 'Migration id "%s" must look like YYYYMMDD_NNNN_name.', $id ) );
			}

			if ( isset( $byId[ $id ] ) ) {
				throw new \LogicException( sprintf( 'Migration id %s is registered twice.', $id ) );
			}

			$byId[ $id ] = $migration;
		}

		ksort( $byId, SORT_STRING );

		$chain = array_values( $byId );

		if ( ! isset( $chain[0] ) || ! $chain[0] instanceof PlatformBootstrapMigration ) {
			throw new \LogicException( sprintf( 'The first migration must be %s, which creates the migrator\'s own tables.', PlatformBootstrapMigration::ID ) );
		}

		$this->chain = $chain;

		return $chain;
	}

	/**
	 * Tells whether the run's time budget is spent.
	 *
	 * @since 0.1.0
	 *
	 * @param MigrationRunOptions $options The run's bounds.
	 * @param int                 $started hrtime() when the run began, in nanoseconds.
	 * @return bool True when a budget is set and has run out.
	 */
	private function budgetSpent( MigrationRunOptions $options, int $started ): bool {
		$budget = $options->timeBudgetSeconds();

		return null !== $budget && (int) hrtime( true ) - $started >= $budget * 1000000000;
	}

	/**
	 * Returns the verifier.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaVerifier The verifier.
	 */
	private function verifier(): SchemaVerifier {
		if ( null === $this->verifier ) {
			$this->verifier = new SchemaVerifier( $this->db );
		}

		return $this->verifier;
	}

	/**
	 * Returns the DDL operations handed to schema migrations.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaOperations The operations.
	 */
	private function operations(): SchemaOperations {
		if ( null === $this->operations ) {
			$this->operations = new SchemaOperations( $this->db, new DdlGenerator(), $this->verifier() );
		}

		return $this->operations;
	}

	/**
	 * Returns the current time for a row timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @return string UTC, as MySQL's DATETIME.
	 */
	private function now(): string {
		return $this->clock->now()->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Returns the checksum of a migration's class file.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration $migration The migration.
	 * @return string SHA-256 in hexadecimal, or 64 zeros when the file cannot be read.
	 */
	private static function checksum( Migration $migration ): string {
		$file = ( new \ReflectionClass( $migration ) )->getFileName();
		$hash = false === $file ? false : hash_file( 'sha256', $file );

		return false === $hash ? str_repeat( '0', 64 ) : $hash;
	}

	/**
	 * Returns the ids of migrations.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[] $migrations The migrations.
	 * @return list<string> Their ids, in the same order.
	 */
	private static function ids( array $migrations ): array {
		return array_map( static fn( Migration $migration ): string => $migration->id(), $migrations );
	}

	/**
	 * Returns the milliseconds since a moment.
	 *
	 * @since 0.1.0
	 *
	 * @param int $began hrtime() at that moment, in nanoseconds.
	 * @return int Milliseconds.
	 */
	private static function elapsedMs( int $began ): int {
		return intdiv( (int) hrtime( true ) - $began, 1000000 );
	}

	/**
	 * Returns the running plugin version.
	 *
	 * @since 0.1.0
	 *
	 * @return string SEOCART_VERSION, or `unknown` when the plugin's main file is not loaded.
	 */
	private static function pluginVersion(): string {
		return defined( 'SEOCART_VERSION' ) ? (string) SEOCART_VERSION : 'unknown';
	}
}
