<?php
/**
 * Tests that a SEOCart extension costs an idle request almost nothing
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Extension;

use SEOCart\Tests\Support\ChildProcessProbe;
use SEOCart\Tools\Packaging\PluginPackage;
use WP_UnitTestCase;

/**
 * The idle budget of an extension: its share of a request that touches no commerce is at most
 * one file (its main file), one hook registration (the one it registers with SEOCart on) and
 * no query.
 *
 * It measures the extension SEOCART_EXTENSION_PATH names, which is how an extension's CI runs
 * it: `sh bin/ci/extension.sh idle <extension root>`. In SEOCart's own suite no extension is
 * named, and the test is skipped. The request is the one IdleBudgetTest measures
 * (tests/Support/idle-request-probe.php), served with SEOCart alone and with SEOCart and the
 * extension, each in a fresh process; the extension's share is the difference, so a file or a
 * hook of SEOCart that the extension makes load counts against the extension.
 *
 * Planted violations, each in the extension's main file and each turning the test red: a
 * `get_option( 'seocart_gateway_for_example_planted' );` (a query), a second `add_action()` (a hook), and
 * a `require __DIR__ . '/src/Gateway.php';` (a file).
 *
 * @since 0.2.0
 *
 * @group extension-idle
 */
final class ExtensionIdleShareTest extends WP_UnitTestCase {

	/**
	 * The most files an extension may add to an idle request: its main file.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MAX_FILES = 1;

	/**
	 * The most hook registrations an extension may add to an idle request: its registration with SEOCart.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const MAX_HOOKS = 1;

	/**
	 * Tests the extension's share of an idle request.
	 *
	 * @since 0.2.0
	 */
	public function test_an_idle_request_costs_the_extension_one_file_one_hook_and_no_query(): void {
		$root = (string) getenv( 'SEOCART_EXTENSION_PATH' );

		if ( '' === $root ) {
			$this->markTestSkipped( 'No extension is named: bin/ci/extension.sh idle <extension root> names one in SEOCART_EXTENSION_PATH.' );
		}

		$main_file = (string) realpath( $root . '/' . PluginPackage::at( $root )->mainFile );
		$probe     = dirname( __DIR__, 2 ) . '/Support/idle-request-probe.php';

		// The first requests after an install prime what every later one only reads (IdleBudgetTest says why).
		ChildProcessProbe::run( dirname( __DIR__, 2 ) . '/Support/library-prime-probe.php' );
		ChildProcessProbe::run( $probe, array( 'with-plugin' ) );

		$alone       = ChildProcessProbe::run( $probe, array( 'with-plugin' ) );
		$extended    = ChildProcessProbe::run( $probe, array( 'with-extension' ) );
		$alone_again = ChildProcessProbe::run( $probe, array( 'with-plugin' ) );

		$this->assertNotContains( $main_file, $alone['included_files'], 'The control run loaded the extension, so it is not a control run.' );
		$this->assertContains( $main_file, $extended['included_files'], 'The probe did not load the extension, so a small share would prove nothing.' );
		$this->assertSame( $alone['queries_run'], $alone_again['queries_run'], 'Two control runs disagree, so this environment is not stable enough to measure a share against.' );

		$files = array_values( array_filter( array_diff( $extended['included_files'], $alone['included_files'] ), array( self::class, 'servedBySite' ) ) );
		$hooks = self::added( $alone['all_hooks'], $extended['all_hooks'] );

		$this->assertLessThanOrEqual( self::MAX_FILES, count( $files ), "The extension made an idle request load these files:\n  " . implode( "\n  ", $files ) );
		$this->assertLessThanOrEqual( self::MAX_HOOKS, count( $hooks ), "The extension added these hook registrations to an idle request:\n  " . implode( "\n  ", $hooks ) );
		$this->assertSame(
			$alone['queries_run'],
			$extended['queries_run'],
			"The extension added queries to an idle request. SEOCart alone: {$alone['queries_run']}; with the extension: {$extended['queries_run']}."
		);
	}

	/**
	 * Tells whether a file is one a site would load, rather than this test harness.
	 *
	 * To load the extension, the integration bootstrap finds its main file with SEOCart's
	 * development tools; SEOCart's tools/, tests/ and vendor/ never reach a site, so a file of
	 * theirs is the measurement's, not the extension's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $file An absolute path, as get_included_files() returns it.
	 * @return bool False for a file of SEOCart's development tree.
	 */
	private static function servedBySite( string $file ): bool {
		$core = (string) realpath( dirname( __DIR__, 3 ) );
		$real = realpath( $file );

		// SEOCart may be reached through a link, as the `seocart` beside an extension can be.
		$file = false === $real ? $file : $real;

		foreach ( array( 'tools', 'tests', 'vendor' ) as $directory ) {
			if ( str_starts_with( $file, $core . '/' . $directory . '/' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the registrations in one hook table that another does not have, counting repeats.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $before The registrations without the extension, one line each.
	 * @param string[] $after  The registrations with it.
	 * @return list<string> The lines only the second table has.
	 */
	private static function added( array $before, array $after ): array {
		$remaining = array_count_values( $before );
		$added     = array();

		foreach ( $after as $line ) {
			if ( ( $remaining[ $line ] ?? 0 ) > 0 ) {
				--$remaining[ $line ];
				continue;
			}

			$added[] = $line;
		}

		return $added;
	}
}
