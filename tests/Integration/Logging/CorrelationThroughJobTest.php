<?php
/**
 * Tests that a line written inside a real job run carries the id of the request that queued the job
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
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Logging\FallbackLog;
use SEOCart\Platform\Logging\Level;
use SEOCart\Platform\Logging\Logger;
use SEOCart\Platform\Logging\LogsTable;
use SEOCart\Platform\Logging\Migrations\CreateLogsMigration;
use SEOCart\Platform\Logging\Redactor;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Logging\DeclaredFields;
use SEOCart\Tests\Support\Logging\LoggingJob;

/**
 * The correlation id travels request → queued job → the job's log line, through the real Action Scheduler.
 *
 * One request queues a job under its id; a later request, with an id of its own, runs the queue
 * the way WP-Cron does, through the library's own runner. The line the job writes carries the
 * queuing request's id; the running request's own lines keep theirs.
 *
 * Planted violation: in JobRunner, run the handler without CorrelationId::scoped() (the job's
 * line carries the running request's id).
 *
 * @since 0.1.0
 */
final class CorrelationThroughJobTest extends JobsTestCase {

	/**
	 * The id of the request that queues the job.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const QUEUING_REQUEST = '0192a3b4-0000-7000-8000-00000000dddd';

	/**
	 * The id of the request that runs it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const RUNNING_REQUEST = '0192a3b4-0000-7000-8000-00000000eeee';

	/**
	 * The lines the logger could not write.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $lost = array();

	/**
	 * Creates the log table.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		( new CreateLogsMigration() )->up( new SchemaOperations( $this->db, new DdlGenerator(), new SchemaVerifier( $this->db ) ) );

		$this->lost = array();
	}

	/**
	 * Tests that the job's line carries the queuing request's id, and the running request's line its own.
	 *
	 * @since 0.1.0
	 */
	public function test_a_line_written_in_a_job_carries_the_id_of_the_request_that_queued_it(): void {
		$logger = new Logger(
			$this->db,
			$this->correlation,
			Redactor::fromDeclarations( OwnedData::registry(), ...DeclaredFields::production() ),
			Level::Debug,
			null,
			new FallbackLog(
				function ( string $line ): void {
					$this->lost[] = $line;
				},
				static function (): void {}
			)
		);

		$this->wire( array( LoggingJob::class ), static fn(): JobHandler => new LoggingJob( $logger ) );

		$this->correlation->accept( self::QUEUING_REQUEST );
		$this->assertTrue( $this->queue->enqueue( new Job( LoggingJob::NAME ) ) );

		$this->correlation->accept( self::RUNNING_REQUEST );
		$this->runLibraryQueue();
		$logger->info( 'test.after_run', 'The running request logs after the run.' );

		$this->assertSame( array( 'complete' ), array_column( $this->actions(), 'status' ), 'The job ran.' );
		$this->assertSame(
			array(
				LoggingJob::CODE => self::QUEUING_REQUEST,
				'test.after_run' => self::RUNNING_REQUEST,
			),
			array_column( $this->db->fetchAll( 'SELECT machine_code, correlation_id FROM %i ORDER BY id', $this->db->table( LogsTable::NAME ) ), 'correlation_id', 'machine_code' )
		);
		$this->assertSame( array(), $this->lost );
	}
}
