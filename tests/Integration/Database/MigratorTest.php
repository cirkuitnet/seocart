<?php
/**
 * Tests the migrator, its bootstrap migration and `wp seocart migrate` against real tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Database;

use SEOCart\Platform\Database\Cli\MigrateCommand;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\DatabaseError;
use SEOCart\Platform\Database\DatabaseState;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Platform\Database\Exception\MigrationFailed;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\ReportCode;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\PlatformTables;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingDatabaseState;
use SEOCart\Tests\Support\Migrations\CreatesTestTable;
use SEOCart\Tests\Support\Migrations\DeclaresMissingIndex;
use SEOCart\Tests\Support\Migrations\MarksRowsInBatches;
use SEOCart\Tests\Support\QueryLog;
use SEOCart\Tests\Support\SecondConnection;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- These tests plant and inspect migrator state directly, as another runner or a crash would leave it.

/**
 * The migrator, its bootstrap migration and `wp seocart migrate`: runs, resumes, failures, the
 * schema lock, the write gate, and the re-run of a migration whose tables already exist.
 *
 * Fixture migrations live in tests/Support/Migrations/ and create `test_` tables, which the
 * base class drops after each test together with `migrations` and `locks`.
 *
 * Each test names its planted violation, in src/Platform/Database/Migrator.php unless it says
 * otherwise.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class MigratorTest extends DatabaseTestCase {

	/**
	 * The instant the frozen clock shows.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-22 12:00:00';

	/**
	 * On an empty database the bootstrap creates `migrations` and `locks` and records itself.
	 *
	 * Planted violation: at the top of recordApplied(), return when the migration is the bootstrap.
	 *
	 * @since 0.1.0
	 */
	public function test_the_bootstrap_creates_the_platform_tables_and_records_itself(): void {
		$b     = $this->secondConnection();
		$state = new MigrationsTableState( $this->db );

		$this->assertNull( $state->schemaHead(), 'No migrations table yet: the head is unknown, not an error.' );

		$report = $this->migrator( array( new PlatformBootstrapMigration() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID ), array_column( $report->applied(), 'id' ) );

		foreach ( array( 'migrations', 'locks' ) as $table ) {
			$this->assertSame( 'InnoDB', $b->fetchValue( sprintf( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $this->db->table( $table ) ) ), $table );
		}

		$row = $this->migrationRow( $b, PlatformBootstrapMigration::ID );

		$this->assertSame( Migrator::APPLIED, $row['state'] );
		$this->assertSame( 'schema', $row['kind'] );
		$this->assertSame( '0', $row['can_operate_half_applied'] );
		$this->assertSame( SEOCART_VERSION, $row['plugin_version'] );
		$this->assertSame( self::NOW, $row['applied_at'] );
		$this->assertSame( hash_file( 'sha256', dirname( __DIR__, 3 ) . '/src/Platform/Database/Migrations/PlatformBootstrapMigration.php' ), $row['checksum'] );

		$summary = json_decode( (string) $row['postcondition_json'], true );

		$this->assertSame( array( $this->db->table( 'migrations' ), $this->db->table( 'locks' ) ), array_column( $summary['tables'], 'table' ) );
		$this->assertSame( PlatformBootstrapMigration::ID, ( new MigrationsTableState( $this->db ) )->schemaHead() );

		$status = $this->migrator( array( new PlatformBootstrapMigration() ) )->status();

		$this->assertFalse( $status->writesBlocked() );
		$this->assertSame( PlatformBootstrapMigration::ID, $status->appliedHead() );
		$this->assertSame( array(), $status->pending() );
	}

	/**
	 * The bootstrap tolerates a runner that creates its table between the existence check and CREATE (error 1050).
	 *
	 * Planted violation: in createRacing(), rethrow every QueryFailed.
	 *
	 * @since 0.1.0
	 */
	public function test_the_bootstrap_tolerates_a_racing_creator(): void {
		$b      = $this->secondConnection();
		$table  = $this->db->table( 'migrations' );
		$create = ( new DdlGenerator() )->createTable( PlatformTables::migrations(), $table, $this->db->charsetCollate() );
		$raced  = false;

		add_filter(
			'query',
			static function ( $query ) use ( $b, $table, $create, &$raced ) {
				if ( ! $raced && is_string( $query ) && str_starts_with( $query, 'CREATE TABLE `' . $table . '`' ) ) {
					$raced = true;

					// The other runner wins: its CREATE lands just before this one is sent.
					$b->query( $create );
				}

				return $query;
			}
		);

		$report = $this->migrator( array( new PlatformBootstrapMigration() ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertTrue( $raced, 'The race must have been staged.' );
		$this->assertSame( array( PlatformBootstrapMigration::ID ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( Migrator::APPLIED, $this->migrationRow( $b, PlatformBootstrapMigration::ID )['state'] );
	}

	/**
	 * A chain applies in id order; a second run changes nothing and sends no DDL.
	 *
	 * Planted violation: in pending(), ignore the state and return the whole chain.
	 *
	 * @since 0.1.0
	 */
	public function test_a_chain_applies_in_order_once(): void {
		$b     = $this->secondConnection();
		$a     = new CreatesTestTable( '20990101_0001_a', 'a' );
		$bee   = new CreatesTestTable( '20990101_0002_b', 'b' );
		$chain = array( new PlatformBootstrapMigration(), $a, $bee );

		$first = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( PlatformBootstrapMigration::ID, $a->id(), $bee->id() ), array_column( $first->applied(), 'id' ), 'Applied in id order.' );
		$this->assertSame( '3', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->db->table( 'migrations' ) ) ) );

		$second = null;
		$log    = $this->captureQueries(
			function () use ( $chain, &$second ): void {
				$second = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
			}
		);

		$this->assertInstanceOf( MigrationReport::class, $second );
		$this->assertSame( MigrationReport::UP_TO_DATE, $second->outcome() );
		$this->assertSame( '3', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s`', $this->db->table( 'migrations' ) ) ), 'No row was added.' );
		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL on a second run' );
		$this->assertSame( 1, $a->runs );
		$this->assertSame( 1, $bee->runs );
	}

	/**
	 * A migration whose table exists but whose row was never written (a crash between DDL and
	 * the record) runs again: dbDelta finds the table matching its generated CREATE, sends no
	 * DDL, and the row becomes applied. This is also the proof that the generator's output
	 * parses in dbDelta exactly as the server describes the table.
	 *
	 * Planted violation: in DdlGenerator::column(), write a `char(n)` type as its synonym
	 * `character(n)`. MySQL reports `char(n)`, so dbDelta sends an ALTER on every run.
	 *
	 * @since 0.1.0
	 */
	public function test_a_migration_interrupted_after_its_ddl_resumes_without_ddl(): void {
		$b = $this->secondConnection();
		$a = new CreatesTestTable( '20990101_0001_a', 'a' );

		$this->migrator( array( new PlatformBootstrapMigration(), $a ) )->migrate( new MigrationRunOptions( 0 ) );

		$b->query( sprintf( "DELETE FROM `%s` WHERE migration_id = '%s'", $this->db->table( 'migrations' ), $a->id() ) );

		$log = $this->captureQueries( fn() => $this->migrator( array( new PlatformBootstrapMigration(), $a ) )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertSame( 2, $a->runs, 'up() ran again.' );
		$this->assertQueryCount( 0, $log->ofType( 'CREATE', 'ALTER', 'DROP' ), 'DDL when re-running up() on a table that exists' );
		$this->assertGreaterThan( 0, count( $log->matching( '/^DESCRIBE /' ) ), 'dbDelta compared the existing table.' );
		$this->assertSame( Migrator::APPLIED, $this->migrationRow( $b, $a->id() )['state'] );
	}

	/**
	 * Lists both lock modes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{LockMode}> The modes.
	 */
	public static function lockModes(): array {
		return array(
			'table lock' => array( LockMode::Table ),
			'GET_LOCK'   => array( LockMode::GetLock ),
		);
	}

	/**
	 * A migration whose post-conditions fail is recorded failed with the diff, stops the chain and releases the lock.
	 *
	 * The fixture DeclaresMissingIndex is the permanent plant: its declaration names an index
	 * its up() never creates, so only the verifier can notice.
	 *
	 * Planted violation: in SchemaVerifier::diff(), drop the index comparison.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider lockModes
	 *
	 * @param LockMode $mode How the schema lock is held.
	 */
	public function test_a_failed_post_condition_stops_the_chain( LockMode $mode ): void {
		$b = $this->secondConnection();
		$a = new CreatesTestTable( '20990101_0001_a', 'a' );
		$c = new DeclaresMissingIndex( '20990101_0003_c', 'c' );
		$d = new CreatesTestTable( '20990101_0004_d', 'd' );

		$this->migrator( array( new PlatformBootstrapMigration(), $a ), $mode )->migrate( new MigrationRunOptions( 0 ) );

		$failed = null;

		try {
			$this->migrator( array( new PlatformBootstrapMigration(), $a, $c, $d ), $mode )->migrate( new MigrationRunOptions( 0 ) );
		} catch ( MigrationFailed $failure ) {
			$failed = $failure;
		}

		$this->assertNotNull( $failed, 'C must fail its post-conditions.' );
		$this->assertSame( $c->id(), $failed->migrationId() );
		$this->assertSame( ReportCode::PostconditionMismatch->value, $failed->recordedCode() );
		$this->assertSame( array( $this->db->table( 'test_c' ) . ': index value does not exist' ), $failed->diff() );

		$row = $this->migrationRow( $b, $c->id() );

		$this->assertSame( Migrator::FAILED, $row['state'] );
		$this->assertSame( ReportCode::PostconditionMismatch->value, $row['error_code'] );
		$this->assertSame( $failed->diff(), json_decode( (string) $row['postcondition_json'], true ) );
		$this->assertNull( $b->fetchRow( sprintf( "SELECT state FROM `%s` WHERE migration_id = '%s'", $this->db->table( 'migrations' ), $d->id() ) ), 'D was not attempted.' );
		$this->assertSame( 0, $d->runs );
		$this->assertSame( $a->id(), ( new MigrationsTableState( $this->db ) )->schemaHead(), 'The head is still A.' );

		if ( LockMode::Table === $mode ) {
			$this->assertNull( $b->fetchValue( sprintf( "SELECT owner_token FROM `%s` WHERE name = 'schema'", $this->db->table( 'locks' ) ) ), 'The table lock was released.' );
		} else {
			$this->assertNull( $b->fetchValue( sprintf( "SELECT IS_USED_LOCK( '%s' )", LockService::serverLockName( $this->db->databaseName(), $this->db->prefix(), Migrator::SCHEMA_LOCK ) ) ), 'The server lock was released.' );
		}

		$status = $this->migrator( array( new PlatformBootstrapMigration(), $a, $c, $d ), $mode )->status();

		$this->assertTrue( $status->writesBlocked(), 'C cannot operate half-applied.' );
		$this->assertSame( $c->id(), $status->failed() );
		$this->assertSame( array( $c->id(), $d->id() ), $status->pending() );
	}

	/**
	 * An interrupted chain resumes at the migration that failed, and never repeats one that was applied.
	 *
	 * Planted violation: in pending(), ignore the state and return the whole chain.
	 *
	 * @since 0.1.0
	 */
	public function test_an_interrupted_chain_resumes_where_it_stopped(): void {
		$b = $this->secondConnection();
		$a = new CreatesTestTable( '20990101_0001_a', 'a' );
		$e = new CreatesTestTable( '20990101_0005_e', 'e' );
		$f = new CreatesTestTable( '20990101_0006_f', 'f' );

		$e->beforeUp = static function (): void {
			throw new \RuntimeException( 'the process died inside E' );
		};

		$chain  = array( new PlatformBootstrapMigration(), $a, $e, $f );
		$failed = null;

		try {
			$this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );
		} catch ( MigrationFailed $failure ) {
			$failed = $failure;
		}

		$this->assertNotNull( $failed );
		$this->assertSame( $e->id(), $failed->migrationId() );
		$this->assertSame( DatabaseError::MigrationFailed->value, $failed->recordedCode(), 'A failure that is not a coded failure is recorded with the migrator\'s own code.' );
		$this->assertInstanceOf( \RuntimeException::class, $failed->getPrevious() );
		$this->assertSame( Migrator::FAILED, $this->migrationRow( $b, $e->id() )['state'] );
		$this->assertStringContainsString( 'the process died inside E', (string) $this->migrationRow( $b, $e->id() )['error_message'] );
		$this->assertNull( $b->fetchRow( sprintf( "SELECT state FROM `%s` WHERE migration_id = '%s'", $this->db->table( 'migrations' ), $f->id() ) ) );

		$e->beforeUp = null;

		$report = $this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( array( $e->id(), $f->id() ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( 1, $a->runs, 'A was not run again.' );
		$this->assertSame( Migrator::APPLIED, $this->migrationRow( $b, $e->id() )['state'] );
		$this->assertNull( $this->migrationRow( $b, $e->id() )['error_code'], 'The failure is cleared once it is applied.' );
	}

	/**
	 * A data migration commits each batch with its cursor; interrupted inside a batch, it resumes without repeating a row.
	 *
	 * Planted violation: in runBatches(), write the cursor after the batch's transaction
	 * returns, instead of inside it. The log assertion turns red, and so does the next test.
	 *
	 * @since 0.1.0
	 */
	public function test_a_data_migration_resumes_from_its_cursor(): void {
		$b     = $this->secondConnection();
		$marks = $this->seedMarks();

		$marks->onBatch = static function ( int $batch ): void {
			if ( 3 === $batch ) {
				throw new \RuntimeException( 'batch 3 failed' );
			}
		};

		try {
			$this->migrator( array( new PlatformBootstrapMigration(), $marks ) )->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'Batch 3 must fail the migration.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( $marks->id(), $failed->migrationId() );
		}

		$this->assertSame( Migrator::FAILED, $this->migrationRow( $b, $marks->id() )['state'] );
		$this->assertSame( '6', $this->migrationRow( $b, $marks->id() )['batch_cursor'], 'Batches 1 and 2 committed with their cursor; batch 3 rolled back.' );
		$this->assertSame( '1,1,1,1,1,1,0,0,0,0', $this->marks( $b ) );

		$marks->onBatch = null;

		$log = $this->captureQueries( fn() => $this->migrator( array( new PlatformBootstrapMigration(), $marks ) )->migrate( new MigrationRunOptions( 0 ) ) );

		$this->assertSame( '1,1,1,1,1,1,1,1,1,1', $this->marks( $b ), 'Every row marked exactly once.' );
		$this->assertSame( Migrator::APPLIED, $this->migrationRow( $b, $marks->id() )['state'] );
		$this->assertNull( $this->migrationRow( $b, $marks->id() )['batch_cursor'] );
		$this->assertSame( 2, $this->assertEachBatchCommitsWithItsCursor( $log ), 'Two batches remained: rows 6 to 8, then row 9.' );
	}

	/**
	 * A crash between a batch's COMMIT and whatever follows it replays nothing.
	 *
	 * The fixture registers an after-commit callback that fails in batch 2, and this migrator's
	 * reporter throws when that failure is reported: the run stops right after the batch
	 * committed, which is exactly a process dying there.
	 *
	 * Planted violation: as for the test above. With the cursor written after the transaction,
	 * the crash leaves the cursor behind the committed rows, and the re-run marks rows 3 to 5 again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_crash_right_after_a_batch_commits_replays_nothing(): void {
		global $wpdb;

		$b     = $this->secondConnection();
		$marks = $this->seedMarks();
		$dying = new Database(
			$wpdb,
			true,
			static function ( string $code ): void {
				if ( ReportCode::AfterCommitFailed->value === $code ) {
					throw new \RuntimeException( 'the process died right after batch 2 committed' );
				}
			},
			5,
			$this->sleeper(),
			$this->randomSource()
		);

		$marks->onBatch = static function ( int $batch, Database $db ): void {
			if ( 2 === $batch ) {
				$db->afterCommit(
					static function (): void {
						throw new \RuntimeException( 'a listener failed after batch 2 committed' );
					}
				);
			}
		};

		try {
			$this->migrator( array( new PlatformBootstrapMigration(), $marks ), LockMode::Table, $dying )->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'The crash must fail the migration.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( $marks->id(), $failed->migrationId() );
		}

		$this->assertSame( '6', $this->migrationRow( $b, $marks->id() )['batch_cursor'], 'Batch 2 and its cursor committed together.' );

		$marks->onBatch = null;

		$this->migrator( array( new PlatformBootstrapMigration(), $marks ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( '1,1,1,1,1,1,1,1,1,1', $this->marks( $b ), 'No row was marked twice.' );
	}

	/**
	 * A time budget stops a data migration after a batch, leaves it running, and a later run finishes it.
	 *
	 * Planted violation: make budgetSpent() return false.
	 *
	 * @since 0.1.0
	 */
	public function test_the_time_budget_leaves_a_data_migration_running(): void {
		$b     = $this->secondConnection();
		$marks = $this->seedMarks();

		$report = $this->migrator( array( new PlatformBootstrapMigration(), $marks ) )->migrate( new MigrationRunOptions( 0, 0 ) );

		$this->assertSame( MigrationReport::INCOMPLETE, $report->outcome() );
		$this->assertSame( $marks->id(), $report->incompleteMigration() );
		$this->assertSame( Migrator::RUNNING, $this->migrationRow( $b, $marks->id() )['state'] );
		$this->assertSame( '3', $this->migrationRow( $b, $marks->id() )['batch_cursor'] );
		$this->assertSame( '1,1,1,0,0,0,0,0,0,0', $this->marks( $b ), 'One batch ran.' );

		$later = $this->migrator( array( new PlatformBootstrapMigration(), $marks ) )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertSame( MigrationReport::APPLIED, $later->outcome() );
		$this->assertSame( '1,1,1,1,1,1,1,1,1,1', $this->marks( $b ) );
	}

	/**
	 * A runner that waited for another's schema lock re-reads the table, skips what the
	 * other applied, and applies the rest.
	 *
	 * B holds the schema lease. The sleeper is the barrier: B records G as applied, as the
	 * other node would, and releases its lease; attempt 2 then takes the lock.
	 *
	 * Planted violation: in runLocked(), use $pendingBefore instead of re-reading the rows.
	 *
	 * @since 0.1.0
	 *
	 * @group concurrency
	 */
	public function test_a_runner_that_waited_skips_what_another_runner_applied(): void {
		$b = $this->secondConnection();
		$g = new CreatesTestTable( '20990101_0007_g', 'g' );
		$h = new CreatesTestTable( '20990101_0008_h', 'h' );

		$this->migrator( array( new PlatformBootstrapMigration() ) )->migrate( new MigrationRunOptions( 0 ) );
		$this->holdSchemaLock( $b );

		$this->onSleep = function () use ( $b, $g ): void {
			$b->query(
				sprintf(
					"INSERT INTO `%s` ( migration_id, kind, can_operate_half_applied, state, plugin_version, checksum, applied_at, created_at, updated_at ) VALUES ( '%s', 'schema', 1, 'applied', '0.1.0', '%s', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
					$this->db->table( 'migrations' ),
					$g->id(),
					hash_file( 'sha256', (string) ( new \ReflectionClass( $g ) )->getFileName() )
				)
			);
			$b->query( sprintf( "UPDATE `%s` SET owner_token = NULL WHERE name = 'schema'", $this->db->table( 'locks' ) ) );
		};

		$report = $this->migrator( array( new PlatformBootstrapMigration(), $g, $h ) )->migrate( new MigrationRunOptions( 5 ) );

		$this->assertSame( array( LockService::POLL_MILLISECONDS ), $this->sleeps, 'One pause, then the lock was free.' );
		$this->assertSame( 0, $g->runs, 'G was applied elsewhere and must not run here.' );
		$this->assertSame( 1, $h->runs );
		$this->assertSame( array( $g->id() ), $report->appliedElsewhere() );
		$this->assertSame( array( $h->id() ), array_column( $report->applied(), 'id' ) );
		$this->assertSame( array(), $this->reports, 'G\'s recorded checksum matches its file.' );
	}

	/**
	 * A changed checksum on an applied migration is reported once, and nothing is applied.
	 *
	 * Planted violation: in reportChecksumMismatches(), delete the comparison's report.
	 *
	 * @since 0.1.0
	 */
	public function test_a_changed_checksum_is_reported_not_fatal(): void {
		$b = $this->secondConnection();
		$a = new CreatesTestTable( '20990101_0001_a', 'a' );

		$this->migrator( array( new PlatformBootstrapMigration(), $a ) )->migrate( new MigrationRunOptions( 0 ) );

		$b->query( sprintf( "UPDATE `%s` SET checksum = '%s' WHERE migration_id = '%s'", $this->db->table( 'migrations' ), str_repeat( '0', 64 ), $a->id() ) );

		$lines = array();
		$code  = $this->command( array( new PlatformBootstrapMigration(), $a ), $lines )->run( array( 'wait' => '0' ) );

		$this->assertSame( MigrateCommand::EXIT_OK, $code );
		$this->assertContains( 'Nothing to migrate: the schema is up to date.', $lines );
		$this->assertSame( 1, $a->runs );
		$this->assertCount( 1, $this->reports );
		$this->assertSame( ReportCode::MigrationChecksumMismatch->value, $this->reports[0]['code'] );
		$this->assertSame( $a->id(), $this->reports[0]['context']['migration_id'] );
	}

	/**
	 * `wp seocart migrate` exits 0 when it applied, 1 on a failure with the diff printed, 2 when blocked.
	 *
	 * Planted violation: in MigrateCommand::migrateSite(), return EXIT_OK from the MigrationFailed catch.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_reports_through_its_exit_code(): void {
		$b = $this->secondConnection();
		$a = new CreatesTestTable( '20990101_0001_a', 'a' );

		$lines = array();

		$this->assertSame( MigrateCommand::EXIT_OK, $this->command( array( new PlatformBootstrapMigration(), $a ), $lines )->run( array( 'wait' => '0' ) ) );
		$this->assertMatchesRegularExpression( '/^Applied 20260922_0001_platform_bootstrap in \d+ ms\.$/', $lines[0] );
		$this->assertMatchesRegularExpression( '/^Applied 20990101_0001_a in \d+ ms\.$/', $lines[1] );

		$lines = array();
		$c     = new DeclaresMissingIndex( '20990101_0003_c', 'c' );

		$this->assertSame( MigrateCommand::EXIT_FAILED, $this->command( array( new PlatformBootstrapMigration(), $a, $c ), $lines )->run( array( 'wait' => '0' ) ) );
		$this->assertContains( 'Migration 20990101_0003_c failed: database.postcondition_mismatch', $lines );
		$this->assertContains( '  ' . $this->db->table( 'test_c' ) . ': index value does not exist', $lines );

		$lines = array();

		$this->holdSchemaLock( $b );

		$this->assertSame( MigrateCommand::EXIT_BLOCKED, $this->command( array( new PlatformBootstrapMigration(), $a, $c ), $lines )->run( array( 'wait' => '0' ) ) );
		$this->assertContains( 'Another runner holds the schema lock, so nothing was changed. Run the command again later.', $lines );

		if ( is_multisite() ) {
			// On a network, --network iterates the sites: the network test covers that branch.
			return;
		}

		$lines = array();

		$this->assertSame( MigrateCommand::EXIT_FAILED, $this->command( array( new PlatformBootstrapMigration() ), $lines )->run( array( 'network' => true ) ) );
		$this->assertSame( array( '--network needs a multisite installation. Run the command without it to migrate this site.' ), $lines );
	}

	/**
	 * The `--network` option migrates each site of a batch on its own prefix.
	 *
	 * @since 0.1.0
	 */
	public function test_network_migrates_every_site_of_the_batch(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Needs a multisite test run (WP_MULTISITE=1).' );
		}

		global $wpdb;

		$b    = $this->secondConnection();
		$site = wp_insert_site(
			array(
				'domain' => 'example.org',
				'path'   => '/seocart-network-test/',
			)
		);

		$this->assertIsInt( $site );

		try {
			$lines = array();
			$code  = $this->command( array( new PlatformBootstrapMigration() ), $lines )->run(
				array(
					'network'   => true,
					'from-site' => '1',
					'batch'     => '50',
				)
			);

			$this->assertSame( MigrateCommand::EXIT_OK, $code, implode( "\n", $lines ) );

			foreach ( array( 1, $site ) as $id ) {
				$prefix = $wpdb->get_blog_prefix( $id );

				$this->assertSame( '1', $b->fetchValue( sprintf( "SELECT COUNT(*) FROM `%sseocart_migrations` WHERE state = 'applied'", $prefix ) ), 'Site ' . $id );
			}
		} finally {
			foreach ( array( 'migrations', 'locks' ) as $table ) {
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->get_blog_prefix( $site ) . 'seocart_' . $table ) );
			}

			wp_delete_site( $site );
		}
	}

	/**
	 * A data migration that can operate half-applied, left running by the time budget, keeps the store trading.
	 *
	 * The heads differ here (the code's newest migration is not applied), and that alone must
	 * not refuse writes.
	 *
	 * Planted violation: in Migrator::blocksWrites(), refuse writes whenever $outstanding is not
	 * empty, which is the old "the heads differ" rule.
	 *
	 * @since 0.1.0
	 */
	public function test_a_backfill_in_progress_leaves_writes_open(): void {
		$a     = new CreatesTestTable( '20990101_0001_a', 'a' );
		$marks = $this->seedMarks();
		$chain = array( new PlatformBootstrapMigration(), $a, $marks );
		$state = new RecordingDatabaseState();

		$report = $this->migrator( $chain, LockMode::Table, null, $state )->migrate( new MigrationRunOptions( 0, 0 ) );

		$this->assertSame( MigrationReport::INCOMPLETE, $report->outcome() );
		$this->assertSame( array( $a->id() ), $state->heads, 'The recorded head is the newest applied migration.' );

		$migrator = $this->migrator( $chain );
		$status   = $migrator->status();

		$this->assertSame( $marks->id(), $status->running() );
		$this->assertNotSame( $status->codeHead(), $status->appliedHead(), 'The heads differ while the backfill runs.' );
		$this->assertFalse( $status->writesBlocked(), 'A half-applicable backfill must not refuse writes.' );
		$this->assertFalse( $migrator->writesBlocked( $a->id() ), 'The zero-query gate agrees.' );
	}

	/**
	 * A migration that cannot operate half-applied refuses writes while it is outstanding, even behind a running backfill.
	 *
	 * Planted violation: in Migrator::blocksWrites(), delete the loop over $outstanding.
	 *
	 * @since 0.1.0
	 */
	public function test_a_migration_that_cannot_operate_half_applied_blocks_writes_until_applied(): void {
		$marks = $this->seedMarks();
		$z     = new CreatesTestTable( '20990101_0010_z', 'z', false );
		$chain = array( new PlatformBootstrapMigration(), $marks, $z );

		$this->migrator( $chain )->migrate( new MigrationRunOptions( 0, 0 ) );

		$migrator = $this->migrator( $chain );
		$status   = $migrator->status();

		$this->assertSame( array( $marks->id(), $z->id() ), $status->pending(), 'The backfill runs and Z waits behind it.' );
		$this->assertTrue( $status->writesBlocked(), 'Z cannot operate half-applied.' );
		$this->assertTrue( $migrator->writesBlocked( PlatformBootstrapMigration::ID ), 'The zero-query gate agrees.' );

		$this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->assertFalse( $this->migrator( $chain )->status()->writesBlocked(), 'Writes reopen once Z is applied.' );
		$this->assertFalse( $migrator->writesBlocked( $z->id() ) );
	}

	/**
	 * A schema newer than the code refuses writes, and an older release that runs does not lower the recorded head.
	 *
	 * Planted violations: in Migrator::blocksWrites(), delete the comparison of the applied head
	 * with the code head; and, separately, in Migrator::recordHead(), consider only the
	 * migrations of this release's chain.
	 *
	 * @since 0.1.0
	 */
	public function test_a_schema_newer_than_the_code_blocks_writes(): void {
		$b     = $this->secondConnection();
		$a     = new CreatesTestTable( '20990101_0001_a', 'a' );
		$chain = array( new PlatformBootstrapMigration(), $a );
		$newer = '20990101_0099_newer';

		$this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		// A newer release applied a migration this code does not know.
		$b->query(
			sprintf(
				"INSERT INTO `%s` ( migration_id, kind, can_operate_half_applied, state, plugin_version, checksum, applied_at, created_at, updated_at ) VALUES ( '%s', 'schema', 0, 'applied', '9.9.9', '%s', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP() )",
				$this->db->table( 'migrations' ),
				$newer,
				str_repeat( 'e', 64 )
			)
		);

		$state    = new RecordingDatabaseState();
		$migrator = $this->migrator( $chain, LockMode::Table, null, $state );

		$this->assertSame( MigrationReport::UP_TO_DATE, $migrator->migrate( new MigrationRunOptions( 0 ) )->outcome() );
		$this->assertSame( array( $newer ), $state->heads, 'An older release must not lower the recorded head.' );

		$status = $migrator->status();

		$this->assertSame( $newer, $status->appliedHead() );
		$this->assertSame( array(), $status->pending() );
		$this->assertTrue( $status->writesBlocked(), 'The schema is newer than the code.' );
		$this->assertTrue( $migrator->writesBlocked( $newer ), 'The zero-query gate agrees.' );
		$this->assertFalse( $migrator->writesBlocked( $a->id() ), 'At the code\'s own head, writes are open.' );
	}

	/**
	 * The zero-query gate decides from the registry and the head alone, and sends no query.
	 *
	 * Planted violation: at the top of Migrator::writesBlocked(), call $this->rows().
	 *
	 * @since 0.1.0
	 */
	public function test_the_write_gate_sends_no_query(): void {
		$migrator = $this->migrator( array( new PlatformBootstrapMigration(), new CreatesTestTable( '20990101_0001_a', 'a' ), new CreatesTestTable( '20990101_0010_z', 'z', false ) ) );
		$answers  = array();

		$log = $this->captureQueries(
			function () use ( $migrator, &$answers ): void {
				foreach ( array( null, PlatformBootstrapMigration::ID, '20990101_0001_a', '20990101_0010_z', '20990101_0099_newer' ) as $head ) {
					$answers[] = $migrator->writesBlocked( $head );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'The write gate' );
		$this->assertSame(
			array( true, true, true, false, true ),
			$answers,
			'Nothing applied, then the bootstrap, then A (Z outstanding each time), then Z (all applied), then a head newer than the code.'
		);
	}

	/**
	 * A registry list that is not in strictly ascending id order is refused before anything is read or sent.
	 *
	 * Planted violation: in Migrator::chain(), delete the order check and sort the list by id instead.
	 *
	 * @since 0.1.0
	 */
	public function test_a_registry_out_of_id_order_is_refused(): void {
		global $wpdb;

		$a      = new CreatesTestTable( '20990101_0001_a', 'a' );
		$bee    = new CreatesTestTable( '20990101_0002_b', 'b' );
		$before = count( (array) $wpdb->queries );

		foreach ( array(
			'swapped'   => array( new PlatformBootstrapMigration(), $bee, $a ),
			'duplicate' => array( new PlatformBootstrapMigration(), $a, new CreatesTestTable( '20990101_0001_a', 'again' ) ),
		) as $case => $chain ) {
			try {
				$this->migrator( $chain )->status();
				$this->fail( 'A registry ' . $case . ' must be refused.' );
			} catch ( \LogicException $refused ) {
				$this->assertStringContainsString( 'strictly ascending id order', $refused->getMessage(), $case );
			}

			$this->assertFalse( $this->migrator( array( new PlatformBootstrapMigration(), $a, $bee ) )->writesBlocked( $bee->id() ), 'The same migrations in order are accepted.' );
		}

		$this->assertSame( $before, count( (array) $wpdb->queries ), 'The order check runs before any query.' );
	}

	/**
	 * A migration that is not applied but sorts before the newest applied one blocks writes and is reported.
	 *
	 * The zero-query gate counts only migrations after the head, so it cannot see this; status(),
	 * which admin and command-line requests call, can.
	 *
	 * Planted violation: in Migrator::blocksWrites(), delete the out-of-order check, and in
	 * Migrator::status(), delete the report loop.
	 *
	 * @since 0.1.0
	 */
	public function test_status_reports_and_blocks_a_migration_that_sorts_before_the_head(): void {
		$z = new CreatesTestTable( '20990101_0010_z', 'z' );
		$m = new CreatesTestTable( '20990101_0005_m', 'm' );

		$this->migrator( array( new PlatformBootstrapMigration(), $z ) )->migrate( new MigrationRunOptions( 0 ) );

		// A later release registers M, whose id sorts before Z, which is already applied.
		$chain    = array( new PlatformBootstrapMigration(), $m, $z );
		$migrator = $this->migrator( $chain );
		$status   = $migrator->status();

		$this->assertSame( array( $m->id() ), $status->pending() );
		$this->assertTrue( $status->writesBlocked(), 'An inconsistent chain refuses writes, although M can operate half-applied.' );
		$this->assertSame(
			array(
				array(
					'code'    => ReportCode::MigrationOutOfOrder->value,
					'context' => array(
						'migration_id' => $m->id(),
						'schema_head'  => $z->id(),
					),
				),
			),
			$this->reports
		);
		$this->assertFalse( $migrator->writesBlocked( $z->id() ), 'The zero-query gate cannot see it; this is why status() checks.' );

		$this->migrator( $chain )->migrate( new MigrationRunOptions( 0 ) );

		$this->reports = array();

		$this->assertFalse( $this->migrator( $chain )->status()->writesBlocked(), 'Once M is applied, the chain is consistent again.' );
		$this->assertSame( array(), $this->reports );
	}

	/**
	 * A run that fails still records the head it reached.
	 *
	 * Planted violation: in runLocked(), rethrow a failed migration without recording the head.
	 *
	 * @since 0.1.0
	 */
	public function test_a_run_that_fails_records_the_head_it_reached(): void {
		$a     = new CreatesTestTable( '20990101_0001_a', 'a' );
		$c     = new DeclaresMissingIndex( '20990101_0003_c', 'c' );
		$state = new RecordingDatabaseState();

		try {
			$this->migrator( array( new PlatformBootstrapMigration(), $a, $c ), LockMode::Table, null, $state )->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'C must fail.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( $c->id(), $failed->migrationId() );
		}

		$this->assertSame( array( $a->id() ), $state->heads, 'A was applied in this run, so the head is A.' );
	}

	/**
	 * Non-ASCII text is stored in a plugin table that mixes ascii_bin and utf8mb4 columns.
	 *
	 * WordPress judges such a table's character set as ascii and refuses any other text. The
	 * `migrations` table is such a table, and a failure message is written by people.
	 *
	 * Planted violation: in Database, do not register the pre_get_table_charset filter.
	 *
	 * @since 0.1.0
	 */
	public function test_non_ascii_text_is_stored_in_a_table_that_mixes_ascii_and_utf8mb4_columns(): void {
		$b = $this->secondConnection();
		$e = new CreatesTestTable( '20990101_0005_e', 'e' );

		$e->beforeUp = static function (): void {
			throw new \RuntimeException( 'Échec : la table a refusé 🙂' );
		};

		try {
			$this->migrator( array( new PlatformBootstrapMigration(), $e ) )->migrate( new MigrationRunOptions( 0 ) );
			$this->fail( 'E must fail.' );
		} catch ( MigrationFailed $failed ) {
			$this->assertSame( $e->id(), $failed->migrationId() );
		}

		$this->assertStringContainsString( 'Échec : la table a refusé 🙂', (string) $this->migrationRow( $b, $e->id() )['error_message'] );
		$this->assertSame( array(), $this->reports, 'The failure was recorded, not reported as unrecordable.' );

		$this->db->execute( 'UPDATE %i SET batch_cursor = %s, error_message = %s WHERE migration_id = %s', $this->db->table( 'migrations' ), 'café 🙂', 'naïve 🙂', $e->id() );

		$this->assertSame(
			array(
				'batch_cursor'  => 'café 🙂',
				'error_message' => 'naïve 🙂',
			),
			$this->db->fetchRow( 'SELECT batch_cursor, error_message FROM %i WHERE migration_id = %s', $this->db->table( 'migrations' ), $e->id() )
		);
	}

	/**
	 * The command exits 1 with the error's code and message on any database error, instead of a fatal.
	 *
	 * Planted violation: in MigrateCommand::migrateSite(), catch MigrationFailed only.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_exits_1_on_a_database_error(): void {
		$lines   = array();
		$command = $this->command( array( new PlatformBootstrapMigration() ), $lines );
		$code    = $this->db->transaction( static fn(): int => $command->run( array( 'wait' => '0' ) ) );

		$this->assertSame( MigrateCommand::EXIT_FAILED, $code );
		$this->assertStringStartsWith( 'database.forbidden_in_transaction: ', $lines[0] ?? '' );
	}

	/**
	 * Migrate() inside a transaction is refused before it sends anything, whether the guards throw or report.
	 *
	 * Planted violation: at the top of migrate(), delete the depth check. In strict mode the DDL
	 * guard then throws with the CREATE statement as its detail; in reporting mode the CREATE
	 * runs inside the window.
	 *
	 * @since 0.1.0
	 */
	public function test_migrate_inside_a_transaction_is_refused(): void {
		global $wpdb;

		foreach ( array( true, false ) as $strict ) {
			$db      = $this->makeDatabase( $strict );
			$before  = count( (array) $wpdb->queries );
			$refused = null;

			try {
				$db->transaction( fn() => $this->migrator( array( new PlatformBootstrapMigration() ), LockMode::Table, $db )->migrate( new MigrationRunOptions( 0 ) ) );
			} catch ( ForbiddenInsideTransaction $forbidden ) {
				$refused = $forbidden;
			}

			$log = QueryLog::fromWpdb( array_slice( (array) $wpdb->queries, $before ) );

			$this->assertNotNull( $refused, $strict ? 'strict' : 'reporting' );
			$this->assertSame( ForbiddenInsideTransaction::KIND_DDL, $refused->kind() );
			$this->assertSame( 'migrate', $refused->detail() );
			$this->assertQueryCount( 3, $log, 'Only the wrapper\'s START TRANSACTION, SAVEPOINT and ROLLBACK' );
			$this->assertSame( '0', $this->secondConnection()->fetchValue( sprintf( "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s'", $this->db->table( 'migrations' ) ) ) );
		}
	}

	/**
	 * Builds a migrator over the test's connection, with a frozen clock and the recording reporter.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[]        $migrations The chain.
	 * @param LockMode           $mode       Optional. How the schema lock is held. Default Table.
	 * @param Database|null      $db         Optional. The connection. Default the test's.
	 * @param DatabaseState|null $state      Optional. Receives the schema head. Default a MigrationsTableState.
	 * @return Migrator The migrator.
	 */
	private function migrator( array $migrations, LockMode $mode = LockMode::Table, ?Database $db = null, ?DatabaseState $state = null ): Migrator {
		$db = $db ?? $this->db;

		return new Migrator( $db, new LockService( $db, $mode, $this->sleeper() ), $state ?? new MigrationsTableState( $db ), $migrations, FrozenClock::at( self::NOW ), $this->reporter() );
	}

	/**
	 * Builds the command over a migrator, printing into a list.
	 *
	 * @since 0.1.0
	 *
	 * @param Migration[] $migrations The chain.
	 * @param string[]    $lines      Receives the printed lines.
	 * @return MigrateCommand The command.
	 */
	private function command( array $migrations, array &$lines ): MigrateCommand {
		return new MigrateCommand(
			$this->migrator( $migrations ),
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);
	}

	/**
	 * Has B hold the schema lease for an hour.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 */
	private function holdSchemaLock( SecondConnection $b ): void {
		$b->query(
			sprintf(
				"INSERT INTO `%s` ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( 'schema', '%s', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6) + INTERVAL 1 HOUR, 'cli:other-node', UTC_TIMESTAMP() ) ON DUPLICATE KEY UPDATE owner_token = VALUES( owner_token ), expires_at = VALUES( expires_at )",
				$this->db->table( 'locks' ),
				str_repeat( 'b', 64 )
			)
		);
	}

	/**
	 * Reads a migrations row as B sees it.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b           Connection B.
	 * @param string           $migrationId The migration id.
	 * @return array<string, string|null> The row.
	 */
	private function migrationRow( SecondConnection $b, string $migrationId ): array {
		$row = $b->fetchRow( sprintf( "SELECT * FROM `%s` WHERE migration_id = '%s'", $this->db->table( 'migrations' ), $migrationId ) );

		$this->assertIsArray( $row, 'There must be a row for ' . $migrationId );

		return $row;
	}

	/**
	 * Commits fixture rows 0 to 9 and returns a data migration that marks them three at a time.
	 *
	 * @since 0.1.0
	 *
	 * @return MarksRowsInBatches The migration.
	 */
	private function seedMarks(): MarksRowsInBatches {
		for ( $id = 0; $id < 10; $id++ ) {
			$this->insertRow( $id );
		}

		return new MarksRowsInBatches( '20990101_0009_marks', self::ROWS, 10, 3 );
	}

	/**
	 * Reads how often each fixture row was marked, in id order.
	 *
	 * @since 0.1.0
	 *
	 * @param SecondConnection $b Connection B.
	 * @return string The counts, comma-separated.
	 */
	private function marks( SecondConnection $b ): string {
		return (string) $b->fetchValue( sprintf( 'SELECT GROUP_CONCAT( n ORDER BY id ) FROM `%s`', $this->rowsTable() ) );
	}

	/**
	 * Asserts that every cursor update sits inside a START TRANSACTION ... COMMIT, one per transaction.
	 *
	 * @since 0.1.0
	 *
	 * @param QueryLog $log The queries of a run.
	 * @return int How many batch transactions the run committed.
	 */
	private function assertEachBatchCommitsWithItsCursor( QueryLog $log ): int {
		$open       = false;
		$cursors    = 0;
		$committed  = 0;
		$statements = explode( "\n", preg_replace( '/^\s+\d+\. (.*)\n\s+caller: .*$/m', '$1', $log->describe() ) );

		foreach ( $statements as $sql ) {
			if ( 'START TRANSACTION' === $sql ) {
				$open    = true;
				$cursors = 0;
			} elseif ( str_contains( $sql, 'SET batch_cursor' ) ) {
				$this->assertTrue( $open, 'The cursor was written outside the batch\'s transaction: ' . $sql );

				++$cursors;
			} elseif ( 'COMMIT' === $sql && $open ) {
				$this->assertSame( 1, $cursors, 'Each batch transaction writes its cursor exactly once.' );

				$open = false;
				++$committed;
			}
		}

		return $committed;
	}
}
