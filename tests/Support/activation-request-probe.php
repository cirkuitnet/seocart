<?php
/**
 * Activates SEOCart the way WordPress does, in a request in which the plugin was never loaded
 *
 * Usage: php tests/Support/activation-request-probe.php <result-file>
 *
 * ActivationRequestTest starts this script as a child process. WordPress boots without the
 * plugin, so `plugins_loaded` fires without it; then, as activate_plugin() does, the plugin's
 * main file is included and its activation hook is fired. The kernel has not booted at that
 * point, and the activation must install the site regardless. The test reads the result from
 * the database; this script reports whether the kernel had booted.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Kernel\Kernel;

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/activation-request-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

$seocart_activation_probe_result_file = $argv[1];

if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DISABLE_WP_CRON is WordPress's own constant.
	define( 'DISABLE_WP_CRON', true );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

require dirname( __DIR__ ) . '/bootstrap-integration.php';

// What activate_plugin() does: include the main file after `plugins_loaded`, then fire its activation hook.
require_once dirname( __DIR__, 2 ) . '/seocart.php';

$seocart_activation_probe_booted = Kernel::hasBooted();

do_action( 'activate_' . plugin_basename( SEOCART_PLUGIN_FILE ), false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own activation hook, fired as activate_plugin() fires it.

file_put_contents(
	$seocart_activation_probe_result_file,
	json_encode(
		array(
			'booted_before_activation' => $seocart_activation_probe_booted,
			'booted_after_activation'  => Kernel::hasBooted(),
		),
		JSON_THROW_ON_ERROR
	)
);
