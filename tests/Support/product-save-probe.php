<?php
/**
 * Saves a product's title through SaveProduct in a process of its own, and reports how the save ended
 *
 * Usage: php tests/Support/product-save-probe.php <result-file> <post-id> <title> <saves|fails|loses|crashes> <go|pause>
 *
 * The product-write concurrency and recovery tests start this script through
 * ChildProcessProbe::start(), so that a save runs on a connection of its own while the test
 * holds locks, or so that a save can die the way a request does. It boots WordPress against the
 * installed test site without loading the plugin, registers the product post type, and builds
 * the service with ProductWrites over a Database whose guards report rather than throw, as in
 * production.
 *
 * - `saves`: the save runs to its end.
 * - `fails`: a `save_post_seocart_product` listener throws, as another plugin's might: the
 *   save is refused and its window rolls back.
 * - `loses`: a `save_post_seocart_product` listener sends COMMIT and then throws, as another
 *   plugin might: the window's transaction is lost.
 * - `crashes`: a `save_post_seocart_product` listener writes the report and ends the process,
 *   as a fatal error or a killed request would: nothing after it runs, no rollback handler and
 *   no restore; the server rolls the open transaction back when the connection closes.
 *
 * With `pause`, the save stops just before the first statement of its window, after its mark
 * has committed, in ProductWrites::BARRIER_WAIT, until the test lets the barrier's lock go.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Catalog\Application\ProductWrite\ProductSave;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Platform\Database\Database;
use SEOCart\Tests\Support\Catalog\ProductWrites;

// phpcs:disable WordPress.WP.AlternativeFunctions -- The probe writes its report to the file its parent reads.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2], $argv[3], $argv[4], $argv[5] ) || ! in_array( $argv[4], array( 'saves', 'fails', 'loses', 'crashes' ), true ) || ! in_array( $argv[5], array( 'go', 'pause' ), true ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/product-save-probe.php <result-file> <post-id> <title> <saves|fails|loses|crashes> <go|pause>' . PHP_EOL );

	exit( 2 );
}

putenv( 'SEOCART_TESTS_LOAD_PLUGIN=0' );

/*
 * The WordPress test bootstrap deletes every post when it loads, and the post this probe saves
 * is the calling test's. Deletions are refused until WordPress has loaded. Hooks added before
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
$seocart_probe_write   = static function ( array $outcome ) use ( $argv, &$seocart_probe_reports ): void {
	file_put_contents( $argv[1], (string) wp_json_encode( $outcome + array( 'reports' => $seocart_probe_reports ) ) );
};

$seocart_probe_service = ProductWrites::service( new Database( $wpdb, false, $seocart_probe_report ), $seocart_probe_report );

if ( 'pause' === $argv[5] ) {
	$seocart_probe_savepoints = 0;
	$seocart_probe_armed      = false;

	// The window's probe savepoint is the second `SAVEPOINT sc_0`, after the mark's; the statement after it is the window's first.
	add_filter(
		'query',
		static function ( string $query ) use ( &$seocart_probe_savepoints, &$seocart_probe_armed, $wpdb ): string {
			if ( $seocart_probe_armed ) {
				$seocart_probe_armed = false;

				$wpdb->get_var( ProductWrites::BARRIER_WAIT ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- A constant user-lock statement.
				$wpdb->get_var( ProductWrites::BARRIER_RELEASE ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- A constant user-lock statement.
			} elseif ( 'SAVEPOINT sc_0' === $query && 2 === ++$seocart_probe_savepoints ) {
				$seocart_probe_armed = true;
			}

			return $query;
		}
	);
}

if ( 'fails' === $argv[4] ) {
	add_action(
		'save_post_' . ProductCapabilities::POST_TYPE,
		static function (): void {
			throw new RuntimeException( 'A listener failed.' );
		}
	);
}

if ( 'loses' === $argv[4] ) {
	add_action(
		'save_post_' . ProductCapabilities::POST_TYPE,
		static function () use ( $wpdb ): void {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The listener ends the transaction, as the test's plugin would.

			throw new RuntimeException( 'A listener failed after ending the transaction.' );
		}
	);
}

if ( 'crashes' === $argv[4] ) {
	add_action(
		'save_post_' . ProductCapabilities::POST_TYPE,
		static function () use ( $seocart_probe_write ): void {
			$seocart_probe_write( array( 'outcome' => 'crashed' ) );

			exit( 0 );
		}
	);
}

try {
	$seocart_probe_saved = $seocart_probe_service->save( new ProductSave( (int) $argv[2], array( 'post_title' => $argv[3] ), null, Actor::user( 1 ) ) );

	$seocart_probe_write(
		array(
			'outcome'    => 'saved',
			'generation' => $seocart_probe_saved->generation->value,
		)
	);
} catch ( Throwable $seocart_probe_failure ) {
	$seocart_probe_write(
		array(
			'outcome' => 'failed',
			'failure' => get_class( $seocart_probe_failure ) . ': ' . $seocart_probe_failure->getMessage(),
		)
	);
}
