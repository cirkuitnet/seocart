<?php
/**
 * Deletes a product post in a process of its own, through WordPress with the product lifecycle hooked, and reports how the delete ended
 *
 * Usage: php tests/Support/product-delete-probe.php <result-file> <post-id>
 *
 * The concurrency tests of a product's bindings start this script through
 * ChildProcessProbe::start(), so that a delete runs on a connection of its own while the test
 * holds the product's lock. It boots WordPress against the installed test site without loading
 * the plugin, registers the product post type, builds the catalog's services with ProductWrites
 * over a Database whose guards report rather than throw, as in production, hooks their lifecycle
 * where the kernel hooks its own, and deletes the post with wp_delete_post().
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\Catalog\ProductWrites;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2] ) || (int) $argv[2] < 1 ) {
	fwrite( STDERR, 'Usage: php tests/Support/product-delete-probe.php <result-file> <post-id>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the posts this probe works
 * on are the calling test's. Deletions are refused until WordPress has loaded. Hooks added before
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

if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) ) {
	ProductPostType::register();
}

$seocart_probe_reports = array();
$seocart_probe_report  = static function ( string $code, array $context ) use ( &$seocart_probe_reports ): void {
	$seocart_probe_reports[] = $code . ( array() === $context ? '' : ' ' . (string) wp_json_encode( $context ) );
};

ProductWrites::services( new Database( $wpdb, false, $seocart_probe_report ), $seocart_probe_report )->attach();

try {
	$seocart_probe_deleted = wp_delete_post( (int) $argv[2], true );
	$seocart_probe_outcome = array( 'outcome' => $seocart_probe_deleted instanceof WP_Post ? 'deleted' : 'refused' );
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_outcome = array(
		'outcome' => 'failed',
		'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage(),
	);
}

file_put_contents( $argv[1], (string) wp_json_encode( $seocart_probe_outcome + array( 'reports' => $seocart_probe_reports ) ) );
