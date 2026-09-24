<?php
/**
 * PluginActions: plants, reads and clears the plugin's own background jobs in Action Scheduler's tables
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

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests plant, read and remove Action Scheduler's rows directly, as a runner or a failure would have left them.

/**
 * The plugin's jobs in Action Scheduler's tables, as the tests plant, read and clear them.
 *
 * Owns one fact for the tests: what the plugin's jobs look like in Action Scheduler's tables.
 * A planted job is stored through the library's API, in the plugin's group, with the arguments
 * the queue writes, and then given its status and its last attempt, as a runner would have left
 * it. Action Scheduler writes with autocommit through the shared connection, so a test that
 * plants, queues or schedules a job, an installation included, calls purge() in its tear-down.
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
	 * A test that also stores another plugin's actions names that plugin's hook and group too.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $hooks  Optional. The hooks. Default the runner's hook.
	 * @param string[] $groups Optional. The groups. Default the plugin's group.
	 */
	public static function purge( array $hooks = array( JobRunner::HOOK ), array $groups = array( JobQueue::GROUP ) ): void {
		global $wpdb;

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$ids     = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT a.action_id FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE a.hook IN ( ' . implode( ', ', array_fill( 0, count( $hooks ), '%s' ) ) . ' ) OR g.slug IN ( ' . implode( ', ', array_fill( 0, count( $groups ), '%s' ) ) . ' )', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one placeholder per hook and per group.
				array_merge( array( $actions, $wpdb->prefix . 'actionscheduler_groups' ), $hooks, $groups )
			)
		);

		foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
			$list = implode( ',', $chunk );

			$wpdb->query( "DELETE FROM {$actions} WHERE action_id IN ( {$list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ( {$list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
		}
	}

	/**
	 * Returns the plugin's waiting jobs: each one's stored arguments.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The arguments of every pending action of the runner's hook and the plugin's group, in id order.
	 */
	public static function pending(): array {
		global $wpdb;

		return array_values(
			array_map(
				'strval',
				$wpdb->get_col(
					$wpdb->prepare(
						'SELECT a.args FROM %i a JOIN %i g ON g.group_id = a.group_id WHERE a.hook = %s AND g.slug = %s AND a.status = %s ORDER BY a.action_id',
						$wpdb->prefix . 'actionscheduler_actions',
						$wpdb->prefix . 'actionscheduler_groups',
						JobRunner::HOOK,
						JobQueue::GROUP,
						'pending'
					)
				)
			)
		);
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
