<?php
/**
 * RunsScripts: runs the packaging scripts in bin/ as the command line does
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

/**
 * Lets a test case start a script in a process of its own and read its exit code.
 *
 * The exit code is what a CI step acts on, and the scripts write to the standard
 * streams, so the honest test is the command itself and not a call to `main()`.
 *
 * @since 0.1.0
 */
trait RunsScripts {

	/**
	 * Runs a script from bin/ with the PHP binary that runs the tests.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $script    File name of the script in bin/.
	 * @param string[] $arguments Command-line arguments.
	 * @return array{exit: int, stdout: string, stderr: string} What the process returned and wrote.
	 */
	private function runScript( string $script, array $arguments ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/' . $script ), $arguments ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process, "{$script} could not be started." );

		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit'   => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}
}
