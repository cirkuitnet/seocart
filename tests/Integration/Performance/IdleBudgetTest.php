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
 * The idle-request budgets G1, G3 and G4 of docs/architecture/performance.md, section 4.
 *
 * The request measured is the front page of a site with no commerce on it, from the first
 * line of the WordPress boot (the main plugin file, `plugins_loaded`, `init`) to `wp_footer`.
 * Section 5.1 of the same document says what the plugin may do in it: read one autoloaded
 * option, register lazy factories and cheap closures, and nothing else.
 *
 * The request is served once, by tests/Support/idle-request-probe.php, in a child PHP process
 * that serves nothing else. The list of included files and the query log only ever grow, so
 * measured inside this PHPUnit process the numbers would depend on which tests ran before.
 * PHPUnit's own process isolation is not used, because it never returns on FreeBSD, which is
 * what the development server runs (see ProcessIsolationTest).
 *
 * G1 is attributed, not differenced: a query counts against the plugin when shipped plugin
 * code appears in the call stack wpdb recorded for it. Attribution names the culprit and needs
 * no second run without the plugin, but it has a blind spot that a differenced count would
 * not have: a query the plugin causes without being in its stack. Registering a WordPress
 * function as a hook callback does that, and so does a filter whose return value makes
 * WordPress query more. G2, the autoloaded-option budget, arrives with the settings registry
 * that creates the option it measures.
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
	 * G1: queries the plugin may add to an idle request. performance.md section 4: "+0 queries".
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G1_PLUGIN_QUERIES = 0;

	/**
	 * G3: plugin PHP files an idle request may load. performance.md section 4: "≤ 15 files".
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_PLUGIN_FILES = 15;

	/**
	 * G3: bytes of plugin PHP an idle request may parse. performance.md section 4: "≤ 250 KB parsed".
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G3_MAX_PLUGIN_BYTES = 250 * 1024;

	/**
	 * G4: hook registrations the plugin may make on an idle request. performance.md section 4: "≤ 25".
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const G4_MAX_PLUGIN_HOOKS = 25;

	/**
	 * What the probe reported, once it has run.
	 *
	 * @since 0.1.0
	 *
	 * @var array{queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>}|null
	 */
	private static ?array $measurement = null;

	/**
	 * Tests G1: the plugin issues no query, neither while WordPress boots nor while the page is served.
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
			'G1, queries issued by SEOCart on an idle request (docs/architecture/performance.md, section 4)'
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
			'G3, plugin PHP files loaded on an idle request (docs/architecture/performance.md, section 4). An eager service graph looks like this:' . $report
		);

		$this->assertLessThanOrEqual(
			self::G3_MAX_PLUGIN_BYTES,
			array_sum( $files ),
			'G3, bytes of plugin PHP parsed on an idle request (docs/architecture/performance.md, section 4):' . $report
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
			'G4, hook registrations made by SEOCart on an idle request (docs/architecture/performance.md, section 4):' . $report
		);
	}

	/**
	 * Returns what the probe reported, running it on first use.
	 *
	 * @since 0.1.0
	 *
	 * @return array{queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>} The report.
	 */
	private static function measurement(): array {
		if ( null === self::$measurement ) {
			self::$measurement = self::serveIdleRequestInChildProcess();
		}

		return self::$measurement;
	}

	/**
	 * Serves the idle request in a child PHP process and returns the probe's report.
	 *
	 * The child inherits this process's environment, and with it the test configuration path
	 * and WP_TESTS_SKIP_INSTALL, both exported by the integration bootstrap.
	 *
	 * @since 0.1.0
	 *
	 * @return array{queries_run: int, queries: array<int, array<int, mixed>>, files: array<string, int>, hooks: list<array{hook: string, priority: int, callback: string}>} The report.
	 */
	private static function serveIdleRequestInChildProcess(): array {
		$result_file = (string) tempnam( sys_get_temp_dir(), 'seocart-idle-' );

		$command = escapeshellarg( PHP_BINARY )
			. ' ' . escapeshellarg( self::pluginDirectory() . '/tests/Support/idle-request-probe.php' )
			. ' ' . escapeshellarg( $result_file )
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
