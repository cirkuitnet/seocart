<?php
/**
 * Builds the settings declarations in a process without WordPress, and reports what they answer
 *
 * Usage: php tests/Support/settings-probe.php <result-file>
 *
 * SettingsAreDataTest starts this script through ChildProcessProbe. It loads the unit bootstrap,
 * which defines the placeholder ABSPATH that every file under src/ checks for and then loads
 * Composer's autoloader, and nothing else: no WordPress, and none of the function stand-ins that
 * Brain Monkey leaves behind in a PHPUnit process. A WordPress call made while the settings
 * registry or the settings operations are built or compiled is therefore a fatal error here, and
 * the test reports it.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Tests\Support\SettingsSnapshot;

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1] ) ) {
	fwrite( STDERR, 'Usage: php tests/Support/settings-probe.php <result-file>' . PHP_EOL );

	exit( 2 );
}

require dirname( __DIR__ ) . '/bootstrap-unit.php';

// A sample of what would be defined if WordPress, or a stand-in for it, were loaded.
$seocart_probe_wordpress_functions = array_values(
	array_filter(
		array( 'apply_filters', 'add_filter', 'do_action', 'get_option', 'update_option', 'wp_prime_option_caches', 'wp_cache_get', '__', 'wp_json_encode' ),
		'function_exists'
	)
);

file_put_contents(
	$argv[1],
	json_encode(
		array(
			'wordpress_loaded'    => defined( 'WPINC' ) || class_exists( 'WP_Roles', false ),
			'wordpress_functions' => $seocart_probe_wordpress_functions,
			'snapshot'            => SettingsSnapshot::take(),
		),
		JSON_THROW_ON_ERROR
	)
);
