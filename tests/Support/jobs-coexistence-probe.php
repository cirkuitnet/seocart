<?php
/**
 * Boots WordPress with SEOCart and a competing copy of Action Scheduler, runs a job, and reports which copy ran it
 *
 * Usage: php tests/Support/jobs-coexistence-probe.php <result-file> <copy-directory> <before|after>
 *
 * CoexistenceTest starts this script through ChildProcessProbe, once per competing copy and
 * load order. Another plugin bundling Action Scheduler loads its copy when WordPress includes
 * that plugin, before or after SEOCart; the probe includes the copy's `action-scheduler.php`
 * at `muplugins_loaded`, where the integration bootstrap includes SEOCart, at a priority just
 * before or just after it. Then WordPress finishes loading, and the copy with the highest
 * version, whichever loaded first, is initialised.
 *
 * The probe reports what SEOCart's queue says is in control, what the version negotiation
 * registered, and which copy's classes are loaded. It then queues one keyed job and lets the
 * library's own queue runner (the one WP-Cron fires) run it, recording which copy's classes the
 * job ran under. Finally it stores another plugin's actions, a waiting one and an old completed
 * one, cancels and cleans up SEOCart's own jobs, and reports whether the other plugin's
 * actions survived. Everything it stored is deleted before it writes its report.
 *
 * Started by a test, it inherits WP_TESTS_SKIP_INSTALL and boots against the installed site.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Jobs\RecordingJob;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The probe ages and reads Action Scheduler's rows directly, to see what the queue did.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3] ) || ! in_array( $argv[3], array( 'before', 'after' ), true ) || ! is_file( $argv[2] . '/action-scheduler.php' ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/jobs-coexistence-probe.php <result-file> <copy-directory> <before|after>' . PHP_EOL );

	exit( 2 );
}

$seocart_probe_copy = $argv[2] . '/action-scheduler.php';

/*
 * The integration bootstrap includes SEOCart at `muplugins_loaded` priority 10. Hooks added
 * before WordPress loads are kept in this array shape until plugin.php builds them.
 */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded yet; this is how a hook is added before it is.
$GLOBALS['wp_filter']['muplugins_loaded'][ 'before' === $argv[3] ? 5 : 15 ][] = array(
	'function'      => static function () use ( $seocart_probe_copy ): void {
		require_once $seocart_probe_copy;
	},
	'accepted_args' => 1,
);

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=1' );

require dirname( __DIR__ ) . '/bootstrap-integration.php';

global $wpdb;

$seocart_probe_reports = array();
$seocart_probe_report  = static function ( string $code, array $context ) use ( &$seocart_probe_reports ): void {
	$seocart_probe_reports[] = $code . ( array() === $context ? '' : ' ' . (string) wp_json_encode( $context ) );
};

$seocart_probe_db          = new Database( $wpdb, false, $seocart_probe_report );
$seocart_probe_locks       = new LockService( $seocart_probe_db, LockMode::GetLock );
$seocart_probe_correlation = new CorrelationId( new SequentialIdGenerator() );
$seocart_probe_handlers    = new JobHandlers( array( RecordingJob::class ), static fn(): RecordingJob => new RecordingJob( $seocart_probe_correlation ) );
$seocart_probe_queue       = new ActionSchedulerQueue( $seocart_probe_db, $seocart_probe_locks, $seocart_probe_handlers, $seocart_probe_correlation, $seocart_probe_report );
$seocart_probe_runner      = new JobRunner( $seocart_probe_handlers, $seocart_probe_queue, $seocart_probe_correlation, new SequentialIdGenerator( 1000 ), $seocart_probe_report );

add_action( JobRunner::HOOK, array( $seocart_probe_runner, 'run' ) );

$seocart_probe_before  = $seocart_probe_queue->report();
$seocart_probe_sources = ActionScheduler_Versions::instance()->get_sources();

// A keyed job, queued twice: the second is a redelivery and must add nothing.
$seocart_probe_queued = array(
	$seocart_probe_queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 1 ), 'coexistence:1' ) ),
	$seocart_probe_queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 1 ), 'coexistence:1' ) ),
);

// What WP-Cron does: the library's own runner, the controlling copy's, runs every due action.
do_action( 'action_scheduler_run_queue', 'WP Cron' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Action Scheduler's WP-Cron hook, fired as WP-Cron fires it.

$seocart_probe_ours = static function () use ( $wpdb ): array {
	return $wpdb->get_results(
		$wpdb->prepare(
			'SELECT a.action_id, a.status FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s ORDER BY a.action_id',
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'actionscheduler_groups',
			JobQueue::GROUP
		),
		ARRAY_A
	);
};

$seocart_probe_after_run = $seocart_probe_ours();

// Another plugin's actions: one waiting, one completed a month ago. SEOCart then cancels and cleans up its own.
$seocart_probe_other_waiting  = (int) as_schedule_single_action( time() + HOUR_IN_SECONDS, 'another_plugin_task', array( 1 ), 'another-plugin' );
$seocart_probe_other_finished = (int) as_enqueue_async_action( 'another_plugin_task', array( 2 ), 'another-plugin' );
$seocart_probe_own_waiting    = $seocart_probe_queue->schedule( new Job( RecordingJob::NAME ), new DateTimeImmutable( '+1 hour' ) );

$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'complete', last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE action_id = %d", $wpdb->prefix . 'actionscheduler_actions', $seocart_probe_other_finished ) );
$wpdb->query( $wpdb->prepare( "UPDATE %i a INNER JOIN %i g ON g.group_id = a.group_id SET a.last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE g.slug = %s AND a.status = 'complete'", $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_groups', JobQueue::GROUP ) );

$seocart_probe_cancelled = $seocart_probe_queue->cancel();
$seocart_probe_cleaned   = $seocart_probe_queue->cleanup( 100 );

$seocart_probe_other = $wpdb->get_results(
	$wpdb->prepare( 'SELECT action_id, status FROM %i WHERE action_id IN ( %d, %d ) ORDER BY action_id', $wpdb->prefix . 'actionscheduler_actions', $seocart_probe_other_waiting, $seocart_probe_other_finished ),
	ARRAY_A
);

$seocart_probe_result = array(
	'report'           => array(
		'version'    => $seocart_probe_before->runtimeVersion,
		'source'     => $seocart_probe_before->runtimeSource,
		'registered' => $seocart_probe_before->registeredVersions,
		'supported'  => $seocart_probe_before->runtimeSupported,
	),
	'negotiated'       => (string) ActionScheduler_Versions::instance()->latest_version(),
	'sources'          => $seocart_probe_sources,
	'library'          => (string) ( new ReflectionMethod( 'ActionScheduler', 'store' ) )->getFileName(),
	'queued'           => $seocart_probe_queued,
	'runs'             => RecordingJob::$runs,
	'ours_after_run'   => $seocart_probe_after_run,
	'own_waiting'      => $seocart_probe_own_waiting,
	'cancelled'        => $seocart_probe_cancelled,
	'cleaned'          => $seocart_probe_cleaned,
	'ours_left'        => $seocart_probe_ours(),
	'other_after'      => $seocart_probe_other,
	'retention_filter' => has_filter( 'action_scheduler_retention_period' ) || has_filter( 'action_scheduler_retention_period_for_failed' ),
	'reports'          => $seocart_probe_reports,
);

// Leave the site as it was.
$seocart_probe_ids = array_merge( array_column( $seocart_probe_ours(), 'action_id' ), array( $seocart_probe_other_waiting, $seocart_probe_other_finished ) );

$seocart_probe_list = implode( ',', array_map( 'intval', $seocart_probe_ids ) );

$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ( {$seocart_probe_list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ( {$seocart_probe_list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.

file_put_contents( $argv[1], json_encode( $seocart_probe_result, JSON_THROW_ON_ERROR ) );
