<?php
/**
 * Tests the log's retention sweep as a recurring job: scheduled from the retention catalog, run by the real queue, batched and bounded
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Logging;

use SEOCart\Platform\Database\Schema\DdlGenerator;
use SEOCart\Platform\Database\Schema\SchemaVerifier;
use SEOCart\Platform\Database\SchemaOperations;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Logging\LogRetention;
use SEOCart\Platform\Logging\LogRetentionJob;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Tests\Support\Jobs\JobsTestCase;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant and age log lines directly.

/**
 * The `logs.prune` job: one of the plugin's handlers, recurring on a schedule read from the retention catalog, and a bounded batched sweep.
 *
 * The queue and the runner are the real ones over Action Scheduler, wired as the kernel wires
 * them, with this handler built over the test's connection.
 *
 * @since 0.1.0
 */
final class LogRetentionJobTest extends JobsTestCase {

	/**
	 * Creates the log table.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );
	}

	/**
	 * Tests that the handler is one of the plugin's, recurs once a day as the catalog's thirty days make it, and attempts each run once.
	 *
	 * Planted violation: the `logs` policy's period changed to PT6H in the retention catalog; the
	 * job then recurs every six hours, because it reads its schedule from there.
	 *
	 * @since 0.1.0
	 */
	public function test_it_is_a_production_handler_scheduled_from_the_retention_catalog(): void {
		$this->assertContains( LogRetentionJob::class, JobHandlers::PRODUCTION );
		$this->assertSame( DAY_IN_SECONDS, LogRetentionJob::recurrence(), 'Once a day, as the catalog\'s thirty days make it.' );
		$this->assertSame( array( 'logs.prune' => DAY_IN_SECONDS ), ( new JobHandlers( array( LogRetentionJob::class ), 'strval' ) )->recurring() );
		$this->assertSame( 1, LogRetentionJob::maxAttempts() );
		$this->assertSame( 'P30D', LogRetention::cataloguedPeriod() );
		$this->assertSame( 30 * DAY_IN_SECONDS, LogRetention::seconds( LogRetention::cataloguedPeriod() ) );
	}

	/**
	 * Tests that the queue schedules the sweep, and that a run through the library's own runner deletes every line past thirty days and keeps the rest.
	 *
	 * Planted violation: the `logs` policy's period changed to P40D in the retention catalog; the
	 * lines thirty-one days old then stay.
	 *
	 * @since 0.1.0
	 */
	public function test_the_queue_runs_the_sweep_on_its_schedule(): void {
		$this->wire( array( LogRetentionJob::class ), fn(): JobHandler => new LogRetentionJob( new LogRetention( $this->db ), 2 ) );

		$old    = $this->plantLines( 5, 31 );
		$recent = $this->plantLines( 2, 29 );

		$this->assertSame( array( LogRetentionJob::name() ), $this->queue->ensureRecurring() );

		$actions = $this->actions();

		$this->assertSame( array( '[{"h":"logs.prune","r":86400}]' ), array_column( $actions, 'args' ) );

		$this->makeDue( $actions[0]['id'] );
		$this->runLibraryQueue();

		$this->assertSame( $recent, $this->lineIds(), 'Every line past thirty days is gone, two at a time; the younger ones stay.' );
		$this->assertCount( 5, $old );
		$this->assertSame( array( 'complete', 'pending' ), array_column( $this->actions(), 'status' ), 'The run is done and the next one is scheduled.' );
	}

	/**
	 * Tests that one run sweeps batch after batch until one comes back short, and stops at its time budget.
	 *
	 * Planted violations: in LogRetentionJob::handle(), sweep once instead of looping (three of
	 * the five old lines stay); loop while anything was deleted instead of while a batch was full
	 * (a fourth statement is sent).
	 *
	 * @since 0.1.0
	 */
	public function test_a_run_sweeps_in_batches_until_a_short_one_and_stops_at_its_budget(): void {
		$this->plantLines( 5, 31 );

		$recent = $this->plantLines( 1, 1 );
		$log    = $this->captureQueries( fn() => ( new LogRetentionJob( new LogRetention( $this->db ), 2 ) )->handle( array() ) );

		$this->assertSame( $recent, $this->lineIds() );
		$this->assertCount( 3, $log->ofType( 'DELETE' ), 'Batches of two, two and one: the short one ends the run.' );

		$this->plantLines( 5, 31 );

		$calls = 0;

		// The deadline reads 0; the check after the first batch is past the budget.
		( new LogRetentionJob(
			new LogRetention( $this->db ),
			2,
			static function () use ( &$calls ): int {
				return ++$calls <= 1 ? 0 : PHP_INT_MAX;
			}
		) )->handle( array() );

		$this->assertCount( 4, $this->lineIds(), 'The budget stops the run after one batch of two; the rest is the next run\'s.' );
	}

	/**
	 * Inserts log lines of one age.
	 *
	 * @since 0.1.0
	 *
	 * @param int $count   How many.
	 * @param int $daysOld How old they are.
	 * @return list<int> Their ids.
	 */
	private function plantLines( int $count, int $daysOld ): array {
		global $wpdb;

		$ids = array();

		for ( $line = 0; $line < $count; $line++ ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i ( level, channel, machine_code, message, correlation_id, created_at ) VALUES ( 'info', 'test', 'test.planted', 'Planted.', '00000000-0000-7000-8000-000000000001', UTC_TIMESTAMP(6) - INTERVAL %d DAY )", $this->db->table( LogsTable::NAME ), $daysOld ) );

			$ids[] = (int) $wpdb->insert_id;
		}

		return $ids;
	}

	/**
	 * Lists the ids of the log lines, ascending.
	 *
	 * @since 0.1.0
	 *
	 * @return list<int> The ids.
	 */
	private function lineIds(): array {
		return array_map( 'intval', array_column( $this->db->fetchAll( 'SELECT id FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'id' ) );
	}
}
