<?php
/**
 * Tests that the docs generator works on an extension that has no docs directory yet
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\GatewayMatrixPage;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * Runs `php bin/generate-docs.php --plugin=<root>` in a process of its own, against a
 * generated extension whose docs directory has been removed: the state of a repository
 * on its first run, before anything has written a page.
 *
 * @since 0.2.0
 */
final class PluginDocsTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The root of the generated extension, without its docs directory.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $extension = '';

	/**
	 * Generates an extension and removes the directory its capabilities page was written into.
	 *
	 * @since 0.2.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$core            = dirname( __DIR__, 3 );
		$this->extension = $this->directory . '/seocart-gateway-for-example';

		list( $code, $output ) = self::runPhp( array( $core . '/tools/extension.php', 'new', $core, 'seocart-gateway-for-example', '--type=payments', '--label=Example', '--dir=' . $this->extension ) );

		$this->assertSame( 0, $code, $output );

		unlink( $this->extension . '/' . GatewayMatrixPage::PLUGIN_TARGET );
		rmdir( dirname( $this->extension . '/' . GatewayMatrixPage::PLUGIN_TARGET ) );
	}

	/**
	 * Tests that a run in write mode makes the docs directory and writes the page into it.
	 *
	 * @since 0.2.0
	 */
	public function test_generate_makes_the_docs_directory_of_a_new_extension(): void {
		list( $code, $output ) = self::runPhp( array( dirname( __DIR__, 3 ) . '/bin/generate-docs.php', '--plugin=' . $this->extension ) );

		$this->assertSame( 0, $code, $output );
		$this->assertStringContainsString( 'wrote  ' . GatewayMatrixPage::PLUGIN_TARGET, $output );
		$this->assertFileExists( $this->extension . '/' . GatewayMatrixPage::PLUGIN_TARGET );

		list( $code, $output ) = self::runPhp( array( dirname( __DIR__, 3 ) . '/bin/generate-docs.php', '--check', '--plugin=' . $this->extension ) );

		$this->assertSame( 0, $code, $output );
	}

	/**
	 * Tests that a check of an extension without the page reports drift and creates nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_check_reports_drift_and_creates_no_directory(): void {
		list( $code, $output ) = self::runPhp( array( dirname( __DIR__, 3 ) . '/bin/generate-docs.php', '--check', '--plugin=' . $this->extension ) );

		$this->assertNotSame( 0, $code, $output );
		$this->assertStringContainsString( 'DRIFT  ' . GatewayMatrixPage::PLUGIN_TARGET, $output );
		$this->assertDirectoryDoesNotExist( dirname( $this->extension . '/' . GatewayMatrixPage::PLUGIN_TARGET ) );
	}

	/**
	 * Runs PHP on a script in a new process.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $arguments The script and its arguments.
	 * @return array{0: int, 1: string} The exit code, and what the process printed to its output and its error output.
	 */
	private static function runPhp( array $arguments ): array {
		$process = proc_open(
			array_merge( array( PHP_BINARY ), $arguments ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			return array( -1, 'php could not be started.' );
		}

		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array( proc_close( $process ), $output );
	}
}
