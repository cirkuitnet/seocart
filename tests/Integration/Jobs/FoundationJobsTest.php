<?php
/**
 * Tests the jobs the platform runs: the outbox catch-up, the two retention sweeps, and the migration attempt
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\Migration;
use SEOCart\Platform\Database\Migrations\PlatformBootstrapMigration;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\EventEnvelope;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Publisher;
use SEOCart\Platform\Jobs\Handlers\JobHistoryCleanup;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\Handlers\OutboxCatchUp;
use SEOCart\Platform\Jobs\Handlers\OutboxRetention;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\LogRetentionJob;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\Doubles\RecordingWake;
use SEOCart\Tests\Support\Events\ThingHappened;
use SEOCart\Tests\Support\Events\ThingNoticed;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Migrations\DeclaresMissingIndex;
use SEOCart\Tests\Support\Migrations\MarksRowsInBatches;
use SEOCart\Tests\Support\SecondDatabase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests age outbox rows and read the migrations table directly.

/**
 * The platform's jobs, each run through the real queue and runner.
 *
 * Each test names its planted violation, in the handler under src/Platform/Jobs/Handlers/.
 *
 * @since 0.1.0
 *
 * @group migration
 */
final class FoundationJobsTest extends JobsTestCase {

	/**
	 * Tests that the catch-up job delivers what is pending and never prunes.
	 *
	 * Planted violation: in OutboxCatchUp::handle(), drain with `prune: true`; the dispatched row
	 * past retention is deleted.
	 *
	 * @since 0.1.0
	 */
	public function test_the_catch_up_job_delivers_pending_events_and_never_prunes(): void {
		$this->publish( 1, 2 );
		$this->age( "state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 30 DAY", 1 );

		$delivered = array();

		add_action(
			EventEnvelope::hookFor( ThingHappened::eventName() ),
			static function ( ThingHappened $event ) use ( &$delivered ): void {
				$delivered[] = $event->aggregateId();
			}
		);

		$this->wirePlatform();
		$this->queue->enqueue( new Job( OutboxCatchUp::name() ) );
		$this->runner->runDue( 30, 'test' );

		$this->assertSame( array( 2 ), $delivered );
		$this->assertSame( array( 'dispatched', 'dispatched' ), $this->outboxStates(), 'The catch-up must not prune: the dispatched row past retention stays for the retention job.' );
	}

	/**
	 * Tests that the platform's recurring jobs are scheduled at their intervals: the catch-up every five minutes, the three sweeps daily.
	 *
	 * @since 0.1.0
	 */
	public function test_the_platforms_recurring_jobs_are_scheduled_at_their_intervals(): void {
		$this->wirePlatform();

		$this->assertSame( array( OutboxCatchUp::name(), OutboxRetention::name(), JobHistoryCleanup::name(), LogRetentionJob::name() ), $this->queue->ensureRecurring() );
		$this->assertSame(
			array(
				'[{"h":"outbox.catch_up","r":300}]',
				'[{"h":"outbox.prune","r":86400}]',
				'[{"h":"job_history.prune","r":86400}]',
				'[{"h":"logs.prune","r":86400}]',
			),
			array_column( $this->actions(), 'args' )
		);
	}

	/**
	 * Tests that the retention job prunes in batches, keeps what retention keeps, and stops at its budget.
	 *
	 * Planted violation: in OutboxRetention::handle(), prune once instead of looping; with a batch
	 * of 1 only one row of each state goes. A second: the `outbox` policy's dispatched period
	 * changed to P9D in the retention catalog; the rows dispatched 8 days ago stay, because the
	 * outbox reads its periods from there.
	 *
	 * @since 0.1.0
	 */
	public function test_the_retention_job_prunes_in_batches_until_nothing_is_past_retention(): void {
		$this->publish( 1, 2, 3, 4, 5, 6, 7 );
		$this->age( "state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 8 DAY", 1, 2, 3 );
		$this->age( "state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 1 DAY", 4 );
		$this->age( "state = 'failed', available_at = UTC_TIMESTAMP(6) - INTERVAL 91 DAY", 5, 6 );
		$this->age( 'available_at = UTC_TIMESTAMP(6) - INTERVAL 100 DAY', 7 );

		( new OutboxRetention( $this->outbox, 1 ) )->handle( array() );

		$this->assertSame( array( 4, 7 ), $this->outboxIds(), 'Every row past retention is gone, a batch of one at a time; the recent dispatched row and the pending one stay.' );

		$this->publish( 8, 9 );
		$this->age( "state = 'dispatched', dispatched_at = UTC_TIMESTAMP(6) - INTERVAL 8 DAY", 8, 9 );

		$calls = 0;

		// The deadline reads 0; the check after the first batch is past the budget.
		( new OutboxRetention(
			$this->outbox,
			1,
			static function () use ( &$calls ): int {
				return ++$calls <= 1 ? 0 : PHP_INT_MAX;
			}
		) )->handle( array() );

		$this->assertSame( array( 4, 7, 9 ), $this->outboxIds(), 'The budget stops the sweep after one batch; the rest is the next day\'s.' );
	}

	/**
	 * Tests that the migration job works a slice at a time, continuing itself until the migrations are applied.
	 *
	 * Planted violation: in MigrationAttempt::handle(), return null for `incomplete`; the job stops
	 * after the first batch and the data migration stays running.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_job_continues_itself_until_nothing_is_pending(): void {
		for ( $id = 0; $id < 6; $id++ ) {
			$this->insertRow( $id );
		}

		$marks = new MarksRowsInBatches( '20990101_0001_marks', self::ROWS, 6, 2 );

		$this->wirePlatform( array( new PlatformBootstrapMigration(), $marks ) );

		$this->assertTrue( $this->queue->enqueue( MigrationAttempt::job( '20990101_0001_marks' ) ) );
		$this->assertFalse( $this->queue->enqueue( MigrationAttempt::job( '20990101_0001_marks' ) ), 'A second request that sees the same gap adds nothing.' );

		$this->runner->runDue( 60, 'test' );

		global $wpdb;

		$this->assertSame( 3, $marks->batches, 'One batch per run: the budget is spent after each.' );
		$this->assertSame( 'applied', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT state FROM %i WHERE migration_id = %s', $this->db->table( 'migrations' ), '20990101_0001_marks' ) ) );
		$this->assertSame( '1,1,1,1,1,1', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT( n ORDER BY id ) FROM %i', $this->rowsTable() ) ) );
		$this->assertSame( array( 'complete', 'complete', 'complete' ), array_column( $this->actions(), 'status' ), 'Each slice continued the job, and the last one stopped.' );
		$this->assertFalse( $this->queue->enqueue( MigrationAttempt::job( '20990101_0001_marks' ) ), 'The job for this target completed: it is not queued again.' );
		$this->assertTrue( $this->queue->enqueue( MigrationAttempt::job( '20990102_0001_next' ) ), 'A new target is a new job.' );
	}

	/**
	 * Tests that the migration job waits and runs again when another runner holds the schema lock.
	 *
	 * @since 0.1.0
	 */
	public function test_the_migration_job_runs_again_later_when_another_runner_holds_the_schema_lock(): void {
		$this->wirePlatform( array( new PlatformBootstrapMigration() ) );

		$second = new SecondDatabase( $this->reporter() );
		$lease  = ( new LockService( $second->db(), LockMode::Table, $this->sleeper() ) )->acquire( Migrator::SCHEMA_LOCK, 600, 0 );

		$this->queue->enqueue( MigrationAttempt::job( 'blocked' ) );
		$this->runner->runDue( 30, 'test' );

		$lease->release();
		$second->close();

		$actions = $this->actions();

		$this->assertSame( array( 'complete', 'pending' ), array_column( $actions, 'status' ) );
		$this->assertEqualsWithDelta( MigrationAttempt::BLOCKED_DELAY_SECONDS, $actions[1]['due_in'], 5 );
	}

	/**
	 * Tests that a failing migration is retried by the job's backoff, and lands in failed.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failing_migration_is_retried_then_recorded_as_failed(): void {
		$this->wirePlatform( array( new PlatformBootstrapMigration(), new DeclaresMissingIndex( '20990101_0002_broken', 'broken' ) ) );

		$this->queue->enqueue( MigrationAttempt::job( 'broken' ) );

		for ( $attempt = 1; $attempt <= MigrationAttempt::MAX_ATTEMPTS; $attempt++ ) {
			$this->runner->runDue( 30, 'test' );

			$waiting = array_values( array_filter( $this->actions(), static fn( array $action ): bool => 'pending' === $action['status'] ) );

			if ( $attempt < MigrationAttempt::MAX_ATTEMPTS ) {
				$this->assertCount( 1, $waiting, "Attempt {$attempt} failed and is retried." );
				$this->assertSame( $attempt + 1, self::envelopeOf( $waiting[0] )->attempt );
				$this->makeDue( $waiting[0]['id'] );
			}
		}

		$this->assertSame( array( 'complete', 'complete', 'failed' ), array_column( $this->actions(), 'status' ) );
		$this->assertSame( 1, $this->queue->report()->failed );
	}

	/**
	 * Tests that the job-history job deletes the plugin's finished jobs past retention.
	 *
	 * @since 0.1.0
	 */
	public function test_the_history_job_cleans_up_the_plugins_finished_jobs(): void {
		$this->wirePlatform();

		$this->queue->enqueue( MigrationAttempt::job( 'old' ) );
		$this->setStatus( $this->actions()[0]['id'], 'complete', 30 );

		( new JobHistoryCleanup( $this->queue ) )->handle( array() );

		$this->assertSame( array(), $this->actions() );
	}

	/**
	 * Wires the queue with the platform's handlers, over a migrator with the given chain.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the resolver is asked for a class that is not a platform handler.
	 *
	 * @param Migration[] $migrations Optional. The migrator's chain. Default none.
	 */
	private function wirePlatform( array $migrations = array() ): void {
		$migrator = new Migrator( $this->db, $this->locks(), new MigrationsTableState( $this->db ), $migrations, FrozenClock::at( '2026-09-23 12:00:00' ), $this->reporter() );

		$this->wire(
			JobHandlers::PRODUCTION,
			fn( string $handlerClass ): JobHandler => match ( $handlerClass ) {
				OutboxCatchUp::class     => new OutboxCatchUp( $this->drainer() ),
				OutboxRetention::class   => new OutboxRetention( $this->outbox ),
				MigrationAttempt::class  => new MigrationAttempt( $migrator, 0 ),
				JobHistoryCleanup::class => new JobHistoryCleanup( $this->queue ),
				default                  => throw new \LogicException( $handlerClass . ' is not a platform handler.' ),
			}
		);
	}

	/**
	 * Publishes one ThingHappened per id, in one committed unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @param int ...$ids The aggregate ids, which are also the outbox ids on a fresh table.
	 */
	private function publish( int ...$ids ): void {
		$publisher = new Publisher( $this->db, $this->outbox, new HookBridge( $this->reporter() ), new EventCatalog( array( ThingHappened::class, ThingNoticed::class ) ), $this->correlation, new RecordingWake() );

		$this->db->transaction(
			static function () use ( $publisher, $ids ): void {
				foreach ( $ids as $id ) {
					$publisher->publish( new ThingHappened( $id ) );
				}
			}
		);
	}

	/**
	 * Changes outbox rows by aggregate id.
	 *
	 * @since 0.1.0
	 *
	 * @param string $set    The SET clause.
	 * @param int    ...$ids The aggregate ids.
	 */
	private function age( string $set, int ...$ids ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "UPDATE %i SET {$set} WHERE aggregate_id IN ( " . implode( ',', array_map( 'intval', $ids ) ) . ' )', $this->db->table( 'outbox' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- a fixed SET clause and integers.
	}

	/**
	 * Lists the outbox's aggregate ids, in order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<int> The ids.
	 */
	private function outboxIds(): array {
		global $wpdb;

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT aggregate_id FROM %i ORDER BY aggregate_id', $this->db->table( 'outbox' ) ) ) );
	}

	/**
	 * Lists the outbox's states, in aggregate order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The states.
	 */
	private function outboxStates(): array {
		global $wpdb;

		return array_map( 'strval', $wpdb->get_col( $wpdb->prepare( 'SELECT state FROM %i ORDER BY aggregate_id', $this->db->table( 'outbox' ) ) ) );
	}
}
