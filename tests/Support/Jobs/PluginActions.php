<?php
/**
 * PluginActions: plants and clears the plugin's own background jobs in Action Scheduler's tables
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Jobs;

use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobEnvelope;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant and remove Action Scheduler's rows directly, as a runner or a failure would have left them.

/**
 * Stores jobs of the plugin's group as a runner would have left them, for the tests that read the jobs report without running jobs.
 *
 * Owns one fact for the tests: what a check-in and a failed job look like in Action Scheduler's
 * tables. A job is stored through the library's API, in the plugin's group, with the arguments
 * the queue writes, and then given its status and its last attempt. Action Scheduler writes
 * with autocommit, so a test that plants here calls purge() in its tear-down.
 *
 * @since 0.1.0
 */
final class PluginActions {

	/**
	 * Records that a runner started one of the plugin's jobs: a completed job, last attempted a while ago.
	 *
	 * @since 0.1.0
	 *
	 * @param int $secondsAgo Optional. How long ago the runner started it. Default 0.
	 * @return int The job's action id.
	 */
	public static function checkIn( int $secondsAgo = 0 ): int {
		return self::planted( 'test.check_in', 'complete', $secondsAgo );
	}

	/**
	 * Records a job that failed for the last time, with a last error that must never be shown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler    The handler whose job failed.
	 * @param string $lastError  Optional. The error the library logged for it. Default a fixed sentence.
	 * @return int The job's action id.
	 */
	public static function failed( string $handler, string $lastError = 'The handler failed.' ): int {
		$id = self::planted( $handler, 'failed', 60 );

		\ActionScheduler_Logger::instance()->log( (string) $id, $lastError );

		return $id;
	}

	/**
	 * Moves the last attempt of a planted job.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id         The action id.
	 * @param int $secondsAgo How long ago it was attempted.
	 */
	public static function attempted( int $id, int $secondsAgo ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL %d SECOND, last_attempt_local = UTC_TIMESTAMP() - INTERVAL %d SECOND WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $secondsAgo, $secondsAgo, $id ) );
	}

	/**
	 * Deletes every job of the plugin's group and of the runner's hook, with its log lines.
	 *
	 * @since 0.1.0
	 */
	public static function purge(): void {
		global $wpdb;

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$ids     = $wpdb->get_col( $wpdb->prepare( 'SELECT a.action_id FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.hook = %s OR g.slug = %s', $actions, $wpdb->prefix . 'actionscheduler_groups', JobRunner::HOOK, JobQueue::GROUP ) );

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$list = implode( ',', $chunk );

			$wpdb->query( "DELETE FROM {$actions} WHERE action_id IN ( {$list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ( {$list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		}
	}

	/**
	 * Stores a job of the plugin's group, then gives it a status and a last attempt.
	 *
	 * @since 0.1.0
	 *
	 * @param string $handler    The handler name.
	 * @param string $status     The status.
	 * @param int    $secondsAgo How long ago it was last attempted.
	 * @return int The action id.
	 */
	private static function planted( string $handler, string $status, int $secondsAgo ): int {
		global $wpdb;

		$id = (int) as_enqueue_async_action( JobRunner::HOOK, ( new JobEnvelope( new Job( $handler ) ) )->toArguments(), JobQueue::GROUP );

		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE action_id = %d', $wpdb->prefix . 'actionscheduler_actions', $status, $id ) );
		self::attempted( $id, $secondsAgo );

		return $id;
	}
}
