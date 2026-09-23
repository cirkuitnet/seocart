<?php
/**
 * Exercises SEOCart's jobs on a real site, next to whatever other copy of Action Scheduler it runs, and reports what happened
 *
 * Usage, against a disposable site (bin/dev/provision-site.sh), with the plugin active:
 *
 *     wp --path=<site> eval-file <checkout>/tests/Support/jobs-site-probe.php
 *
 * The coexistence runs use it on a site with WooCommerce active and on a site with a competing
 * copy of Action Scheduler installed as a plugin, loaded before or after SEOCart. The kernel does
 * not wire the jobs module yet, so the probe wires it the way the kernel will: the queue, the
 * runner on its hook, the triggers and the command, with the plugin's own handlers plus the
 * RecordingJob fixture. Then it:
 *
 * 1. reports the copy in control, its version and where it lives, and every registered version;
 * 2. queues the migration job and lets the library's own runner (the one WP-Cron fires) run it,
 *    which applies the plugin's migrations on this site;
 * 3. queues one keyed job twice (a redelivery) and lets the library's runner run it, recording
 *    which copy's classes it ran under;
 * 4. queues another job and runs `wp seocart jobs run`, then `wp seocart jobs status`;
 * 5. stores another plugin's waiting and old completed actions, cancels and cleans up SEOCart's
 *    own jobs, and compares every other group's actions before and after;
 * 6. checks that the queue-wide retention filters are as they were before it started.
 *
 * It prints one JSON document. Everything it stores in the queue is deleted before it ends; the
 * tables the migrations created stay, as they would on a real site.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\LockMode;
use SEOCart\Platform\Database\LockService;
use SEOCart\Platform\Database\MigrationsTableState;
use SEOCart\Platform\Database\Migrator;
use SEOCart\Platform\DataRegistry\OwnedData;
use SEOCart\Platform\Events\EventCatalog;
use SEOCart\Platform\Events\HookBridge;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxDrainer;
use SEOCart\Platform\Jobs\ActionSchedulerQueue;
use SEOCart\Platform\Jobs\Cli\JobsCommand;
use SEOCart\Platform\Jobs\Handlers\MigrationAttempt;
use SEOCart\Platform\Jobs\Job;
use SEOCart\Platform\Jobs\JobHandler;
use SEOCart\Platform\Jobs\JobHandlers;
use SEOCart\Platform\Jobs\JobQueue;
use SEOCart\Platform\Jobs\JobRunner;
use SEOCart\Platform\Jobs\RunnerTriggers;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\SystemClock;
use SEOCart\Support\SystemIdGenerator;
use SEOCart\Tests\Support\Jobs\RecordingJob;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The probe reads and ages Action Scheduler's rows directly, to see what the queue did.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SEOCART_PLUGIN_FILE' ) || 0 === did_action( 'action_scheduler_init' ) ) {
	fwrite( STDERR, 'SEOCart and Action Scheduler must both be loaded; activate the plugin first.' . PHP_EOL );

	exit( 1 );
}

// The site's autoloader maps plugin classes only; the fixture handler lives with the tests.
require_once __DIR__ . '/Jobs/RecordingJob.php';

global $wpdb;

$seocart_site_filters        = static fn(): array => array(
	'action_scheduler_retention_period'            => has_filter( 'action_scheduler_retention_period' ),
	'action_scheduler_retention_period_for_failed' => has_filter( 'action_scheduler_retention_period_for_failed' ),
);
$seocart_site_filters_before = $seocart_site_filters();

$seocart_site_reports = array();
$seocart_site_report  = static function ( string $code, array $context ) use ( &$seocart_site_reports ): void {
	$seocart_site_reports[] = $code . ( array() === $context ? '' : ' ' . (string) wp_json_encode( $context ) );
};

$seocart_site_db          = new Database( $wpdb, false, $seocart_site_report );
$seocart_site_locks       = new LockService( $seocart_site_db, LockMode::GetLock );
$seocart_site_correlation = new CorrelationId( new SystemIdGenerator( new SystemClock() ) );
$seocart_site_migrator    = new Migrator( $seocart_site_db, $seocart_site_locks, new MigrationsTableState( $seocart_site_db ), OwnedData::registry()->migrations(), new SystemClock(), $seocart_site_report );
$seocart_site_outbox      = new Outbox( $seocart_site_db );
$seocart_site_drainer     = new OutboxDrainer( $seocart_site_db, $seocart_site_outbox, new HookBridge( $seocart_site_report ), new EventCatalog( array() ), $seocart_site_locks, $seocart_site_correlation, $seocart_site_report );
$seocart_site_handlers    = new JobHandlers(
	array( MigrationAttempt::class, RecordingJob::class ),
	static fn( string $handler_class ): JobHandler => MigrationAttempt::class === $handler_class ? new MigrationAttempt( $seocart_site_migrator ) : new RecordingJob( $seocart_site_correlation )
);
$seocart_site_queue       = new ActionSchedulerQueue( $seocart_site_db, $seocart_site_locks, $seocart_site_handlers, $seocart_site_correlation, $seocart_site_report );
$seocart_site_runner      = new JobRunner( $seocart_site_handlers, $seocart_site_queue, $seocart_site_correlation, new SystemIdGenerator( new SystemClock() ), $seocart_site_report );
$seocart_site_triggers    = new RunnerTriggers( $seocart_site_runner, $seocart_site_queue, $seocart_site_drainer, $seocart_site_locks, $seocart_site_report );
$seocart_site_lines       = array();
$seocart_site_command     = new JobsCommand(
	$seocart_site_triggers,
	$seocart_site_queue,
	static function ( string $line ) use ( &$seocart_site_lines ): void {
		$seocart_site_lines[] = $line;
	}
);

add_action( JobRunner::HOOK, array( $seocart_site_runner, 'run' ) );

// What WP-Cron does, as often as the library's runner finds something to run.
$seocart_site_wp_cron = static function (): int {
	$ran = 0;

	for ( $round = 0; $round < 20; $round++ ) {
		$count = ActionScheduler_QueueRunner::instance()->run( 'WP Cron' );
		$ran  += $count;

		if ( 0 === $count ) {
			break;
		}
	}

	return $ran;
};

// Every group's action count by status, the plugin's own left out.
$seocart_site_others = static function () use ( $wpdb ): array {
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT COALESCE( g.slug, \'\' ) AS grp, a.status, COUNT(*) AS total FROM %i a LEFT JOIN %i g ON g.group_id = a.group_id WHERE COALESCE( g.slug, \'\' ) <> %s GROUP BY grp, a.status ORDER BY grp, a.status',
			$wpdb->prefix . 'actionscheduler_actions',
			$wpdb->prefix . 'actionscheduler_groups',
			JobQueue::GROUP
		),
		ARRAY_A
	);

	$counts = array();

	foreach ( $rows as $row ) {
		$counts[ $row['grp'] . ' ' . $row['status'] ] = (int) $row['total'];
	}

	return $counts;
};

$seocart_site_runtime = $seocart_site_queue->report();
$seocart_site_result  = array(
	'runtime'     => array(
		'version'    => $seocart_site_runtime->runtimeVersion,
		'source'     => $seocart_site_runtime->runtimeSource,
		'registered' => $seocart_site_runtime->registeredVersions,
		'supported'  => $seocart_site_runtime->runtimeSupported,
		'negotiated' => (string) ActionScheduler_Versions::instance()->latest_version(),
		'sources'    => ActionScheduler_Versions::instance()->get_sources(),
		'store'      => get_class( ActionScheduler::store() ),
	),
	'plugins'     => array_values( (array) get_option( 'active_plugins', array() ) ),
	'woocommerce' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
);

// The migration job, run by the library's own runner.
$seocart_site_result['migration_queued'] = $seocart_site_queue->enqueue( MigrationAttempt::job( 'site-probe' ) );
$seocart_site_result['wp_cron_ran']      = $seocart_site_wp_cron();
$seocart_site_status                     = $seocart_site_migrator->status();
$seocart_site_result['migrations']       = array(
	'code_head'      => $seocart_site_status->codeHead(),
	'applied_head'   => $seocart_site_status->appliedHead(),
	'writes_blocked' => $seocart_site_status->writesBlocked(),
);

// A keyed job and its redelivery, on the WP-Cron path.
$seocart_site_result['keyed_queued']      = array(
	$seocart_site_queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 1 ), 'site:1:test.recording' ) ),
	$seocart_site_queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 1 ), 'site:1:test.recording' ) ),
);
$seocart_site_result['keyed_wp_cron_ran'] = $seocart_site_wp_cron();
$seocart_site_result['keyed_runs']        = RecordingJob::$runs;

// The run and status commands.
RecordingJob::reset();
$seocart_site_queue->enqueue( new Job( RecordingJob::NAME, array( 'order_id' => 2 ) ) );
$seocart_site_result['command_run_exit']  = $seocart_site_command->run( array( 'run' ), array( 'budget' => '30' ) );
$seocart_site_result['command_run_lines'] = $seocart_site_lines;
$seocart_site_result['command_run_jobs']  = RecordingJob::$runs;
$seocart_site_lines                       = array();
$seocart_site_result['status_exit']       = $seocart_site_command->run( array( 'status' ), array() );
$seocart_site_result['status_lines']      = $seocart_site_lines;

// Another plugin's actions, and every other group's, through SEOCart's cancel and cleanup.
$seocart_site_other_waiting  = (int) as_schedule_single_action( time() + HOUR_IN_SECONDS, 'another_plugin_task', array( 1 ), 'another-plugin' );
$seocart_site_other_finished = (int) as_enqueue_async_action( 'another_plugin_task', array( 2 ), 'another-plugin' );

$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'complete', last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE action_id = %d", $wpdb->prefix . 'actionscheduler_actions', $seocart_site_other_finished ) );
$wpdb->query( $wpdb->prepare( "UPDATE %i a INNER JOIN %i g ON g.group_id = a.group_id SET a.last_attempt_gmt = UTC_TIMESTAMP() - INTERVAL 30 DAY WHERE g.slug = %s AND a.status IN ( 'complete', 'failed' )", $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_groups', JobQueue::GROUP ) );

$seocart_site_queue->schedule( new Job( RecordingJob::NAME ), new DateTimeImmutable( '+1 hour' ) );

$seocart_site_others_before            = $seocart_site_others();
$seocart_site_result['cancelled']      = $seocart_site_queue->cancel();
$seocart_site_result['cleaned']        = $seocart_site_queue->cleanup( 1000 );
$seocart_site_others_after             = $seocart_site_others();
$seocart_site_result['others_before']  = $seocart_site_others_before;
$seocart_site_result['others_changed'] = $seocart_site_others_before !== $seocart_site_others_after;

// The queue-wide retention filters, unchanged.
$seocart_site_result['filters_before'] = $seocart_site_filters_before;
$seocart_site_result['filters_after']  = $seocart_site_filters();
$seocart_site_result['reports']        = $seocart_site_reports;

// Leave the queue as it was.
$seocart_site_ids  = array_merge(
	array_map(
		'intval',
		$wpdb->get_col( $wpdb->prepare( 'SELECT a.action_id FROM %i a INNER JOIN %i g ON g.group_id = a.group_id WHERE g.slug = %s', $wpdb->prefix . 'actionscheduler_actions', $wpdb->prefix . 'actionscheduler_groups', JobQueue::GROUP ) )
	),
	array( $seocart_site_other_waiting, $seocart_site_other_finished )
);
$seocart_site_list = implode( ',', $seocart_site_ids );

$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ( {$seocart_site_list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.
$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ( {$seocart_site_list} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers only.

echo wp_json_encode( $seocart_site_result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON for the terminal.
