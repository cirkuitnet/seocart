<?php
/**
 * Boots WordPress with SEOCart as one kind of request, and reports the hooks and files the plugin's boot left
 *
 * Usage: php tests/Support/kernel-hooks-probe.php <result-file> <front|admin|cli|cron>
 *
 * KernelWiringTest starts this script as a child process, once per kind of request, because the
 * kernel decides which hooks to add from the kind of request it boots in: an admin request, a
 * WP-CLI run or a cron run. Each kind is set up the way WordPress itself marks it, before
 * WordPress loads: WP_ADMIN, WP_CLI or DOING_CRON.
 *
 * The report is taken at the end of `plugins_loaded`, right after the kernel booted. The plugin's
 * callbacks on `init`, `admin_init` and `cli_init` are then detached, because they reconcile the
 * site, and a cron boot fires `init`: a probe must not install the plugin into the test database.
 *
 * The script boots WordPress through the integration bootstrap, so it uses the same test database
 * and loads the plugin the same way. Started by a test, it inherits WP_TESTS_SKIP_INSTALL and
 * leaves the installed site alone.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\PluginOwnership;

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2] ) || ! in_array( $argv[2], array( 'front', 'admin', 'cli', 'cron' ), true ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/kernel-hooks-probe.php <result-file> <front|admin|cli|cron>' . PHP_EOL );

	exit( 2 );
}

$seocart_hooks_probe_result_file = $argv[1];

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constants, which mark the kind of request.
if ( 'admin' === $argv[2] ) {
	define( 'WP_ADMIN', true );
} elseif ( 'cli' === $argv[2] ) {
	define( 'WP_CLI', true );
} elseif ( 'cron' === $argv[2] ) {
	define( 'DOING_CRON', true );
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

/*
 * Registered before WordPress loads, the way the test library's tests_add_filter() does, so that it
 * runs last on `plugins_loaded`: after the kernel's boot, and before `init`.
 */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- a hook registered before WordPress loads, as the test library registers its own.
$GLOBALS['wp_filter']['plugins_loaded'][ PHP_INT_MAX ][] = array(
	'function'      => static function () use ( $seocart_hooks_probe_result_file ): void {
		$probes = new BootstrapProbes( PluginOwnership::fromComposerManifest( dirname( __DIR__, 2 ) ) );
		$hooks  = $probes->registeredPluginHooks();

		file_put_contents(
			$seocart_hooks_probe_result_file,
			json_encode(
				array(
					'is_admin'  => is_admin(),
					'multisite' => is_multisite(),
					'hooks'     => $hooks,
					'files'     => $probes->loadedPluginFiles(),
				),
				JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE
			)
		);

		global $wp_filter;

		foreach ( array( 'init', 'admin_init', 'cli_init' ) as $hook ) {
			foreach ( $wp_filter[ $hook ]->callbacks ?? array() as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					if ( PluginOwnership::fromComposerManifest( dirname( __DIR__, 2 ) )->ownsCallback( $callback['function'] ) ) {
						remove_action( $hook, $callback['function'], $priority );
					}
				}
			}
		}
	},
	'accepted_args' => 0,
);

require dirname( __DIR__ ) . '/bootstrap-integration.php';
