<?php
/**
 * Tests the exit codes and output of the readme check command
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\WpOrg\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\WpOrg\ReadmeCheckCommand;

/**
 * Covers what the rules' own tests cannot: reading the files, the `--tag` option and the exit code.
 *
 * @since 0.1.0
 */
final class ReadmeCheckCommandTest extends TestCase {

	/**
	 * The lines the command printed during the current test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	/**
	 * Runs the command against a readme fixture, the fixture main file and the fixture manifest.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $fixture Readme fixture name without the extension.
	 * @param string[] $extra   Optional. Further command-line arguments; a later one replaces an
	 *                          earlier one of the same name. Default none.
	 * @return int The exit code.
	 */
	private function runWith( string $fixture, array $extra = array() ): int {
		$this->lines = array();
		$command     = new ReadmeCheckCommand(
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);

		$args = array(
			'--readme=' . __DIR__ . "/Fixtures/readme/{$fixture}.txt",
			'--plugin-file=' . __DIR__ . '/Fixtures/plugin-header.txt',
			'--composer-json=' . __DIR__ . '/Fixtures/source-manifest.json',
		);

		return $command->run( array_merge( $args, $extra ), dirname( __DIR__, 3 ) );
	}

	/**
	 * Tests that a valid readme exits zero, with and without a matching tag.
	 *
	 * @since 0.1.0
	 */
	public function test_valid_readme_passes(): void {
		$this->assertSame( 0, $this->runWith( 'valid' ), implode( "\n", $this->lines ) );
		$this->assertSame( 0, $this->runWith( 'valid', array( '--tag=v0.1.0' ) ), implode( "\n", $this->lines ) );
		$this->assertStringContainsString( 'git tag v0.1.0 agree', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a broken rule exits one and names the rule.
	 *
	 * @since 0.1.0
	 */
	public function test_violation_fails_and_is_named(): void {
		$this->assertSame( 1, $this->runWith( 'stable-tag-trunk' ) );
		$this->assertStringContainsString( '[stable-tag-trunk]', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a git tag other than the plugin version exits one.
	 *
	 * @since 0.1.0
	 */
	public function test_disagreeing_git_tag_fails(): void {
		$this->assertSame( 1, $this->runWith( 'valid', array( '--tag=v9.9.9' ) ) );
		$this->assertStringContainsString( '[git-tag-mismatch]', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that the command refuses to run on input it cannot judge.
	 *
	 * @since 0.1.0
	 */
	public function test_unusable_input_is_an_error(): void {
		$this->assertSame( 2, $this->runWith( 'valid', array( '--tga=v0.1.0' ) ), 'An unknown option must not be ignored.' );
		$this->assertSame( 2, $this->runWith( 'valid', array( '--tag=' ) ), 'An empty option must not be ignored.' );
		$this->assertSame( 2, $this->runWith( 'does-not-exist' ), 'A missing readme must not pass.' );
	}

	/**
	 * Tests that the command refuses to run when composer.json does not declare the source repository.
	 *
	 * @since 0.1.0
	 */
	public function test_manifest_without_a_source_repository_is_an_error(): void {
		$this->assertSame( 2, $this->runWith( 'valid', array( '--composer-json=' . __DIR__ . '/Fixtures/licenses/allowed/npm-lock.json' ) ), 'A manifest without support.source must not pass.' );
		$this->assertStringContainsString( 'support.source', implode( "\n", $this->lines ) );

		$this->assertSame( 2, $this->runWith( 'valid', array( '--composer-json=' . __DIR__ . '/Fixtures/readme/valid.txt' ) ), 'A manifest that is not JSON must not pass.' );
		$this->assertSame( 2, $this->runWith( 'valid', array( '--composer-json=' . __DIR__ . '/Fixtures/does-not-exist.json' ) ), 'A missing manifest must not pass.' );
	}
}
