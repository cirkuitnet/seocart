<?php
/**
 * Tests that the licence gate fails on a planted incompatible or unlicensed package
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\LicenseCheckCommand;

/**
 * Runs the command against the lockfile fixtures in Fixtures/licenses/.
 *
 * Each fixture directory holds a composer-lock.json, in the format of composer.lock, and an
 * npm-lock.json, in the format of package-lock.json. They do not carry the real file names
 * because dependency scanners read every file with those names as one of the project's
 * lockfiles, and would list the invented packages as its dependencies. `allowed` passes;
 * `gpl2-only` plants a GPL-2.0-only runtime package in each; `no-licence` plants a runtime
 * package without licence information in each. Every fixture also carries a dev-only
 * package under a forbidden licence, which must never be judged.
 *
 * @since 0.1.0
 */
final class LicenseCheckCommandTest extends TestCase {

	/**
	 * The lines the command printed during the current test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	/**
	 * Runs the command with lockfiles taken from the fixture directories.
	 *
	 * @since 0.1.0
	 *
	 * @param string $composer Fixture directory that supplies the Composer lockfile.
	 * @param string $npm      Fixture directory that supplies the npm lockfile.
	 * @return int The exit code.
	 */
	private function runWith( string $composer, string $npm ): int {
		return $this->runArgs(
			array(
				'--composer-lock=' . __DIR__ . "/Fixtures/licenses/{$composer}/composer-lock.json",
				'--package-lock=' . __DIR__ . "/Fixtures/licenses/{$npm}/npm-lock.json",
			)
		);
	}

	/**
	 * Runs the command with raw arguments, recording its output.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Command-line arguments.
	 * @return int The exit code.
	 */
	private function runArgs( array $args ): int {
		$this->lines = array();
		$command     = new LicenseCheckCommand(
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);

		return $command->run( $args, dirname( __DIR__, 3 ) );
	}

	/**
	 * Tests that allowed runtime licences pass and dev-only packages are not judged.
	 *
	 * @since 0.1.0
	 */
	public function test_allowed_licences_pass(): void {
		$this->assertSame( 0, $this->runWith( 'allowed', 'allowed' ), implode( "\n", $this->lines ) );
		$this->assertStringContainsString( '2 Composer and 2 npm runtime package(s)', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a GPL-2.0-only Composer runtime package fails the gate.
	 *
	 * @since 0.1.0
	 */
	public function test_gpl_2_only_composer_package_fails(): void {
		$this->assertSame( 1, $this->runWith( 'gpl2-only', 'allowed' ) );
		$this->assertStringContainsString( '[license-not-allowed] Composer package example/gpl2-only-library 1.2.3 is licensed "GPL-2.0-only"', implode( "\n", $this->lines ) );
		$this->assertStringNotContainsString( 'dev-only-tool', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a GPL-2.0-only npm production package fails the gate.
	 *
	 * @since 0.1.0
	 */
	public function test_gpl_2_only_npm_package_fails(): void {
		$this->assertSame( 1, $this->runWith( 'allowed', 'gpl2-only' ) );
		$this->assertStringContainsString( '[license-not-allowed] npm package gpl2-only-widget 3.2.1 is licensed "GPL-2.0-only"', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a runtime package without licence information fails the gate.
	 *
	 * @since 0.1.0
	 */
	public function test_package_without_licence_fails(): void {
		$this->assertSame( 1, $this->runWith( 'no-licence', 'no-licence' ) );

		$output = implode( "\n", $this->lines );

		$this->assertStringContainsString( '[license-missing] Composer package example/unlicensed-library 0.4.0', $output );
		$this->assertStringContainsString( '[license-missing] npm package unlicensed-widget 0.0.7', $output );
	}

	/**
	 * Tests that the command refuses to run on input it cannot judge.
	 *
	 * @since 0.1.0
	 */
	public function test_unusable_input_is_an_error(): void {
		$this->assertSame( 2, $this->runArgs( array( '--composer-lok=composer.lock' ) ), 'An unknown option must not be ignored.' );
		$this->assertSame( 2, $this->runArgs( array( '--composer-lock=' . __DIR__ . '/Fixtures/licenses/does-not-exist.lock' ) ), 'A missing lockfile must not pass.' );
		$this->assertSame( 2, $this->runArgs( array( '--composer-lock=' . __DIR__ . '/Fixtures/readme/valid.txt' ) ), 'A file that is not a lockfile must not pass.' );
	}

	/**
	 * Tests that the repository's own lockfiles pass.
	 *
	 * @since 0.1.0
	 */
	public function test_the_real_lockfiles_pass(): void {
		$this->assertSame( 0, $this->runArgs( array() ), implode( "\n", $this->lines ) );
	}
}
