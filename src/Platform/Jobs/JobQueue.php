<?php
/**
 * JobQueue: the port through which SEOCart queues background work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Queues jobs for a background runner, cancels and cleans up the plugin's own, and reports on them.
 *
 * Owns one fact: the contract of background work as the rest of the plugin sees it. A job is
 * a handler name and a small payload of ids (Job). It runs later, at least once, on whichever
 * runner takes it: WP-Cron, `wp seocart jobs run` or the admin tick. No correctness property
 * may depend on when, or whether, a job runs: jobs drain, sweep and catch up.
 *
 * - Nothing is queued inside a transaction. The queue's storage is not part of the caller's
 *   unit of work, so a job queued inside one would survive its rollback. A unit of work
 *   queues its jobs after commit, or from a listener of the event it stored.
 * - A job with a unique key is queued once: while the latest run of the job with the same
 *   handler and key waits, runs or has completed (and its record is kept), queueing it again
 *   changes nothing. A redelivered event whose listener queues `outbox:{id}:{handler}`
 *   therefore adds no second job. A job whose last attempt failed, or that was cancelled, does
 *   not block a new one.
 * - Everything the plugin queues belongs to one group, GROUP. Cancelling and cleaning up touch
 *   that group only, never another plugin's jobs, and no queue-wide setting is ever changed.
 *
 * ActionSchedulerQueue is the implementation. This interface calls no WordPress function.
 *
 * @since 0.1.0
 */
interface JobQueue {

	/**
	 * The group every SEOCart job belongs to. The data registry declares it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const GROUP = 'seocart';

	/**
	 * The retention policy of the plugin's job history, in the retention catalog.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RETENTION = 'job_history';

	/**
	 * Queues a job to run as soon as a runner takes it.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open, the handler is not registered, or the
	 *                         queue is used before it is ready.
	 *
	 * @param Job $job The job.
	 * @return bool True when the job was queued; false when a job with the same handler and unique
	 *              key is already waiting, running or completed, or another request was queueing
	 *              it at the same moment.
	 */
	public function enqueue( Job $job ): bool;

	/**
	 * Queues a job to run once a time has come.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open, the handler is not registered, or the
	 *                         queue is used before it is ready.
	 *
	 * @param Job                $job   The job.
	 * @param \DateTimeImmutable $runAt The earliest time it may run.
	 * @return bool As enqueue().
	 */
	public function schedule( Job $job, \DateTimeImmutable $runAt ): bool;

	/**
	 * Cancels the plugin's own waiting jobs: all of them, one handler's, or one keyed job.
	 *
	 * A job that is already running finishes. Recurring jobs are cancelled too; ensureRecurring()
	 * schedules them again.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a unique key is given without its handler.
	 *
	 * @param string|null $handler   Optional. Only this handler's jobs. Default null, every job.
	 * @param string|null $uniqueKey Optional. Only the job with this key. Default null.
	 * @return int How many jobs were cancelled.
	 */
	public function cancel( ?string $handler = null, ?string $uniqueKey = null ): int;

	/**
	 * Deletes the plugin's own finished jobs past retention, in one bounded batch.
	 *
	 * Completed and cancelled jobs, and failed ones, are kept for the periods of the RETENTION
	 * policy after they last ran, the failed ones longer, so a failure stays counted long enough
	 * to be seen. A job cancel() cancelled counts from its cancellation; one cancelled some
	 * other way that never ran counts from when it was due. Waiting and running jobs are never
	 * touched, and neither is any other group's job.
	 *
	 * @since 0.1.0
	 *
	 * @param int $limit At most this many jobs of each kind (finished, failed) are deleted.
	 * @return int How many jobs were deleted.
	 */
	public function cleanup( int $limit ): int;

	/**
	 * Schedules every recurring handler that has no run waiting.
	 *
	 * Safe to repeat: a handler already scheduled at its current interval is left alone, and one
	 * scheduled at an interval the code no longer declares is rescheduled.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When a transaction is open or the queue is used before it is ready.
	 *
	 * @return list<string> The handlers that were scheduled now.
	 */
	public function ensureRecurring(): array;

	/**
	 * Counts the plugin's jobs and describes the runner, for `wp seocart jobs status`, doctor and Site Health.
	 *
	 * @since 0.1.0
	 *
	 * @return JobsReport The counts and the runtime in control.
	 */
	public function report(): JobsReport;
}
