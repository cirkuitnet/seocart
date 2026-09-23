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
 * Each test names the planted violation that must turn it red. Every plant goes into
 * SEOCart\Platform\Kernel\Kernel::boot(), directly after `self::$booted = true;`, and is
 * reverted afterwards.
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
	 * G3: plugin PHP files an idle request may load.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_PLUGIN_FILES = 15;

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
	 * What both probe modes reported, once they have run.
	 *
	 * @since 0.1.0
	 *
	 * @var array{without_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}, with_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}, without_plugin_again: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}}|null
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
	 * Planted violation for the file count: create fifteen files `src/Planted/P01.php` to
	 * `src/Planted/P15.php`, each holding only `<?php`, and plant
	 * `foreach ( glob( dirname( __DIR__, 2 ) . '/Planted/P*.php' ) as $planted ) { require $planted; }`.
	 * With the main file and the kernel that makes seventeen.
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
			'G3, plugin PHP files loaded on an idle request. An eager service graph looks like this:' . $report
		);

		$this->assertLessThanOrEqual(
			self::G3_MAX_PLUGIN_BYTES,
			array_sum( $files ),
			'G3, bytes of plugin PHP parsed on an idle request:' . $report
		);
	}

	/**
	 * Tests G4: the plugin registers few hooks.
	 *
	 * Planted violation:
	 * `for ( $planted = 0; $planted < 25; $planted++ ) { add_action( 'wp_footer', array( self::class, 'hasBooted' ), $planted ); }`.
	 * With the `plugins_loaded` registration that makes twenty-six. The failure must list them.
	 *
	 * @since 0.1.0
	 */
	public function test_g4_idle_request_registers_few_plugin_hooks(): void {
		$hooks  = self::measurement()['hooks'];
		$report = "\n" . BootstrapProbes::describeHooks( $hooks ) . "\n";

		$this->assertNotSame( array(), $hooks, 'The probe did not see even the plugins_loaded registration, so a small count would prove nothing.' );

		$this->assertLessThanOrEqual(
			self::G4_MAX_PLUGIN_HOOKS,
			count( $hooks ),
			'G4, hook registrations made by SEOCart on an idle request:' . $report
		);
	}

	/**
	 * Returns the with-plugin report, running both modes on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>} The report.
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
	 * @return array{without_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}, with_plugin: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}, without_plugin_again: array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}} The reports.
	 */
	private static function measurements(): array {
		if ( null === self::$measurements ) {
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
	 * @return array{plugin_loaded: bool, queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>} The report.
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
