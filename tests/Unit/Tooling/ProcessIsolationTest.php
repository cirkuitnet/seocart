<?php
/**
 * Tests that no test asks PHPUnit to run it in a separate process
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Guards against a test run that never ends.
 *
 * PHPUnit 9 runs an isolated test in a child process and, when the test is over, the child
 * reads whatever is waiting on its own standard output stream. On Linux that read fails at
 * once, because the stream is the write end of a pipe. On FreeBSD a pipe works in both
 * directions, so the read waits for data that never comes and the run hangs without a word.
 * The development server runs FreeBSD; continuous integration runs Linux and would pass.
 * Observed with FreeBSD 14.4, PHP 8.4.23 and PHPUnit 9.6.36.
 *
 * A test that needs a process of its own starts one itself and reads its report from a
 * file, the way IdleBudgetTest runs tests/Support/idle-request-probe.php.
 *
 * The two annotations are never spelled out with their at-sign in this file: PHPUnit reads
 * annotations anywhere in a docblock, prose included, and would isolate this very test.
 *
 * @since 0.1.0
 */
final class ProcessIsolationTest extends TestCase {

	use ReadsTestSources;

	/**
	 * The annotation names that make PHPUnit isolate a test, without the at-sign.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ISOLATING_ANNOTATIONS = array( 'runInSeparateProcess', 'runTestsInSeparateProcesses' );

	/**
	 * Tests that no PHP file in the test directories carries an isolating annotation.
	 *
	 * @since 0.1.0
	 */
	public function test_no_test_is_annotated_to_run_in_a_separate_process(): void {
		$offenders = array();

		foreach ( $this->testSources() as $file => $source ) {
			if ( self::isolates( $source ) ) {
				$offenders[] = $file;
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			'PHPUnit process isolation hangs on FreeBSD, which the development server runs. Start the child process from the test instead, as IdleBudgetTest does.'
		);
	}

	/**
	 * Tests that the PHPUnit configuration does not isolate every test.
	 *
	 * @since 0.1.0
	 */
	public function test_the_configuration_does_not_isolate_every_test(): void {
		$this->assertDoesNotMatchRegularExpression(
			'/processIsolation\s*=\s*"true"/',
			(string) file_get_contents( $this->root() . '/phpunit.xml.dist' ),
			'PHPUnit process isolation hangs on FreeBSD, which the development server runs.'
		);
	}

	/**
	 * Tests the detector on text with a known answer, so that a detector which finds nothing cannot pass for a clean tree.
	 *
	 * @since 0.1.0
	 */
	public function test_the_detector_finds_the_annotations_it_is_shown(): void {
		foreach ( self::ISOLATING_ANNOTATIONS as $annotation ) {
			$this->assertTrue( self::isolates( "<?php\n/**\n * @" . $annotation . "\n */\n" ), $annotation . ' on a line of its own.' );
			$this->assertTrue( self::isolates( "<?php\n/** Prose that mentions @" . $annotation . ' in passing. */' ), $annotation . ' in prose, which PHPUnit honours as well.' );
		}

		$this->assertFalse( self::isolates( "<?php\n/**\n * @group performance\n * Runs in a separate process of its own making.\n */\n" ) );
	}

	/**
	 * Tells whether a PHP source file asks PHPUnit for process isolation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $source The text of a PHP file.
	 * @return bool True when an isolating annotation appears anywhere in the file.
	 */
	private static function isolates( string $source ): bool {
		return 1 === preg_match( '/@(?:' . implode( '|', self::ISOLATING_ANNOTATIONS ) . ')\b/', $source );
	}
}
