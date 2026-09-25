<?php
/**
 * Tests `wp seocart doctor` against a real site: zero on a clean one, non-zero on each planted fault, and nothing private printed
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cli;

use SEOCart\Platform\Cli\Doctor\Doctor;
use SEOCart\Platform\Cli\Doctor\OutboxCheck;
use SEOCart\Platform\Cli\DoctorCommand;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationReport;
use SEOCart\Platform\Database\MigrationRunOptions;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\DataRegistry\DataRegistry;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Platform\Logging\LogRetentionJob;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\DatabaseTestCase;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\PluginActions;
use SEOCart\Tests\Support\ReloadsRoles;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- These tests plant faults in real tables on purpose, and remove them.

/**
 * Doctor on a site installed by the production migration chain.
 *
 * Each fault is planted, doctor must exit 1 and name it, the fault is removed and doctor must
 * exit 0 again, which proves both that the plant was a real violation and that the check comes
 * back clean. The jobs are the real Action Scheduler's: set_up() records a runner's check-in
 * a moment ago, as a working site has one, and every job of the plugin's group is deleted
 * again in tear_down().
 *
 * @since 0.1.0
 */
final class DoctorTest extends DatabaseTestCase {

	use ReloadsRoles;

	/**
	 * The instant the frozen clock shows while migrating.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-23 12:00:00';

	/**
	 * Planted values that must never appear in doctor's output.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const PRIVATE_VALUES = array( 'jane.doe@example.com', 'planted-secret-value', '4111111111111111', 'planted@private' );

	/**
	 * The production registry.
	 *
	 * @since 0.1.0
	 *
	 * @var DataRegistry
	 */
	private DataRegistry $registry;

	/**
	 * The migrator over the production chain.
	 *
	 * @since 0.1.0
	 *
	 * @var Migrator
	 */
	private Migrator $migrator;

	/**
	 * What the last doctor run printed.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $printed = array();

	/**
	 * Installs the production schema on an empty test database.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();
		self::reloadRoles();

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->rowsTable() ) );

		$this->registry = OwnedData::registry();
		$this->migrator = new Migrator( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new MigrationsTableState( $this->db ), $this->registry->migrations(), FrozenClock::at( self::NOW ), $this->reporter() );

		$this->assertSame( MigrationReport::APPLIED, $this->migrator->migrate( new MigrationRunOptions( 0 ) )->outcome() );

		PluginActions::purge();
		PluginActions::checkIn();
	}

	/**
	 * Puts the roles back in step with the database.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		PluginActions::purge();

		parent::tear_down();
		self::reloadRoles();
	}

	/**
	 * Tests that a clean site passes every check and exits 0.
	 *
	 * The site was migrated in set_up() by the migrator, which recorded each checksum; doctor
	 * compares with Migrator::checksum(), so it follows whatever rule the migrator records by.
	 * Planted: the migrator's rule changed to `md5` (still passes: doctor follows it); and, as the
	 * control, doctor given a SHA-256 copy of its own while the migrator uses `md5` (fails: every
	 * migration "changed after it was applied").
	 *
	 * @since 0.1.0
	 */
	public function test_a_clean_site_passes(): void {
		$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor() );
		$this->assertSame(
			array( 'schema', 'migrations', 'locks', 'outbox', 'runner' ),
			array_map( static fn( string $line ): string => (string) preg_replace( '/^\[ok\]\s+(\w+):.*$/', '$1', $line ), array_slice( $this->printed, 0, 5 ) )
		);
		$this->assertSame( 'All 5 checks passed.', end( $this->printed ) );
	}

	/**
	 * Tests that each planted fault makes doctor exit 1 and name it, and that removing it makes doctor exit 0 again.
	 *
	 * Planted violations in the checks, each confirmed red then removed: SchemaCheck ignores the
	 * undeclared-table sweep; SchemaCheck prints the verifier's line as it is (the planted
	 * default, an email address, is printed); LocksCheck compares `expires_at > UTC_TIMESTAMP(6)`;
	 * OutboxCheck ignores failed rows; MigrationsCheck skips the checksum comparison; RunnerCheck
	 * ignores runnerStale() (the runner that has not checked in passes).
	 *
	 * @since 0.1.0
	 */
	public function test_each_planted_fault_fails_and_its_removal_passes(): void {
		global $wpdb;

		$logs      = $this->db->table( LogsTable::NAME );
		$outbox    = $this->db->table( OutboxTable::NAME );
		$locks     = $this->db->table( 'locks' );
		$records   = $this->db->table( 'migrations' );
		$planted   = $this->db->table( 'planted' );
		$checksum  = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT checksum FROM %i WHERE migration_id = %s', $records, CreateLogsMigration::ID ) );
		$recreate  = function (): void {
			( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
		};
		$checkIn   = static function (): void {
			PluginActions::purge();
			PluginActions::checkIn();
		};
		$statement = static function ( string $sql, string ...$tables ) use ( $wpdb ): \Closure {
			return static function () use ( $wpdb, $sql, $tables ): void {
				$wpdb->query( $wpdb->prepare( $sql, ...$tables ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is one of the literal statements of this test, with placeholders.
			};
		};

		$faults = array(
			'a default holding a value'        => array(
				$statement( "ALTER TABLE %i ALTER COLUMN message SET DEFAULT 'jane.doe@example.com'", $logs ),
				$statement( 'ALTER TABLE %i ALTER COLUMN message DROP DEFAULT', $logs ),
				$logs . '.message: its default differs from the declaration',
			),
			'a dropped index'                  => array(
				$statement( 'ALTER TABLE %i DROP INDEX correlation_id', $logs ),
				$statement( 'ALTER TABLE %i ADD INDEX correlation_id ( correlation_id )', $logs ),
				'index correlation_id does not exist',
			),
			'a dropped table'                  => array(
				$statement( 'DROP TABLE %i', $logs ),
				$recreate,
				$logs . ': the table does not exist',
			),
			'an undeclared table'              => array(
				$statement( 'CREATE TABLE %i ( id int NOT NULL ) ENGINE=InnoDB', $planted ),
				$statement( 'DROP TABLE %i', $planted ),
				$planted . ': a plugin table that no module declares',
			),
			'a stale lock lease'               => array(
				$statement( "INSERT INTO %i ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( 'planted', REPEAT( 'a', 64 ), UTC_TIMESTAMP(6) - INTERVAL 2 HOUR, UTC_TIMESTAMP(6) - INTERVAL 1 HOUR, 'cli:4242', UTC_TIMESTAMP() )", $locks ),
				$statement( "DELETE FROM %i WHERE name = 'planted'", $locks ),
				'Lock planted: its lease lapsed',
			),
			'a failed outbox row'              => array(
				$this->plantOutboxRow( 'failed', 'UTC_TIMESTAMP(6)' ),
				$statement( 'DELETE FROM %i', $outbox ),
				'1 stored event failed delivery',
			),
			'a stalled outbox'                 => array(
				$this->plantOutboxRow( 'pending', 'UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE', 'UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE' ),
				$statement( 'DELETE FROM %i', $outbox ),
				'delivery has stalled',
			),
			'a changed migration file'         => array(
				$statement( "UPDATE %i SET checksum = REPEAT( '0', 64 ) WHERE migration_id = %s", $records, CreateLogsMigration::ID ),
				$statement( 'UPDATE %i SET checksum = %s WHERE migration_id = %s', $records, $checksum, CreateLogsMigration::ID ),
				'Migration ' . CreateLogsMigration::ID . ' changed after it was applied',
			),
			'a failed migration'               => array(
				$statement( "UPDATE %i SET state = 'failed' WHERE migration_id = %s", $records, CreateLogsMigration::ID ),
				$statement( "UPDATE %i SET state = 'applied' WHERE migration_id = %s", $records, CreateLogsMigration::ID ),
				'Migration ' . CreateLogsMigration::ID . ' failed.',
			),
			'a runner that has not checked in' => array(
				static function (): void {
					PluginActions::purge();
					PluginActions::checkIn( 2 * HOUR_IN_SECONDS );
				},
				$checkIn,
				'No runner has started one of SEOCart\'s jobs for 72',
			),
			'a failed job'                     => array(
				static fn() => PluginActions::failed( LogRetentionJob::name(), 'jane.doe@example.com 4111111111111111 planted-secret-value' ),
				$checkIn,
				'handler logs.prune: 1 failed',
			),
		);

		foreach ( $faults as $fault => list( $plant, $remove, $finding ) ) {
			$plant();

			$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor(), "Doctor passed with {$fault}." );
			$this->assertStringContainsString( $finding, implode( "\n", $this->printed ), "Doctor did not name {$fault}." );
			$this->assertStringNotContainsString( 'jane.doe', implode( "\n", $this->printed ), "Doctor printed a stored value for {$fault}." );

			$remove();

			$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor(), "Doctor still failed once {$fault} was removed:\n" . implode( "\n", $this->printed ) );
		}
	}

	/**
	 * Tests that a check that cannot run fails with its error code, and the other checks still run.
	 *
	 * Planted violation: in Doctor::run(), let a check's exception through (the test errors).
	 *
	 * @since 0.1.0
	 */
	public function test_a_check_that_cannot_run_fails_and_the_others_still_run(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->db->table( OutboxTable::NAME ) ) );

		$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor() );

		$output = implode( "\n", $this->printed );

		$this->assertStringContainsString( '[FAIL] outbox: The check could not run: database.query_failed: The database refused a statement with error 1146 (SQLSTATE 42S02).', $output );
		$this->assertStringContainsString( '[ok]   locks:', $output );
		$this->assertStringContainsString( '[ok]   migrations:', $output );
		$this->assertStringContainsString( '[ok]   runner:', $output );
		$this->assertStringContainsString( '2 of 5 checks failed.', $output );
	}

	/**
	 * Tests that an old event whose retry is not due yet, or became due only lately, is not a stalled delivery, and one due too long is.
	 *
	 * Both rows were stored two hours ago: one is scheduled for a retry ten minutes from now,
	 * the other became due five minutes ago. Neither has waited for a drainer longer than the
	 * limit. A third, due for twenty minutes, has.
	 *
	 * Planted violation: in OutboxCheck::run(), measure the age from `oldestPendingSeconds`, the
	 * oldest row's storage time (the first two rows are reported as stalled).
	 *
	 * @since 0.1.0
	 */
	public function test_a_pending_event_is_stalled_only_once_it_has_been_due_too_long(): void {
		global $wpdb;

		$this->plantOutboxRow( 'pending', 'UTC_TIMESTAMP(6) - INTERVAL 2 HOUR', 'UTC_TIMESTAMP(6) + INTERVAL 10 MINUTE' )();
		$this->plantOutboxRow( 'pending', 'UTC_TIMESTAMP(6) - INTERVAL 2 HOUR', 'UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE' )();

		$report = ( new Outbox( $this->db ) )->report();

		$this->assertSame( 2, $report->pending );
		$this->assertGreaterThan( OutboxCheck::STALLED_SECONDS, $report->oldestPendingSeconds, 'Both rows are older than the limit.' );
		$this->assertEqualsWithDelta( 300, $report->oldestDueSeconds, 60, 'The age counts from when the row became due.' );
		$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor(), "A row not yet due, or due for a short while, was called stalled:\n" . implode( "\n", $this->printed ) );
		$this->assertStringContainsString( '[ok]   outbox: 2 pending', implode( "\n", $this->printed ) );

		$this->plantOutboxRow( 'pending', 'UTC_TIMESTAMP(6) - INTERVAL 30 MINUTE', 'UTC_TIMESTAMP(6) - INTERVAL 20 MINUTE' )();

		$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor() );
		$this->assertStringContainsString( 'delivery has stalled', implode( "\n", $this->printed ) );

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->db->table( OutboxTable::NAME ) ) );
	}

	/**
	 * Tests that with every fault planted and private values stored everywhere doctor reads, nothing private is printed.
	 *
	 * Planted violations: in LocksCheck, print the holder as stored; in ResidueCheck, print an
	 * option's name without CheckResult::identifier() (each printed the planted value).
	 *
	 * @since 0.1.0
	 */
	public function test_the_output_holds_no_secret_and_no_personal_data(): void {
		global $wpdb;

		$outbox = $this->db->table( OutboxTable::NAME );

		$this->plantOutboxRow( 'failed', 'UTC_TIMESTAMP(6)' )();
		PluginActions::failed( LogRetentionJob::name(), 'jane.doe@example.com 4111111111111111 planted-secret-value' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( 'planted', REPEAT( 'a', 64 ), UTC_TIMESTAMP(6) - INTERVAL 2 HOUR, UTC_TIMESTAMP(6) - INTERVAL 1 HOUR, 'jane.doe@example.com', UTC_TIMESTAMP() )", $this->db->table( 'locks' ) ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i ( name, owner_token, acquired_at, expires_at, holder, created_at ) VALUES ( 'jane.doe@example.com', REPEAT( 'b', 64 ), UTC_TIMESTAMP(6) - INTERVAL 2 HOUR, UTC_TIMESTAMP(6) - INTERVAL 1 HOUR, 'cli:1', UTC_TIMESTAMP() )", $this->db->table( 'locks' ) ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed', error_message = 'jane.doe@example.com 4111111111111111 planted-secret-value' WHERE migration_id = %s", $this->db->table( 'migrations' ), CreateLogsMigration::ID ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i ( level, channel, machine_code, message, context_json, correlation_id, created_at ) VALUES ( 'error', 'test', 'test.private', 'Planted.', '{\"email\":\"jane.doe@example.com\"}', '00000000-0000-7000-8000-000000000001', UTC_TIMESTAMP(6) )", $this->db->table( LogsTable::NAME ) ) );
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ( id int NOT NULL ) ENGINE=InnoDB', $this->db->table( 'planted@private' ) ) );
		add_option( 'seocart_planted_secret_value', '4111111111111111 planted-secret-value', '', false );
		add_option( 'seocart_jane.doe@example.com', 'planted-secret-value', '', false );
		$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ALTER COLUMN message SET DEFAULT 'jane.doe@example.com planted-secret-value'", $this->db->table( LogsTable::NAME ) ) );

		try {
			$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor() );

			$printed = $this->printed;

			$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor( true ) );

			$printed = array_merge( $printed, $this->printed );
		} finally {
			delete_option( 'seocart_planted_secret_value' );
			delete_option( 'seocart_jane.doe@example.com' );
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->db->table( 'planted@private' ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $outbox ) );
		}

		$output = implode( "\n", $printed );

		$this->assertStringContainsString( 'Lock planted: its lease lapsed', $output, 'The faults were reported.' );
		$this->assertStringContainsString( 'handler logs.prune: 1 failed', $output );
		$this->assertStringContainsString( 'job group seocart (declared): 1 complete, 1 failed', $output );
		$this->assertStringContainsString( 'option seocart_planted_secret_value (undeclared)', $output, 'A name that is an identifier is printed.' );
		$this->assertStringContainsString( '(a name that is not an identifier, withheld)', $output );

		foreach ( self::PRIVATE_VALUES as $value ) {
			$this->assertStringNotContainsString( $value, $output );
		}

		$this->assertStringNotContainsString( '4111111111111111', (string) preg_replace( '/\D/', '', $output ) );
	}

	/**
	 * Tests `--residue`: everything the plugin owns is residue, nothing left passes, and each kind of leftover is found.
	 *
	 * Planted violations: in ResidueCheck::options(), look for `seocart_` names only (the planted
	 * transient is missed); in ResidueCheck::run(), leave out jobs() (the planted job is missed).
	 *
	 * @since 0.1.0
	 */
	public function test_the_residue_check_finds_what_remains(): void {
		global $wpdb;

		$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor( true ), 'An installed store is all residue.' );
		$this->assertContains( '         - table ' . $this->db->table( LogsTable::NAME ) . ' (declared)', $this->printed );

		foreach ( self::pluginTablesNow() as $table ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $table ) );
		}

		PluginActions::purge();

		$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor( true ), "Nothing is left:\n" . implode( "\n", $this->printed ) );
		$this->assertStringContainsString( 'Nothing the plugin owned remains on this site', $this->printed[0] );

		$leftovers = array(
			'an option'           => array(
				static fn() => add_option( 'seocart_residue_planted', '1', '', false ),
				static fn() => delete_option( 'seocart_residue_planted' ),
				'option seocart_residue_planted (undeclared)',
			),
			'a transient'         => array(
				static fn() => set_transient( 'seocart_residue_planted', '1', 3600 ),
				static fn() => delete_transient( 'seocart_residue_planted' ),
				'option _transient_seocart_residue_planted (undeclared)',
			),
			'a shipped role'      => array(
				static fn() => add_role( 'seocart_reporter', 'Reporter', array() ),
				static fn() => remove_role( 'seocart_reporter' ),
				'role seocart_reporter (declared)',
			),
			'a plugin capability' => array(
				static fn() => get_role( 'administrator' )->add_cap( 'seocart_view_reports' ),
				static fn() => get_role( 'administrator' )->remove_cap( 'seocart_view_reports' ),
				'role administrator still has 1 plugin capability',
			),
			'a background job'    => array(
				static fn() => PluginActions::checkIn(),
				static fn() => PluginActions::purge(),
				'job group seocart (declared): 1 complete',
			),
			'a scheduled event'   => array(
				static fn() => wp_schedule_single_event( time() + 3600, 'seocart_residue_planted' ),
				static fn() => wp_clear_scheduled_hook( 'seocart_residue_planted' ),
				'scheduled event seocart_residue_planted',
			),
		);

		foreach ( $leftovers as $leftover => list( $plant, $remove, $finding ) ) {
			$plant();

			try {
				$this->assertSame( DoctorCommand::EXIT_FAILED, $this->doctor( true ), "The residue check missed {$leftover}." );
				$this->assertContains( '         - ' . $finding, $this->printed );
			} finally {
				$remove();
			}

			$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor( true ), "Residue remained once {$leftover} was removed." );
		}
	}

	/**
	 * Tests `--repair` with `--residue`: a usage error, nothing run.
	 *
	 * @since 0.1.0
	 */
	public function test_repair_with_residue_is_a_usage_error(): void {
		$this->assertSame( DoctorCommand::EXIT_USAGE, $this->doctor( true, true ) );
		$this->assertStringContainsString( '--repair and --residue', implode( "\n", $this->printed ) );
	}

	/**
	 * Tests `--repair` on a clean site: two passes, nothing to repair, still exits 0.
	 *
	 * None of the platform's own checks are Repairable, so this proves --repair's sequencing
	 * (first pass, a repair section, second pass) without changing behaviour for a check that has
	 * nothing to fix: output and the exit code are exactly what a plain `doctor` run gives, plus
	 * the two extra sections.
	 *
	 * @since 0.1.0
	 */
	public function test_repair_on_a_clean_site_runs_both_passes_and_reports_nothing_to_repair(): void {
		$this->assertSame( DoctorCommand::EXIT_OK, $this->doctor( false, true ) );

		$output = implode( "\n", $this->printed );

		$this->assertStringContainsString( 'First pass:', $output );
		$this->assertStringContainsString( 'Nothing to repair.', $output );
		$this->assertStringContainsString( 'Second pass:', $output );
		$this->assertSame( 1, substr_count( $output, 'All 5 checks passed.' ), 'The verdict is the second, final pass\'s alone; the first pass is diagnostic only.' );
	}

	/**
	 * Runs doctor and records what it printed.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $residue Optional. Whether to pass `--residue`. Default false.
	 * @param bool $repair  Optional. Whether to pass `--repair`. Default false.
	 * @return int The exit code.
	 */
	private function doctor( bool $residue = false, bool $repair = false ): int {
		$this->printed = array();

		$command = new DoctorCommand(
			new Doctor( $this->db, $this->registry, $this->migrator, new Outbox( $this->db ), new ActionSchedulerQueue( $this->db, new LockService( $this->db, LockMode::Table, $this->sleeper() ), new JobHandlers( JobHandlers::PRODUCTION, 'strval' ), new CorrelationId( new SequentialIdGenerator() ), $this->reporter() ) ),
			function ( string $line ): void {
				$this->printed[] = $line;
			}
		);

		$options = array();

		if ( $residue ) {
			$options['residue'] = true;
		}

		if ( $repair ) {
			$options['repair'] = true;
		}

		return $command->run( array(), $options );
	}

	/**
	 * Returns a plant of one outbox row whose failure text and payload hold private values.
	 *
	 * @since 0.1.0
	 *
	 * @param string $state       The row's state.
	 * @param string $createdAt   A SQL expression for `created_at`.
	 * @param string $availableAt Optional. A SQL expression for `available_at`. Default now.
	 * @return \Closure(): void The plant.
	 */
	private function plantOutboxRow( string $state, string $createdAt, string $availableAt = 'UTC_TIMESTAMP(6)' ): \Closure {
		return function () use ( $state, $createdAt, $availableAt ): void {
			global $wpdb;

			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i ( event_name, aggregate_type, aggregate_id, payload_json, correlation_id, state, available_at, attempts, last_error, created_at ) VALUES ( 'planted', 'thing', 1, '{\"email\":\"jane.doe@example.com\"}', '00000000-0000-7000-8000-000000000001', %s, {$availableAt}, 5, 'jane.doe@example.com 4111111111111111 planted-secret-value', {$createdAt} )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed SQL expressions from this test.
					$this->db->table( OutboxTable::NAME ),
					$state
				)
			);
		};
	}

	/**
	 * Lists the plugin tables that exist now.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> Full table names.
	 */
	private static function pluginTablesNow(): array {
		global $wpdb;

		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'seocart_' ) . '%' ) ) );
	}
}
