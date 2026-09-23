<?php
/**
 * JobHistoryCleanup: the daily job that deletes the plugin's own finished jobs past retention
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs\Handlers;

use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobQueue;

defined( 'ABSPATH' ) || exit;

/**
 * Cleans up the plugin's job history once a day.
 *
 * Owns one fact: how often, and how much of, the plugin's own job history is deleted. The
 * queue's retention is shared by every plugin that uses it, and its queue-wide setting is
 * never changed; instead this job deletes the plugin's own finished jobs past the job
 * history's retention periods (JobQueue::cleanup()), up to BATCH of each kind per run.
 * Another plugin's jobs are never touched.
 *
 * @since 0.1.0
 */
final class JobHistoryCleanup implements JobHandler {

	/**
	 * How many finished jobs, and how many failed ones, one run deletes at most.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const BATCH = 5000;

	/**
	 * The queue.
	 *
	 * @since 0.1.0
	 *
	 * @var JobQueue
	 */
	private JobQueue $queue;

	/**
	 * Creates the handler.
	 *
	 * @since 0.1.0
	 *
	 * @param JobQueue $queue The queue.
	 */
	public function __construct( JobQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Returns the handler's name.
	 *
	 * @since 0.1.0
	 *
	 * @return string `job_history.prune`.
	 */
	public static function name(): string {
		return 'job_history.prune';
	}

	/**
	 * Returns how often the handler runs.
	 *
	 * @since 0.1.0
	 *
	 * @return int Once a day.
	 */
	public static function recurrence(): int {
		return 86400;
	}

	/**
	 * Returns the attempts per run.
	 *
	 * @since 0.1.0
	 *
	 * @return int 1: the next day's run is the retry.
	 */
	public static function maxAttempts(): int {
		return 1;
	}

	/**
	 * Deletes one batch of the plugin's finished jobs past retention.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload Unused.
	 * @return int|null Null: the next run follows on its schedule.
	 */
	public function handle( array $payload ): ?int {
		$this->queue->cleanup( self::BATCH );

		return null;
	}
}
