<?php
/**
 * Builds the production data registry in a process without WordPress, reads all of it, and reports what it saw
 *
 * Usage: php tests/Support/data-registry-probe.php <result-file>
 *
 * OwnedDataTest starts this script in a fresh PHP process. It loads the unit bootstrap, which
 * defines the placeholder ABSPATH every file under src/ checks for and then loads Composer's
 * autoloader, and nothing else: no WordPress, and none of the stand-ins for WordPress functions
 * that Brain Monkey leaves behind in a PHPUnit process that has run other tests. A WordPress
 * call made while the registry is built or read is therefore a fatal error here, and the test
 * shows it. The report lists every file the process loaded, so the test can also see that
 * nothing but the plugin's classes and Composer's autoloader was read.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use SEOCart\Platform\Database\Schema\Classification;
use SEOCart\Platform\DataRegistry\OwnedData;

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

$seocart_probe_registry = OwnedData::registry();

$seocart_probe_classified = array();

foreach ( Classification::cases() as $seocart_probe_class ) {
	foreach ( $seocart_probe_registry->columnsClassified( $seocart_probe_class ) as $seocart_probe_table => $seocart_probe_columns ) {
		foreach ( $seocart_probe_columns as $seocart_probe_column ) {
			$seocart_probe_classified[ $seocart_probe_class->value ][] = $seocart_probe_table . '.' . $seocart_probe_column->name();
		}
	}
}

$seocart_probe_retention = array();

foreach ( $seocart_probe_registry->retention()->ids() as $seocart_probe_policy ) {
	$seocart_probe_retention[ $seocart_probe_policy ] = array(
		'rule'     => $seocart_probe_registry->retention()->rule( $seocart_probe_policy ),
		'defaults' => $seocart_probe_registry->retention()->defaults( $seocart_probe_policy ),
	);
}

$seocart_probe_roles = array();

foreach ( $seocart_probe_registry->capabilities()->roles() as $seocart_probe_role ) {
	$seocart_probe_roles[ $seocart_probe_role ] = $seocart_probe_registry->capabilities()->bundle( $seocart_probe_role );
}

file_put_contents(
	$argv[1],
	json_encode(
		array(
			'wordpress_loaded'    => defined( 'WPINC' ) || class_exists( 'WP_Roles', false ) || class_exists( 'wpdb', false ),
			'wordpress_functions' => $seocart_probe_wordpress_functions,
			'tables'              => $seocart_probe_registry->tableNames(),
			'retention_by_table'  => array_combine(
				$seocart_probe_registry->tableNames(),
				array_map( static fn( $table ): string => $table->retention(), $seocart_probe_registry->tables() )
			),
			'classified_columns'  => $seocart_probe_classified,
			'migrations'          => array_map( static fn( $migration ): string => $migration->id(), $seocart_probe_registry->migrations() ),
			'options'             => $seocart_probe_registry->optionNames(),
			'job_groups'          => $seocart_probe_registry->jobGroups(),
			'retention'           => $seocart_probe_retention,
			'roles'               => $seocart_probe_roles,
			'files'               => get_included_files(),
		),
		JSON_THROW_ON_ERROR
	)
);
