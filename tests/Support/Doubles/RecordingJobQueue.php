<?php
/**
 * RecordingJobQueue: a job queue that records what is queued and keeps the unique-key rule
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Doubles;

use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobsReport;

/**
 * A JobQueue that keeps the jobs queued in a list, refuses to queue inside a transaction as the real one does, and adds nothing for a unique key already waiting.
 *
 * Owns one fact: what a service queued, and when. A test marks a keyed job finished with
 * finish(), as a run that failed or was cancelled would leave it, so a later queueing of the same
 * key adds it again. Nothing runs: the test runs the handler itself.
 *
 * @since 0.2.0
 */
final class RecordingJobQueue implements JobQueue {

	/**
	 * The unit of work whose depth enqueue() checks.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Every job queued, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<Job>
	 */
	public array $queued = array();

	/**
	 * The keys of the jobs waiting, by handler and key.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, true>
	 */
	private array $waiting = array();

	/**
	 * Creates the queue.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The unit of work the services run in.
	 */
	public function __construct( TransactionManager $tx ) {
		$this->tx = $tx;
	}

	/**
	 * Marks every keyed job as no longer waiting, as a failed or cancelled run leaves it.
	 *
	 * @since 0.2.0
	 */
	public function finish(): void {
		$this->waiting = array();
	}

	/**
	 * Queues a job, unless its key waits already.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction.
	 *
	 * @param Job $job The job.
	 * @return bool True when it was queued.
	 */
	public function enqueue( Job $job ): bool {
		if ( 0 !== $this->tx->depth() ) {
			throw new \LogicException( 'Nothing is queued inside a transaction.' );
		}

		$key = $job->handler . '|' . (string) $job->uniqueKey;

		if ( null !== $job->uniqueKey && isset( $this->waiting[ $key ] ) ) {
			return false;
		}

		if ( null !== $job->uniqueKey ) {
			$this->waiting[ $key ] = true;
		}

		$this->queued[] = $job;

		return true;
	}

	/**
	 * Queues a job for later, as enqueue() does.
	 *
	 * @since 0.2.0
	 *
	 * @param Job                $job   The job.
	 * @param \DateTimeImmutable $runAt Unused.
	 * @return bool As enqueue().
	 */
	public function schedule( Job $job, \DateTimeImmutable $runAt ): bool {
		return $this->enqueue( $job );
	}

	/**
	 * Cancels nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param string|null $handler   Unused.
	 * @param string|null $uniqueKey Unused.
	 * @return int 0.
	 */
	public function cancel( ?string $handler = null, ?string $uniqueKey = null ): int {
		return 0;
	}

	/**
	 * Cleans nothing up.
	 *
	 * @since 0.2.0
	 *
	 * @param int $limit Unused.
	 * @return int 0.
	 */
	public function cleanup( int $limit ): int {
		return 0;
	}

	/**
	 * Schedules nothing.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> None.
	 */
	public function ensureRecurring(): array {
		return array();
	}

	/**
	 * Reports an empty queue.
	 *
	 * @since 0.2.0
	 *
	 * @return JobsReport The report.
	 */
	public function report(): JobsReport {
		return new JobsReport( 0, count( $this->waiting ), 0, 0, null, null, array(), '', '', array() );
	}
}
