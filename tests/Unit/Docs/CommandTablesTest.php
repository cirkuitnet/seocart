<?php
/**
 * Tests that the documentation names exactly the commands that exist
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Docs;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Guards the command names that the hand-written documentation repeats.
 *
 * The manifests, composer.json and package.json, define the scripts. docs/testing.md
 * explains them to a reader: which suite, which group, what each one needs. package.json
 * cannot describe a script at all and composer.json describes only some of them in one
 * line, so the page is written by hand and the list of names is unavoidably written twice.
 * This test owns one fact: every command name in the documentation is a command that
 * exists, and the tables in docs/testing.md name all of them.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class CommandTablesTest extends TestCase {

	/**
	 * Composer event hooks. They are scripts in composer.json but nobody runs them by name.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const COMPOSER_EVENTS = array( 'post-install-cmd', 'post-update-cmd' );

	/**
	 * Commands built into Composer that the documentation names next to the scripts.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const COMPOSER_BUILT_INS = array( 'audit', 'install', 'list', 'validate' );

	/**
	 * Directories whose Markdown files this project does not write.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private const SKIPPED_DIRECTORIES = array( '.git', 'artifacts', 'build', 'dist', 'node_modules', 'vendor', 'vendor-scoped' );

	/**
	 * Returns the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path without a trailing slash.
	 */
	private function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * Returns the command names in the first column of the tables in docs/testing.md.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix The command prefix, for example 'composer' or 'npm run'.
	 * @return string[] The documented names, sorted.
	 */
	private function documented( string $prefix ): array {
		$source = (string) file_get_contents( $this->root() . '/docs/testing.md' );

		preg_match_all( '/^\| `' . preg_quote( $prefix, '/' ) . ' ([a-z][a-z0-9:-]*)[^`]*` +\|/m', $source, $matches );

		$names = $matches[1];
		sort( $names );

		return $names;
	}

	/**
	 * Returns every name that follows a command prefix in any Markdown file of the project.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix The command prefix, for example 'composer' or 'npm run'.
	 * @return array<string, string> The mentioned name => the first file that mentions it.
	 */
	private function mentioned( string $prefix ): array {
		$files = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $this->root(), FilesystemIterator::SKIP_DOTS ),
				static function ( SplFileInfo $file ): bool {
					if ( $file->isDir() ) {
						return ! in_array( $file->getFilename(), self::SKIPPED_DIRECTORIES, true );
					}

					return 'md' === $file->getExtension();
				}
			)
		);

		$mentions = array();

		foreach ( $files as $file ) {
			preg_match_all( '/(?<![\w-])' . preg_quote( $prefix, '/' ) . ' ([a-z][a-z0-9:-]*)/', (string) file_get_contents( $file->getPathname() ), $matches );

			foreach ( $matches[1] as $name ) {
				$mentions[ $name ] ??= substr( $file->getPathname(), strlen( $this->root() ) + 1 );
			}
		}

		return $mentions;
	}

	/**
	 * Returns the script names a manifest defines.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file Manifest file name relative to the repository root.
	 * @return string[] The script names, sorted.
	 */
	private function defined( string $file ): array {
		$manifest = json_decode( (string) file_get_contents( $this->root() . '/' . $file ), true, 512, JSON_THROW_ON_ERROR );
		$names    = array_keys( $manifest['scripts'] );
		sort( $names );

		return $names;
	}

	/**
	 * Tests that the Composer table names every Composer script, and nothing that does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_composer_table_matches_composer_json(): void {
		$expected = array_values( array_diff( $this->defined( 'composer.json' ), self::COMPOSER_EVENTS ) );
		$actual   = array_values( array_diff( $this->documented( 'composer' ), self::COMPOSER_BUILT_INS ) );

		$this->assertSame( $expected, $actual, 'docs/testing.md must list exactly the scripts that composer.json defines.' );
	}

	/**
	 * Tests that the npm table names every npm script, and nothing that does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_npm_table_matches_package_json(): void {
		$this->assertSame(
			$this->defined( 'package.json' ),
			$this->documented( 'npm run' ),
			'docs/testing.md must list exactly the scripts that package.json defines.'
		);
	}

	/**
	 * Tests that no Markdown file names a Composer command that does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_every_composer_command_in_the_documentation_exists(): void {
		$mentioned = $this->mentioned( 'composer' );
		$known     = array_merge( $this->defined( 'composer.json' ), self::COMPOSER_BUILT_INS );

		$this->assertNotEmpty( $mentioned, 'No Markdown file names a Composer command, so the scan is not reading the documentation.' );
		$this->assertSame(
			array(),
			array_diff_key( $mentioned, array_flip( $known ) ),
			'A Markdown file names a Composer command that composer.json does not define (name => first file). Write "Composer" with a capital in prose.'
		);
	}

	/**
	 * Tests that no Markdown file names an npm script that does not exist.
	 *
	 * @since 0.1.0
	 */
	public function test_every_npm_script_in_the_documentation_exists(): void {
		$mentioned = $this->mentioned( 'npm run' );

		$this->assertNotEmpty( $mentioned, 'No Markdown file names an npm script, so the scan is not reading the documentation.' );
		$this->assertSame(
			array(),
			array_diff_key( $mentioned, array_flip( $this->defined( 'package.json' ) ) ),
			'A Markdown file names an npm script that package.json does not define (name => first file).'
		);
	}
}
