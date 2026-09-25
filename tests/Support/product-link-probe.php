<?php
/**
 * Links a post to a product in a process of its own, through TranslationBindings, and reports how the link ended and how often it was retried
 *
 * Usage: php tests/Support/product-link-probe.php <result-file> <product-id> <post-id> <locale>
 *
 * The concurrency tests of a translation group start this script through
 * ChildProcessProbe::start(), so that a link runs on a connection of its own while the test
 * reconciles the same products. It boots WordPress against the installed test site without
 * loading the plugin, builds the catalog's services with ProductWrites over a Database whose
 * guards report rather than throw, as in production, and whose pauses between attempts are
 * counted, not slept, and links the post with TranslationBindings::link().
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Database\Database;
use SEOCart\Support\Locale;
use SEOCart\Tests\Support\Catalog\ProductWrites;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3], $argv[4] ) || (int) $argv[2] < 1 || (int) $argv[3] < 1 ) {
	fwrite( STDERR, 'Usage: php tests/Support/product-link-probe.php <result-file> <product-id> <post-id> <locale>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the posts this probe links
 * are the calling test's. Deletions are refused until WordPress has loaded. Hooks added before
 * WordPress loads are kept in this array shape until plugin.php builds them.
 */
// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress is not loaded yet; this is how a hook is added before it is.
$GLOBALS['wp_filter']['pre_delete_post'][ PHP_INT_MIN ][] = array(
	'function'      => static fn(): bool => false,
	'accepted_args' => 1,
);

require dirname( __DIR__ ) . '/bootstrap-integration.php';

remove_all_filters( 'pre_delete_post', PHP_INT_MIN );

global $wpdb;

$seocart_probe_reports = array();
$seocart_probe_retries = 0;
$seocart_probe_report  = static function ( string $code, array $context ) use ( &$seocart_probe_reports ): void {
	$seocart_probe_reports[] = $code . ( array() === $context ? '' : ' ' . (string) wp_json_encode( $context ) );
};

// The unit of work pauses only before it runs its work again, after a deadlock or a lock wait that timed out.
$seocart_probe_sleep = static function () use ( &$seocart_probe_retries ): void {
	++$seocart_probe_retries;
};

try {
	ProductWrites::services( new Database( $wpdb, false, $seocart_probe_report, 5, $seocart_probe_sleep ), $seocart_probe_report )->bindings->link( (int) $argv[2], (int) $argv[3], Locale::of( $argv[4] ) );

	$seocart_probe_outcome = array( 'outcome' => 'linked' );
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array(
		'outcome' => 'failed',
		'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage(),
	);
}

file_put_contents(
	$argv[1],
	(string) wp_json_encode(
		$seocart_probe_outcome + array(
			'retries' => $seocart_probe_retries,
			'reports' => $seocart_probe_reports,
		)
	)
);
