<?php
/**
 * ConformanceRun: runs the integration half of the payment gateway conformance suite
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * Runs the conformance group of an integration suite: SEOCart's own, or the extension's that SEOCART_EXTENSION_PATH names.
 *
 * Owns one fact: which tests the integration half of `composer test:conformance:payment` runs.
 * SEOCart's are in its own integration suite. An extension's is its test that extends
 * GatewayConformanceCase, in the integration suite its own phpunit.xml.dist names, run on
 * SEOCart's integration bootstrap, which loads the extension before SEOCart. An extension
 * with no such test yet is skipped, and says so loudly: a warning annotation under GitHub
 * Actions and a line that begins `conformance: SKIPPED`, naming the file to add, since the unit
 * group alone holds a gateway to no rule that needs its provider's answers. A group that selects
 * no test fails, as every other group script does.
 *
 * @since 0.2.0
 */
final class ConformanceRun {

	/**
	 * Where the extension kit's generator writes an extension's conformance test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const TEST_FILE = 'tests/Integration/ConformanceTest.php';

	/**
	 * Runs the integration half for SEOCart or an extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $core      SEOCart's root.
	 * @param string   $extension The extension's root; empty for SEOCart's own suite.
	 * @param bool     $actions   Whether the run is a GitHub Actions job, which shows the skip as a warning annotation.
	 * @param resource $out       Optional. Where the skip is written. Default the standard output.
	 * @return int PHPUnit's exit code, or 0 for an extension that has no conformance test yet, whose skip is written.
	 */
	public static function main( string $core, string $extension, bool $actions, $out = STDOUT ): int {
		$extension = rtrim( $extension, '/' );

		if ( '' !== $extension && null === self::conformanceTestIn( $extension ) ) {
			$skip = self::missingTest( $extension );

			fwrite( $out, ( $actions ? '::warning title=Conformance suite skipped::' . $skip . "\n" : '' ) . 'conformance: SKIPPED: ' . $skip . "\n" );

			return 0;
		}

		$process = proc_open( self::command( $core, $extension ), array( STDIN, STDOUT, STDERR ), $pipes, $core );

		return false === $process ? 1 : proc_close( $process );
	}

	/**
	 * Returns what is said of an extension that has no conformance test yet.
	 *
	 * @since 0.2.0
	 *
	 * @param string $extension The extension's root.
	 * @return string The sentence, which names the file to add.
	 */
	public static function missingTest( string $extension ): string {
		return sprintf(
			'%1$s has no payment conformance test yet, so its gateway was held only to the rules of the unit group, and to none that needs a provider\'s answers. Add %2$s, a test that extends %3$s; bin/dev/new-extension.sh writes it, and docs/writing-an-extension.md shows it.',
			$extension,
			self::TEST_FILE,
			GatewayConformanceCase::class
		);
	}

	/**
	 * Returns the PHPUnit command of the integration half.
	 *
	 * @since 0.2.0
	 *
	 * @param string $core      SEOCart's root.
	 * @param string $extension The extension's root; empty for SEOCart's own suite.
	 * @return list<string> The command and its arguments.
	 */
	public static function command( string $core, string $extension ): array {
		return array(
			PHP_BINARY,
			$core . '/vendor/bin/phpunit',
			'--configuration',
			( '' === $extension ? $core : $extension ) . '/phpunit.xml.dist',
			'--testsuite',
			'integration',
			'--bootstrap',
			$core . '/tests/bootstrap-integration.php',
			'--group',
			'conformance',
			'--fail-on-empty-test-suite',
			'--verbose',
		);
	}

	/**
	 * Finds an extension's conformance test: a PHP file under its tests/ that names GatewayConformanceCase.
	 *
	 * @since 0.2.0
	 *
	 * @param string $extension The extension's root.
	 * @return string|null The file; null when there is none.
	 */
	public static function conformanceTestIn( string $extension ): ?string {
		$tests = $extension . '/tests';

		if ( ! is_dir( $tests ) ) {
			return null;
		}

		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tests, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains( (string) file_get_contents( $file->getPathname() ), 'GatewayConformanceCase' ) ) {
				return $file->getPathname();
			}
		}

		return null;
	}
}
