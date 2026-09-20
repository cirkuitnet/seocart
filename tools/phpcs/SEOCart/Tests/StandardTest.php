<?php
/**
 * Self-tests for the SEOCart PHP_CodeSniffer standard
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\phpcs\SEOCart\Tests;

use FilesystemIterator;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Files\DummyFile;
use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Files\LocalFile;
use PHP_CodeSniffer\Runner;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Runs the real ruleset.xml over every file in Fixtures/ and compares what it reports with
 * what each fixture says it must report.
 *
 * A fixture declares its expectations itself, on the offending line, as a trailing comment:
 * `// Expect:` followed by one or more full error codes separated by commas. The comparison
 * is exact in both directions, so one fixture proves that violations are reported where
 * expected and that every other line, the look-alikes, stays clean. The fixture tree mirrors
 * the plugin's layout (src/, Interfaces/, the allow-listed directories, the two root files)
 * because the standard's scope and allow-lists are paths relative to a root. Fixtures/ is
 * that root here.
 *
 * @since 0.1.0
 */
final class StandardTest extends TestCase {

	/**
	 * PHP_CodeSniffer, initialised once with the SEOCart standard.
	 *
	 * @since 0.1.0
	 * @var Runner
	 */
	private static $runner;

	/**
	 * Loads PHP_CodeSniffer, which Composer does not autoload, and builds the ruleset.
	 *
	 * @since 0.1.0
	 */
	public static function setUpBeforeClass(): void {
		require_once self::repositoryDirectory() . '/vendor/squizlabs/php_codesniffer/autoload.php';

		$standard = dirname( __DIR__ ) . '/ruleset.xml';
		$runner   = new Runner();

		$runner->config = new Config( array( '--standard=' . $standard ) );

		/*
		 * Config keeps command-line values in static state for the life of the process, so
		 * everything this test depends on is also assigned directly.
		 */
		$runner->config->standards = array( $standard );
		$runner->config->sniffs    = array();
		$runner->config->exclude   = array();
		$runner->config->cache     = false;
		$runner->config->basepath  = self::fixturesDirectory();
		$runner->init();

		self::$runner = $runner;
	}

	/**
	 * Returns the fixtures directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private static function fixturesDirectory(): string {
		return __DIR__ . '/Fixtures';
	}

	/**
	 * Returns the repository this standard lies in.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private static function repositoryDirectory(): string {
		return dirname( __DIR__, 4 );
	}

	/**
	 * Provides every fixture file, keyed by its path relative to Fixtures/.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> One data set per fixture.
	 */
	public static function fixtures(): array {
		$root  = self::fixturesDirectory();
		$files = array();

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$files[ substr( $file->getPathname(), strlen( $root ) + 1 ) ] = array( $file->getPathname() );
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Reads the errors a fixture declares, from its trailing `Expect:` comments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path of the fixture.
	 * @return array<int, string[]> Sorted error codes, keyed by line number.
	 */
	private static function expectedErrors( string $path ): array {
		$expected = array();

		foreach ( (array) file( $path ) as $index => $line ) {
			if ( 1 === preg_match( '~//\s*Expect:(.*)$~', (string) $line, $matches ) ) {
				$codes = array_values( array_filter( array_map( 'trim', explode( ',', $matches[1] ) ) ) );
				sort( $codes );

				$expected[ $index + 1 ] = $codes;
			}
		}

		return $expected;
	}

	/**
	 * Flattens PHP_CodeSniffer's line => column => messages structure to codes per line.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<int, array<int, array<string, mixed>>>> $messages Errors or warnings of one file.
	 * @return array<int, string[]> Sorted codes, keyed by line number in ascending order.
	 */
	private static function codesByLine( array $messages ): array {
		$codes = array();

		foreach ( $messages as $line => $columns ) {
			foreach ( $columns as $column ) {
				foreach ( $column as $message ) {
					$codes[ $line ][] = (string) $message['source'];
				}
			}

			sort( $codes[ $line ] );
		}

		ksort( $codes );

		return $codes;
	}

	/**
	 * Scans a fixture's content as if the fixture tree were checked out at another root.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $fixture  Absolute path of the fixture.
	 * @param string      $root     The directory that stands in for Fixtures/, without a trailing slash.
	 * @param string|null $basepath PHP_CodeSniffer's base path for the scan.
	 * @return File The processed file.
	 */
	private static function processBelow( string $fixture, string $root, ?string $basepath ): File {
		$config = clone self::$runner->config;

		$config->basepath  = $basepath;
		$config->stdinPath = $root . substr( $fixture, strlen( self::fixturesDirectory() ) );

		$file = new DummyFile( (string) file_get_contents( $fixture ), self::$runner->ruleset, $config );
		$file->process();

		return $file;
	}

	/**
	 * Asserts that a scan reported exactly the errors the fixture declares, and no warnings.
	 *
	 * @since 0.1.0
	 *
	 * @param string $fixture Absolute path of the fixture.
	 * @param File   $file    The processed file.
	 */
	private function assertReportsWhatTheFixtureDeclares( string $fixture, File $file ): void {
		$this->assertSame( self::expectedErrors( $fixture ), self::codesByLine( $file->getErrors() ), 'Errors, as line => codes.' );
		$this->assertSame( array(), self::codesByLine( $file->getWarnings() ), 'Every rule in this standard is an error; nothing may be a warning.' );
	}

	/**
	 * Tests that the standard reports exactly the errors a fixture declares, and no warnings.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $path Absolute path of the fixture.
	 */
	public function test_fixture_reports_exactly_the_errors_it_declares( string $path ): void {
		$file = new LocalFile( $path, self::$runner->ruleset, self::$runner->config );
		$file->process();

		$this->assertReportsWhatTheFixtureDeclares( $path, $file );
	}

	/**
	 * Tests that paths are matched from the root and not anywhere in the absolute path.
	 *
	 * A checkout may live below a directory that is itself named src/. That must neither bring
	 * tests/ and tools/ into the scope of the DRY rules nor take anything out of it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $path Absolute path of the fixture.
	 */
	public function test_a_checkout_below_a_directory_named_src_reports_the_same( string $path ): void {
		$root = '/checkouts/src/Interfaces/seocart';

		$this->assertReportsWhatTheFixtureDeclares( $path, self::processBelow( $path, $root, $root ) );
	}

	/**
	 * Tests that the root is the repository this standard lies in when no base path is set,
	 * which is how `composer cs:dry` runs.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider fixtures
	 *
	 * @param string $path Absolute path of the fixture.
	 */
	public function test_the_root_is_the_repository_when_no_basepath_is_set( string $path ): void {
		$this->assertReportsWhatTheFixtureDeclares( $path, self::processBelow( $path, self::repositoryDirectory(), null ) );
	}

	/**
	 * Tests that `composer cs:dry` scans exactly the paths the ruleset declares as shipped code.
	 *
	 * A standard cannot choose which files are scanned, so the command has to name them again.
	 * This keeps the two lists equal.
	 *
	 * @since 0.1.0
	 */
	public function test_composer_cs_dry_scans_exactly_the_shipped_paths(): void {
		$composer  = json_decode( (string) file_get_contents( self::repositoryDirectory() . '/composer.json' ), true );
		$arguments = (array) preg_split( '~\s+~', trim( (string) $composer['scripts']['cs:dry'] ) );
		$scanned   = array();

		// The first word is the command; the options start with a dash; the rest are paths.
		foreach ( array_slice( $arguments, 1 ) as $argument ) {
			if ( ! str_starts_with( (string) $argument, '-' ) ) {
				$scanned[] = (string) $argument;
			}
		}

		$scoped = 0;

		foreach ( self::$runner->ruleset->sniffs as $sniff ) {
			if ( property_exists( $sniff, 'shippedPaths' ) ) {
				$this->assertEqualsCanonicalizing( $scanned, $sniff->shippedPaths, get_class( $sniff ) );
				++$scoped;
			}
		}

		$this->assertGreaterThan( 0, $scoped, 'No sniff in the standard has a shippedPaths property.' );
	}

	/**
	 * Tests that no rule of the standard is left unproven.
	 *
	 * The list of rules comes from the ruleset itself, so a sniff added later fails here until
	 * a fixture shows it turning red.
	 *
	 * @since 0.1.0
	 */
	public function test_every_sniff_in_the_standard_is_reported_by_at_least_one_fixture(): void {
		$declared = array();

		foreach ( self::fixtures() as $arguments ) {
			foreach ( self::expectedErrors( $arguments[0] ) as $codes ) {
				foreach ( $codes as $code ) {
					// Standard.Category.Sniff.ErrorCode: the first three parts name the sniff.
					$declared[ implode( '.', array_slice( explode( '.', $code ), 0, 3 ) ) ] = true;
				}
			}
		}

		$sniffs = array_keys( self::$runner->ruleset->sniffCodes );

		sort( $sniffs );
		ksort( $declared );

		$this->assertNotSame( array(), $sniffs, 'The standard registered no sniffs.' );
		$this->assertSame( $sniffs, array_keys( $declared ) );
	}
}
