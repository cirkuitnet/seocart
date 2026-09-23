<?php
/**
 * Builds and reads the data registry in a process without WordPress, and reports the answers
 *
 * Usage: php tests/Support/data-registry-probe.php <result-file>
 *
 * OwnedDataTest starts this script through ChildProcessProbe. It loads the unit bootstrap,
 * which defines the placeholder ABSPATH every file under src/ checks for and then loads
 * Composer's autoloader, and nothing else: no WordPress, and none of the stand-ins for
 * WordPress functions that Brain Monkey leaves behind in a PHPUnit process that has run other
 * tests. DataRegistrySnapshot then builds the registry and reads every accessor of it and of
 * what it returns, so a WordPress call made while the registry is built or read is a fatal
 * error here, and the test shows it. The report also lists every file the process loaded, so
 * the test can see that nothing but the plugin's classes, Composer's autoloader and the
 * snapshot were read.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Support\DataRegistrySnapshot;

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/data-registry-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

require dirname( __DIR__ ) . '/bootstrap-unit.php';

// A sample of what would be defined if WordPress, or a stand-in for it, were loaded.
$seocart_probe_wordpress_functions = array_values(
	array_filter(
		array( 'add_action', 'add_filter', 'apply_filters', 'do_action', '__', '_x', 'esc_html', 'get_option', 'add_option', 'update_option', 'wp_json_encode', 'add_role', 'get_role', 'absint', 'is_multisite' ),
		'function_exists'
	)
);

$seocart_probe_snapshot = DataRegistrySnapshot::take();

file_put_contents(
	$argv[1],
	json_encode(
		array(
			'wordpress_loaded'    => defined( 'WPINC' ) || class_exists( 'WP_Roles', false ) || class_exists( 'wpdb', false ),
			'wordpress_functions' => $seocart_probe_wordpress_functions,
			'snapshot'            => $seocart_probe_snapshot,
			'files'               => get_included_files(),
		),
		JSON_THROW_ON_ERROR
	)
);
