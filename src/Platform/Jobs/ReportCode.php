<?php
/**
 * ReportCode: the codes the Jobs module logs without throwing
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Machine codes that reach the reporter, the log and doctor, never a client.
 *
 * This enum owns one fact: the vocabulary of what background work reports. A job runs after
 * the request that queued it has answered, so none of these is a client's error: none is
 * thrown to a client, none has a row in the error table, and none may spell a code the error
 * table or another module's reporter uses; a test composes them and checks it.
 *
 * @since 0.1.0
 */
enum ReportCode: string {

	/**
	 * A queued job threw; it runs again after the retry delay.
	 *
	 * @since 0.1.0
	 */
	case RetryScheduled = 'jobs.retry_scheduled';

	/**
	 * A job threw on its last attempt, or a recurring run threw; it is recorded as failed and counted.
	 *
	 * @since 0.1.0
	 */
	case Failed = 'jobs.failed';

	/**
	 * A job came due while jobs are paused: a queued job was put back for later, a recurring run skipped.
	 *
	 * @since 0.1.0
	 */
	case Paused = 'jobs.paused';

	/**
	 * A stored job could not be read, for example because another plugin wrote to the group; it is recorded as failed.
	 *
	 * @since 0.1.0
	 */
	case Unreadable = 'jobs.unreadable';

	/**
	 * A stored job names a handler that is not registered, for example one a later release removed; it is recorded as failed.
	 *
	 * @since 0.1.0
	 */
	case UnknownHandler = 'jobs.unknown_handler';

	/**
	 * Another request held the lock of a keyed job for the whole wait, so this one did not queue it.
	 *
	 * @since 0.1.0
	 */
	case EnqueueContended = 'jobs.enqueue_contended';

	/**
	 * The opportunistic tick on an admin request failed as a whole.
	 *
	 * @since 0.1.0
	 */
	case TickFailed = 'jobs.tick_failed';

	/**
	 * The wake at the end of a request that published failed; the recurring catch-up job delivers its events.
	 *
	 * @since 0.1.0
	 */
	case WakeFailed = 'jobs.wake_failed';

	/**
	 * `wp seocart jobs run` ran no job: Action Scheduler keeps its actions in a store another plugin chose, and WP-Cron runs the plugin's jobs through it.
	 *
	 * @since 0.1.0
	 */
	case CustomStore = 'jobs.custom_store';

	/**
	 * The repair of recurring schedules on a WP-Cron hook failed; the next queue run tries again.
	 *
	 * @since 0.1.0
	 */
	case RepairFailed = 'jobs.repair_failed';
}
