<?php
/**
 * JobHandler: the code that runs one kind of job
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the jobs queued under one name.
 *
 * A handler declares itself through static methods, so the registry reads its name, its
 * interval and its attempts without constructing it: a handler is built only when one of its
 * jobs runs. handle() runs outside any transaction, under the correlation id of the request
 * that queued the job (a recurring run gets a fresh one).
 *
 * A job runs at least once: after a crash or a retry it may run again, so handle() must be
 * idempotent, keyed on the ids in its payload. A handler that throws is run again after the
 * retry delay until its attempts are spent; its job then lands in `failed` and is counted.
 * A recurring handler is never retried: its next run is the retry.
 *
 * @since 0.1.0
 */
interface JobHandler {

	/**
	 * Returns the handler's name, under which its jobs are queued.
	 *
	 * @since 0.1.0
	 *
	 * @return string Two or more lowercase words joined by dots, for example `outbox.catch_up` (Job::HANDLER_PATTERN).
	 */
	public static function name(): string;

	/**
	 * Returns how often the handler runs on its own, or null for a handler that runs when a job is queued.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null Seconds between runs, at least JobEnvelope::MIN_INTERVAL_SECONDS, or null.
	 */
	public static function recurrence(): ?int;

	/**
	 * Returns how many times a queued job of this handler is attempted before it fails.
	 *
	 * @since 0.1.0
	 *
	 * @return int From 1 to JobHandlers::MAX_ATTEMPTS. A recurring handler is attempted once per run whatever it returns.
	 */
	public static function maxAttempts(): int;

	/**
	 * Runs one job.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, int|string|bool|null> $payload The job's payload.
	 * @return int|null Null when the job is done; otherwise the delay in seconds after which the
	 *                  same job runs again, for work that stopped at its time budget. A recurring
	 *                  handler's return value is ignored.
	 */
	public function handle( array $payload ): ?int;
}
