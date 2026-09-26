<?php
/**
 * Reports the client identities a WordPress process computes, with or without trusted proxies configured
 *
 * Usage: php tests/Support/client-identity-probe.php <result-file> <trusted-proxies|->
 *
 * ClientIdentityTest starts this script through ChildProcessProbe, because a constant, once
 * defined, cannot be taken back in the test's own process. With a second argument other than `-`,
 * the probe defines SEOCART_TRUSTED_PROXIES to it before WordPress loads, as wp-config.php would.
 * It boots WordPress against the installed test site without loading the plugin, and reports the
 * identity ClientIdentities::forRequest() gives a guest without a cart token for each set of
 * server variables in CASES, keyed by the case's name.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Tests\Support\ClientIdentityCases;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/client-identity-probe.php <result-file> <trusted-proxies|->' . PHP_EOL );

	exit( 2 );
}

if ( '-' !== $argv[2] ) {
	define( 'SEOCART_TRUSTED_PROXIES', $argv[2] );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the posts are the calling
 * test's. Deletions are refused until WordPress has loaded. Hooks added before WordPress loads are
 * kept in this array shape until plugin.php builds them.
 */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded yet; this is how a hook is added before it is.
$GLOBALS['wp_filter']['pre_delete_post'][ PHP_INT_MIN ][] = array(
	'function'      => static fn(): bool => false,
	'accepted_args' => 1,
);

require dirname( __DIR__ ) . '/bootstrap-integration.php';

remove_all_filters( 'pre_delete_post', PHP_INT_MIN );

$seocart_probe_identities = array();

foreach ( ClientIdentityCases::CASES as $seocart_probe_case => $seocart_probe_server ) {
	unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

	$_SERVER = $seocart_probe_server + $_SERVER;

	$seocart_probe_identities[ $seocart_probe_case ] = ClientIdentities::forRequest()->of( 0 )->key();
}

file_put_contents( $argv[1], (string) wp_json_encode( array( 'identities' => $seocart_probe_identities ) ) );
