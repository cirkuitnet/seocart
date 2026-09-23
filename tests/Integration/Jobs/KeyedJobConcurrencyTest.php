<?php
/**
 * Tests two requests queueing the same keyed job: the second waits for the first's lock on the key
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Jobs;

use SEOCart\Platform\Database\Lease;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobEnvelope;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\JobsTestCase;
use SEOCart\Tests\Support\Jobs\RecordingJob;
use SEOCart\Tests\Support\SecondDatabase;

/**
 * A keyed job is stored once when two requests queue it at the same moment.
 *
 * The other request is a second connection holding the key's lock, as a request does between
 * its lookup and its store. This request's enqueue() waits for the lock, polling through the
 * test's sleeper; the first poll is the barrier where the other request stores the job and
 * lets go. No test sleeps.
 *
 * Planted violation: in ActionSchedulerQueue::queue(), run the lookup and the store without
 * withLock(); the second request looks up before the first has stored, and stores the job
 * again.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class KeyedJobConcurrencyTest extends JobsTestCase {

	/**
	 * The other request's connection.
	 *
	 * @since 0.1.0
	 *
	 * @var SecondDatabase|null
	 */
	private ?SecondDatabase $other = null;

	/**
	 * Closes the other request's connection.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		if ( null !== $this->other ) {
			$this->other->close();

			$this->other = null;
		}

		parent::tear_down();
	}

	/**
	 * Tests that a request waits while another holds the key's lock, then finds the job the other stored and stores nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_request_waits_for_the_keys_lock_and_then_finds_the_job_the_other_request_stored(): void {
		$job   = new Job( RecordingJob::NAME, array( 'order_id' => 7 ), 'order:7:test.recording' );
		$lease = $this->otherRequestHoldsTheKey( $job );

		$this->onSleep = function () use ( $job, $lease ): void {
			$this->onSleep = null;

			// The other request stores the job while it holds the lock, then lets go.
			as_enqueue_async_action( JobRunner::HOOK, ( new JobEnvelope( $job, SequentialIdGenerator::nth( 99 ) ) )->toArguments(), JobQueue::GROUP );

			$lease->release();
		};

		$this->assertFalse( $this->queue->enqueue( $job ), 'The job is already queued by the other request.' );
		$this->assertNotSame( array(), $this->sleeps, 'This request waited for the lock.' );
		$this->assertCount( 1, $this->actions(), 'The job is stored once.' );
		$this->assertSame( SequentialIdGenerator::nth( 99 ), self::envelopeOf( $this->actions()[0] )->correlationId, 'The one stored is the other request\'s.' );
	}

	/**
	 * Tests that a request whose wait ends without the other storing the job stores it itself.
	 *
	 * @since 0.1.0
	 */
	public function test_a_request_stores_the_job_when_the_other_request_let_go_without_storing_it(): void {
		$job   = new Job( RecordingJob::NAME, array( 'order_id' => 8 ), 'order:8:test.recording' );
		$lease = $this->otherRequestHoldsTheKey( $job );

		$this->onSleep = function () use ( $lease ): void {
			$this->onSleep = null;

			$lease->release();
		};

		$this->assertTrue( $this->queue->enqueue( $job ) );
		$this->assertNotSame( array(), $this->sleeps );
		$this->assertCount( 1, $this->actions() );
	}

	/**
	 * Takes the lock on a keyed job over the other request's connection.
	 *
	 * @since 0.1.0
	 *
	 * @param Job $job The keyed job.
	 * @return Lease The other request's hold on the key.
	 */
	private function otherRequestHoldsTheKey( Job $job ): Lease {
		$this->other = new SecondDatabase( $this->reporter() );

		return ( new LockService( $this->other->db(), LockMode::Table ) )->acquire( ActionSchedulerQueue::keyLock( $job->handler, (string) $job->uniqueKey ), 30, 0 );
	}
}
