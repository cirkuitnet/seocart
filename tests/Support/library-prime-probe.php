<?php
/**
 * Finishes the bundled Action Scheduler's one-time data-store setup, as its first queue run does on a real site
 *
 * Usage: php tests/Support/library-prime-probe.php <result-file>
 *
 * Action Scheduler keeps its actions in its own tables, and on a site it has not seen before it
 * first checks, in an action it schedules a minute after it is loaded, whether an old release
 * left actions in the posts table to move over. Until that action has run, every request reads
 * the setup's state and loads the classes that move actions: the integration suite reinstalls
 * WordPress for every run and never runs the queue, so without this step an idle request
 * would be measured in that transient state. On a real site WP-Cron runs the action on the
 * library's first queue run after that minute.
 *
 * The probe boots WordPress with the plugin and, if the setup is not finished, runs the pending
 * setup action through the library's own runner, exactly as WP-Cron would. It writes whether
 * the setup was finished before and after.
 *
 * Started by a test, it inherits WP_TESTS_SKIP_INSTALL and boots against the installed site.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/library-prime-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DISABLE_WP_CRON is WordPress's own constant.
	define( 'DISABLE_WP_CRON', true );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=1' );

require dirname( __DIR__ ) . '/bootstrap-integration.php';

$seocart_prime_before = ActionScheduler_DataController::is_migration_complete();

if ( ! $seocart_prime_before ) {
	// The library schedules its setup action when it first loads; this request's own wp_loaded did so if no earlier one had.
	foreach ( as_get_scheduled_actions(
		array(
			'hook'   => 'action_scheduler/migration_hook',
			'status' => ActionScheduler_Store::STATUS_PENDING,
		),
		'ids'
	) as $seocart_prime_action ) {
		ActionScheduler::runner()->process_action( (int) $seocart_prime_action, 'WP Cron' );
	}

	wp_cache_flush();
}

file_put_contents(
	$argv[1],
	json_encode(
		array(
			'before' => $seocart_prime_before,
			'after'  => ActionScheduler_DataController::is_migration_complete(),
		),
		JSON_THROW_ON_ERROR
	)
);
