<?php
/**
 * Tests that a request which touches no commerce pays almost nothing for SEOCart
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Performance;

use SEOCart\Tests\Support\BootstrapProbes;
use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tests\Support\LibraryShare;
use SEOCart\Tests\Support\PluginOwnership;
use SEOCart\Tests\Support\QueryCounter;
use SEOCart\Tests\Support\QueryLog;
use WP_UnitTestCase;

/**
 * The idle-request performance budgets G1, G3 and G4.
 *
 * The request measured is the front page of a site with no commerce on it, from the first
 * line of the WordPress boot (the main plugin file, `plugins_loaded`, `init`) to `wp_footer`.
 * In it the plugin may read one autoloaded option, register lazy factories and cheap
 * closures, and do nothing else.
 *
 * The request is served without and with the plugin, by
 * tests/Support/idle-request-probe.php, in fresh child PHP processes. The list of included
 * files and the query log only ever grow, so measured inside this PHPUnit process the numbers
 * would depend on which tests ran before. PHPUnit's own process isolation is not used,
 * because it never returns on FreeBSD, which is what the development server runs (see
 * ProcessIsolationTest).
 *
 * G1 gates on the total-query delta between the two processes. Stack attribution remains as
 * a diagnostic: a query counts there when shipped plugin code appears in the call stack wpdb
 * recorded for it. Attribution names a direct culprit but misses a query caused indirectly,
 * such as one from a WordPress function registered as the callback. G2, the autoloaded-option
 * budget, arrives with the settings registry that creates the option it measures.
 *
 * The plugin bundles Action Scheduler and requires it from its main file, so every request
 * also loads the library and its hooks. G3 and G4 state that share apart from the plugin's own,
 * with budgets of its own (LibraryShare decides which is which); G1 covers both. The library
 * finishes a one-time setup of its data store on its first queue run, and until then every
 * request reads that setup's state: the integration suite reinstalls WordPress and never runs
 * the queue, so tests/Support/library-prime-probe.php runs that setup once, as WP-Cron would,
 * before anything is measured.
 *
 * Each test names the planted violation that must turn it red. Unless it says otherwise, a plant
 * goes into SEOCart\Platform\Kernel\Kernel::boot(), directly after `self::$booted = true;`, and
 * is reverted afterwards.
 *
 * The site the probe serves has never been activated, so the boot record does not exist there.
 * That is the case the kernel's laziness exists for: reading the absent option would cost the
 * query WordPress spends to learn that it is absent. Two plants prove the kernel pays for neither
 * its record nor its services on an idle request:
 *
 * - `self::container()->get( BootOption::class )->read();` (with its `use`) in Kernel::boot():
 *   G1's total goes up by that one query;
 * - `$container->get( \SEOCart\Platform\Database\Migrator::class );` in Modules::subscribe(): G3's
 *   plugin share lists the database module's files.
 *
 * @since 0.1.0
 *
 * @group performance
 */
final class IdleBudgetTest extends WP_UnitTestCase {

	use QueryCounter;

	/**
	 * G1: queries the plugin may add to an idle request.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G1_PLUGIN_QUERIES = 0;

	/**
	 * G3: files of the plugin's own code an idle request may load: the main file and the kernel's
	 * three (the kernel, its container and the module wiring), with a margin of two.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_PLUGIN_FILES = 6;

	/**
	 * G3: bytes of plugin PHP an idle request may parse.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_PLUGIN_BYTES = 250 * 1024;

	/**
	 * G4: hook registrations the plugin may make on an idle request.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G4_MAX_PLUGIN_HOOKS = 25;

	/**
	 * G3, the bundled Action Scheduler's share: library PHP files an idle request may load.
	 *
	 * The library's three budgets are its measured cost, not a design target: Action Scheduler
	 * 4.2.0 on WordPress 7.1, once its data-store setup is done, loads 25 files (217,070 bytes)
	 * and adds 42 hook registrations. They are not the plugin's to shrink; they exist so that a
	 * library update, or a plugin change that wakes more of the library, shows up here and is
	 * measured again in that change.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_LIBRARY_FILES = 25;

	/**
	 * G3, the bundled Action Scheduler's share: bytes of library PHP an idle request may parse.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_LIBRARY_BYTES = 215 * 1024;

	/**
	 * G4, the bundled Action Scheduler's share: hook registrations loading the library adds to an idle request.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G4_MAX_LIBRARY_HOOKS = 42;

	/**
	 * What both probe modes reported, once they have run.
	 *
	 * @since 0.1.0
	 *
	 * @var array{without_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}, with_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}, without_plugin_again: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}}|null
	 */
	private static ?array $measurements = null;

	/**
	 * Tests G1: loading the plugin adds no query to the total request count.
	 *
	 * Planted violation: `add_action( 'init', 'get_users' );` in `Kernel::boot()`. The queries
	 * are issued by a core callback with no plugin frame, so the attribution test stays green;
	 * this delta must fail (13 became 16 when it was proven) and print the queries under the
	 * with-plugin-only heading. Do not plant `wp_count_posts`: `do_action( 'init' )` passes an
	 * empty string, that is no post type, and the function returns before it queries.
	 *
	 * @since 0.1.0
	 */
	public function test_g1_idle_request_adds_no_queries_to_the_request_total(): void {
		$measurements   = self::measurements();
		$without        = $measurements['without_plugin'];
		$with           = $measurements['with_plugin'];
		$without_log    = QueryLog::fromWpdb( $without['queries'] );
		$with_log       = QueryLog::fromWpdb( $with['queries'] );
		$only_with      = $with_log->difference( $without_log );
		$failure_report = "\nQueries present only in the with-plugin run:\n" . $only_with->describe() . "\n";

		$this->assertFalse( $without['plugin_loaded'], 'The control probe loaded SEOCart, so it is not a control run.' );
		$this->assertTrue( $with['plugin_loaded'], 'The with-plugin probe did not load SEOCart, so an equal count would prove nothing.' );
		$this->assertGreaterThan( 0, count( $without_log ), 'The control probe recorded no WordPress queries, so its total would prove nothing.' );
		$this->assertSame( $without['queries_run'], count( $without_log ), 'The control probe ran more queries than it recorded, so the totals are not comparable.' );
		$this->assertSame( $with['queries_run'], count( $with_log ), 'The with-plugin probe ran more queries than it recorded, so the totals are not comparable.' );

		$this->assertSame(
			$without['queries_run'],
			$measurements['without_plugin_again']['queries_run'],
			'Two control runs disagree, so this environment is not stable enough to measure a delta against.'
		);

		$this->assertSame(
			$without['queries_run'],
			$with['queries_run'],
			'G1, total queries added by SEOCart to an idle request. '
			. "Without plugin: {$without['queries_run']}; with plugin: {$with['queries_run']}."
			. $failure_report
		);
	}

	/**
	 * Tests G1's diagnostic: no recorded query has a shipped plugin frame.
	 *
	 * Planted violation: `get_option( 'seocart_planted_option' );`. The option does not exist
	 * and is therefore not autoloaded, so WordPress looks for it in the database. The failure
	 * must print that SELECT with Kernel::boot in its call stack.
	 *
	 * @since 0.1.0
	 */
	public function test_g1_idle_request_issues_no_plugin_queries(): void {
		$measurement = self::measurement();
		$everything  = QueryLog::fromWpdb( $measurement['queries'] );

		$this->assertGreaterThan( 0, count( $everything ), 'wpdb recorded no query at all, not even the ones WordPress issues to boot, so a count of zero would prove nothing.' );
		$this->assertSame( $measurement['queries_run'], count( $everything ), 'wpdb ran more queries than it recorded, so SAVEQUERIES was not on from the start of the request and a count of zero would prove nothing.' );

		$this->assertQueryCount(
			self::G1_PLUGIN_QUERIES,
			$everything->issuedBy( PluginOwnership::fromComposerManifest( self::pluginDirectory() ) ),
			'G1, queries issued by SEOCart on an idle request'
		);
	}

	/**
	 * Tests G3: the plugin loads few files, and small ones.
	 *
	 * Planted violation for the plugin's share: create three files `src/Planted/P01.php` to
	 * `src/Planted/P03.php`, each holding only `<?php`, and plant
	 * `foreach ( glob( dirname( __DIR__, 2 ) . '/Planted/P*.php' ) as $planted ) { require $planted; }`.
	 * With the main file and the kernel's three that makes seven.
	 *
	 * Planted violation for the byte count: create `src/Planted/Big.php` holding `<?php //`
	 * followed by 260,000 characters on the same line, and plant
	 * `require dirname( __DIR__, 2 ) . '/Planted/Big.php';`.
	 *
	 * The failure must list every counted file with its size. Delete `src/Planted/` afterwards.
	 *
	 * @since 0.1.0
	 */
	public function test_g3_idle_request_loads_few_plugin_files(): void {
		$files  = self::measurement()['files'];
		$report = "\n" . BootstrapProbes::describeFiles( $files ) . "\n";

		$this->assertArrayHasKey( 'seocart.php', $files, 'The probe did not see the main plugin file, so a small count would prove nothing.' . $report );

		$this->assertLessThanOrEqual(
			self::G3_MAX_PLUGIN_FILES,
			count( $files ),
			'G3, files of the plugin\'s own code loaded on an idle request. An eager service graph looks like this:' . $report
		);

		$this->assertLessThanOrEqual(
			self::G3_MAX_PLUGIN_BYTES,
			array_sum( $files ),
			'G3, bytes of plugin PHP parsed on an idle request:' . $report
		);
	}

	/**
	 * Tests G3's library share: the bundled Action Scheduler loads what it was measured to load, and no more.
	 *
	 * Planted violation, in Kernel::boot():
	 * `add_action( 'init', static function () { class_exists( 'ActionScheduler_wpPostStore' ); }, 99 );`.
	 * The library's posts store and what it depends on load, and the library's share grows past
	 * its budget. The failure must list every library file with its size.
	 *
	 * @since 0.1.0
	 */
	public function test_g3_idle_request_loads_the_measured_share_of_the_bundled_library(): void {
		$files  = self::measurement()['library_files'];
		$report = "\n" . BootstrapProbes::describeFiles( $files ) . "\n";

		$this->assertArrayHasKey( LibraryShare::DIRECTORY . 'action-scheduler.php', $files, 'The probe did not see the bundled library, so a small count would prove nothing.' . $report );

		$this->assertLessThanOrEqual(
			self::G3_MAX_LIBRARY_FILES,
			count( $files ),
			'G3, library PHP files loaded on an idle request:' . $report
		);

		$this->assertLessThanOrEqual(
			self::G3_MAX_LIBRARY_BYTES,
			array_sum( $files ),
			'G3, bytes of library PHP parsed on an idle request:' . $report
		);
	}

	/**
	 * Tests G4: the plugin registers few hooks.
	 *
	 * Planted violation:
	 * `for ( $planted = 0; $planted < 25; $planted++ ) { add_action( 'wp_footer', array( self::class, 'hasBooted' ), $planted ); }`.
	 * With the kernel's own four registrations (`plugins_loaded`, the activation and deactivation
	 * hooks and `map_meta_cap`) that makes twenty-nine. The failure must list them.
	 *
	 * @since 0.1.0
	 */
	public function test_g4_idle_request_registers_few_plugin_hooks(): void {
		$hooks  = self::hookShares()['plugin'];
		$report = "\n  " . implode( "\n  ", $hooks ) . "\n";

		$this->assertNotSame( array(), $hooks, 'The probe did not see even the plugins_loaded registration, so a small count would prove nothing.' );

		$this->assertLessThanOrEqual(
			self::G4_MAX_PLUGIN_HOOKS,
			count( $hooks ),
			'G4, hook registrations made by SEOCart on an idle request:' . $report
		);
	}

	/**
	 * Tests G4's library share: loading the bundled Action Scheduler adds the hooks it was measured to add, and no more.
	 *
	 * Planted violation, in Kernel::boot():
	 * `for ( $planted = 0; $planted < 30; $planted++ ) { add_action( 'wp_footer', 'ActionScheduler_Versions::instance', 1000 + $planted ); }`.
	 * Those callbacks are not the plugin's by the ownership rules, but loading the plugin added
	 * them, so they count here. The failure must list every registration.
	 *
	 * @since 0.1.0
	 */
	public function test_g4_idle_request_adds_the_measured_share_of_the_bundled_librarys_hooks(): void {
		$hooks  = self::hookShares()['library'];
		$report = "\n  " . implode( "\n  ", $hooks ) . "\n";

		$this->assertNotSame( array(), $hooks, 'The probe saw no registration of the bundled library, so a small count would prove nothing.' );

		$this->assertLessThanOrEqual(
			self::G4_MAX_LIBRARY_HOOKS,
			count( $hooks ),
			'G4, hook registrations that loading the bundled library added to an idle request:' . $report
		);
	}

	/**
	 * Returns the idle request's hook registrations, split into the plugin's own and the bundled library's.
	 *
	 * @since 0.1.0
	 *
	 * @return array{plugin: list<string>, library: list<string>} One line per registration.
	 */
	private static function hookShares(): array {
		$measurements = self::measurements();

		return LibraryShare::splitHooks( $measurements['with_plugin']['hooks'], $measurements['with_plugin']['all_hooks'], $measurements['without_plugin']['all_hooks'] );
	}

	/**
	 * Returns the with-plugin report, running both modes on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>} The report.
	 */
	private static function measurement(): array {
		return self::measurements()['with_plugin'];
	}

	/**
	 * Returns the reports: a control run, the plugin run, and the control run once more.
	 *
	 * The suite reinstalls WordPress when it starts, and the first request after an install
	 * primes options and transients that every later request only reads: measured here, it
	 * ran 22 queries where each later one ran 13. That request is served once and thrown
	 * away. The second control run proves that nothing else moved while the plugin ran.
	 *
	 * @since 0.1.0
	 *
	 * @return array{without_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}, with_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}, without_plugin_again: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>}} The reports.
	 */
	private static function measurements(): array {
		if ( null === self::$measurements ) {
			$primed = ChildProcessProbe::run( self::pluginDirectory() . '/tests/Support/library-prime-probe.php' );

			if ( true !== $primed['after'] ) {
				self::fail( 'The bundled Action Scheduler did not finish its data-store setup, so an idle request would be measured in that transient state.' );
			}

			self::serveIdleRequestInChildProcess( false );

			self::$measurements = array(
				'without_plugin'       => self::serveIdleRequestInChildProcess( false ),
				'with_plugin'          => self::serveIdleRequestInChildProcess( true ),
				'without_plugin_again' => self::serveIdleRequestInChildProcess( false ),
			);
		}

		return self::$measurements;
	}

	/**
	 * Serves the idle request in a child PHP process and returns the probe's report.
	 *
	 * The child inherits this process's environment, and with it the test configuration path
	 * and WP_TESTS_SKIP_INSTALL, both exported by the integration bootstrap.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $load_plugin Whether the integration bootstrap loads the plugin.
	 * @return array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, library_files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>, all_hooks: list<string>} The report.
	 */
	private static function serveIdleRequestInChildProcess( bool $load_plugin ): array {
		$result_file = (string) tempnam( sys_get_temp_dir(), 'seocart-idle-' );
		$mode        = $load_plugin ? 'with-plugin' : 'without-plugin';

		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( self::pluginDirectory() . '/tests/Support/idle-request-probe.php' )
			. ' ' . escapeshellarg( $result_file )
			. ' ' . escapeshellarg( $mode )
			. ' 2>&1';

		exec( $command, $output, $status );

		$report = (string) file_get_contents( $result_file );

		unlink( $result_file );

		if ( 0 !== $status || '' === $report ) {
			self::fail( "The idle-request probe failed with exit status {$status}. Its output:\n" . implode( "\n", $output ) . "\n" );
		}

		return json_decode( $report, true, 512, JSON_THROW_ON_ERROR );
	}

	/**
	 * Returns the plugin directory of this checkout.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private static function pluginDirectory(): string {
		return dirname( __DIR__, 3 );
	}
}
