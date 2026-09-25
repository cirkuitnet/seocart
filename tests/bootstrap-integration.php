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
use SEOCart\Tests\Support\TestDatabasePrefix;

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

/*
 * WordPress is installed once per run, by the process that gets here first (the same process
 * that, further down, sets WP_TESTS_SKIP_INSTALL so a child process never repeats either step).
 * That installing process is also the one that must start from an empty database, and it must do
 * so before wp-phpunit's own bootstrap runs, for a reason its own installer cannot fix from
 * inside itself: wp-phpunit's install.php boots WordPress (`require ABSPATH . 'wp-settings.php'`)
 * against the database exactly as a previous run left it, and only after that drops and recreates
 * the tables WordPress's own installer knows about. A killed previous run can leave, in that same
 * database: a deleted site's own tables; the main site's plugin tables, which the boot above
 * reads as already installed; pending scheduled actions; and, subtlest, a role a plugin granted
 * on an earlier run, which that pre-drop boot reads into memory and `populate_roles()` then
 * writes back into the freshly emptied options table before anything of ours runs again.
 *
 * The configuration template already says this database is "used by nothing else: installing the
 * test site DROPS ITS TABLES"; this drops every table of the configured prefix, not only the ones
 * WordPress's own installer would have dropped anyway, which is the same hazard extended to the
 * rest of what a run can leave behind. It runs here, before wp-phpunit's bootstrap is required at
 * all, so nothing below it — not that pre-drop boot, not a test — ever reads a previous run's
 * state. $wpdb does not exist yet at this point, so the connection is a plain one, with the
 * configuration's own settings.
 */
if ( '1' !== getenv( 'WP_TESTS_SKIP_INSTALL' ) ) {
	try {
		TestDatabasePrefix::dropAll( TestDatabasePrefix::readConfig( $seocart_tests_config ) );
	} catch ( \Throwable $e ) {
		fwrite( STDERR, PHP_EOL . 'SEOCart integration tests cannot start: ' . $e->getMessage() . PHP_EOL . PHP_EOL );

		exit( 1 );
	}
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

/*
 * The multilingual conformance suite runs against a real multilingual plugin, which is loaded
 * only when it is asked for: with SEOCART_ML_ADAPTER=polylang and SEOCART_ML_PLUGIN naming
 * Polylang's main file, Polylang is loaded where WordPress loads plugins, as an active plugin is.
 * The suite gives the site its languages (Support\Localization\PolylangFixture). Any other value, or
 * none, loads nothing.
 */
$seocart_tests_ml_plugin = (string) getenv( 'SEOCART_ML_PLUGIN' );

if ( 'polylang' === getenv( 'SEOCART_ML_ADAPTER' ) ) {
	if ( ! is_readable( $seocart_tests_ml_plugin ) ) {
		fwrite( STDERR, PHP_EOL . 'SEOCART_ML_ADAPTER=polylang needs SEOCART_ML_PLUGIN, the path of polylang.php; none is readable at "' . $seocart_tests_ml_plugin . '".' . PHP_EOL . PHP_EOL );
		exit( 1 );
	}

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $seocart_tests_ml_plugin ): void {
			require_once $seocart_tests_ml_plugin;
		},
		PHP_INT_MAX
	);
}

require $seocart_tests_library . '/includes/bootstrap.php';

ErrorRecorder::stop();

/*
 * A child process started by a test inherits this variable and boots against the database as
 * the installing process above left it. Reinstalling — or dropping every table of the prefix
 * again — from a child would pull the tables out from under the test that is waiting for it.
 */
putenv( 'WP_TESTS_SKIP_INSTALL=1' );
