<?php
/**
 * Serves one idle front-end request in a process of its own, and reports what SEOCart did in it
 *
 * Usage: php tests/Support/idle-request-probe.php <result-file>
 *
 * IdleBudgetTest starts this script as a child process and asserts the idle-request budgets on
 * the JSON document it writes. A process of its own is the point: the list of included files
 * and the query log only ever grow, so inside the shared PHPUnit process they would also hold
 * whatever earlier tests loaded and queried. Here they hold one request, from the first line
 * of the WordPress boot to the last line of the page.
 *
 * The request is the front page of a site with no commerce on it: the WordPress boot with the
 * plugin loaded, the main query, `template_redirect`, `wp_head` and `wp_footer`. No theme
 * template is rendered, because a bundled theme is not what is being measured.
 *
 * The script boots WordPress through the integration bootstrap, so it uses the same test
 * database and loads the plugin the same way. Started by a test, it inherits
 * WP_TESTS_SKIP_INSTALL from the PHPUnit process and leaves the installed site alone.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\PluginOwnership;

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/idle-request-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

$seocart_probe_result_file = $argv[1];

require dirname( __DIR__ ) . '/bootstrap-integration.php';

// From here on this is wp-blog-header.php: run the main query, then what the template loader fires.
$_SERVER['REQUEST_URI'] = '/';

ob_start();

wp();

// Canonical redirection ends the process when it decides to redirect. It has nothing to measure here.
remove_action( 'template_redirect', 'redirect_canonical' );
do_action( 'template_redirect' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- a WordPress core hook, fired to simulate the request.

wp_head();
wp_footer();

ob_end_clean();

global $wpdb;

$seocart_probe_probes = new BootstrapProbes( PluginOwnership::fromComposerManifest( dirname( __DIR__, 2 ) ) );

$seocart_probe_queries = array();

foreach ( $wpdb->queries as $seocart_probe_query ) {
	// SQL, elapsed seconds, call-stack summary: the positions QueryLog::fromWpdb() reads.
	$seocart_probe_queries[] = array( $seocart_probe_query[0], $seocart_probe_query[1], $seocart_probe_query[2] );
}

file_put_contents(
	$seocart_probe_result_file,
	json_encode(
		array(
			'queries_run' => $wpdb->num_queries,
			'queries'     => $seocart_probe_queries,
			'files'       => $seocart_probe_probes->loadedPluginFiles(),
			'hooks'       => $seocart_probe_probes->registeredPluginHooks(),
		),
		JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
	)
);
