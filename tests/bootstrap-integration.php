<?php
/**
 * Bootstrap for the integration test suite
 *
 * Loads WordPress through the wp-phpunit library against a dedicated MySQL database, with
 * SEOCart loaded the way a must-use loader would load it. The idle-request probe can disable
 * that one inclusion for its control process. `composer test:integration` selects this file
 * with PHPUnit's bootstrap command-line option.
 *
 * tests/Support/idle-request-probe.php includes this file too, in the child process that the
 * idle-budget test starts to measure a request in a process that served nothing else.
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use Composer\Autoload\ClassLoader;
use SEOCart\Tests\Support\AutoloaderWatch;
use SEOCart\Tests\Support\ErrorRecorder;
use SEOCart\Tests\Support\PluginOwnership;

$seocart_tests_plugin_dir  = dirname( __DIR__ );
$seocart_tests_load_plugin = '0' !== getenv( 'SEOCART_TESTS_LOAD_PLUGIN' );

require_once $seocart_tests_plugin_dir . '/vendor/autoload.php';

/*
 * The database the suite runs against is described by a configuration file that is never
 * committed, because it holds generated credentials. wp-phpunit reads its path from the
 * WP_PHPUNIT__TESTS_CONFIG environment variable, here and in the child process that installs
 * WordPress, so the default location is exported through the same variable.
 */
$seocart_tests_config = getenv( 'WP_PHPUNIT__TESTS_CONFIG' );

if ( false === $seocart_tests_config || '' === $seocart_tests_config ) {
	$seocart_tests_config = __DIR__ . '/wp-tests-config.local.php';
}

if ( ! is_readable( $seocart_tests_config ) ) {
	fwrite(
		STDERR,
		PHP_EOL
		. 'SEOCart integration tests cannot start: there is no WordPress test configuration at' . PHP_EOL
		. '  ' . $seocart_tests_config . PHP_EOL
		. PHP_EOL
		. 'Run bin/dev/provision-test-db.sh from the plugin directory. It creates a dedicated test' . PHP_EOL
		. 'database and writes tests/wp-tests-config.local.php from tests/wp-tests-config.template.php.' . PHP_EOL
		. 'To use a configuration kept somewhere else, put its absolute path in the' . PHP_EOL
		. 'WP_PHPUNIT__TESTS_CONFIG environment variable.' . PHP_EOL
		. PHP_EOL
	);

	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $seocart_tests_config );

// wp-phpunit exports its own location when Composer's autoloader loads it.
$seocart_tests_library = getenv( 'WP_PHPUNIT__DIR' );

if ( false === $seocart_tests_library || ! is_dir( $seocart_tests_library ) ) {
	fwrite( STDERR, PHP_EOL . 'SEOCart integration tests cannot start: the wp-phpunit library is not installed. Run `composer install`.' . PHP_EOL . PHP_EOL );

	exit( 1 );
}

// The WordPress test library needs the polyfills and looks for them where WordPress core keeps them.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the name is dictated by the WordPress test library.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $seocart_tests_plugin_dir . '/vendor/yoast/phpunit-polyfills' );

/*
 * Every query budget reads `$wpdb->queries`, which wpdb fills only when SAVEQUERIES is on. It
 * is switched on here, before the first query, so that the queries WordPress issues while it
 * boots are recorded too: the idle budget covers the boot as well as the request.
 */
if ( ! defined( 'SAVEQUERIES' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- SAVEQUERIES is WordPress's own constant.
	define( 'SAVEQUERIES', true );
}

require_once $seocart_tests_library . '/includes/functions.php';

/*
 * The plugin is not inside the WordPress checkout, which is shared and read-only, so it is
 * included directly at the point where WordPress loads must-use plugins unless the idle
 * probe requested its control mode. A closure, not a named function: a function called
 * `seocart_…` would be counted by the hook budget as one of the plugin's own registrations.
 *
 * Errors are recorded from here until WordPress has finished loading, which covers the main
 * file, `plugins_loaded` and `init`. PluginLoadsTest fails on anything recorded.
 *
 * Composer's development class loader was registered first and maps `SEOCart\` to `src/` too,
 * so it would load every plugin class, and the autoloader in seocart.php would never run. The
 * release's generated scoped autoloader does not map plugin classes. The watch moves the
 * development loader behind the plugin autoloader and records every plugin class that still
 * ends up with Composer. PluginLoadsTest fails on those as well.
 */
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $seocart_tests_load_plugin, $seocart_tests_plugin_dir ): void {
		ErrorRecorder::start();

		if ( ! $seocart_tests_load_plugin ) {
			return;
		}

		require_once $seocart_tests_plugin_dir . '/seocart.php';

		AutoloaderWatch::start( ClassLoader::getRegisteredLoaders(), PluginOwnership::fromComposerManifest( $seocart_tests_plugin_dir ) );
	}
);

require $seocart_tests_library . '/includes/bootstrap.php';

ErrorRecorder::stop();

/*
 * WordPress is installed once per run, by the process that gets here first. A child process
 * started by a test inherits this variable and boots against the database as it stands.
 * Reinstalling from a child would drop the tables underneath the test that is waiting for it.
 */
putenv( 'WP_TESTS_SKIP_INSTALL=1' );
