<?php
/**
 * Tests for the release zip builder
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SEOCart\Tools\Packaging\DistIgnore;
use SEOCart\Tools\Packaging\PluginPackage;
use SEOCart\Tools\Packaging\ZipBuilder;
use ZipArchive;

/**
 * Builds zips from a small fixture tree and inspects them.
 *
 * @since 0.1.0
 */
final class ZipBuilderTest extends TestCase {

	use RunsScripts;
	use TemporaryDirectory;

	/**
	 * The patterns the fixture tree is built with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PATTERNS = "# Fixture.\n.gitkeep\n/dist\n/docs\n/tests\n/vendor\n";

	/**
	 * An entry timestamp with an even number of seconds, which a zip stores exactly: 2026-01-01T00:00:00Z.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const TIMESTAMP = 1767225600;

	/**
	 * The environment variables a test may change, with the values to restore.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string|false>
	 */
	private array $environment = array();

	/**
	 * Remembers the environment variables the tests change.
	 *
	 * @since 0.1.0
	 *
	 * @before
	 */
	protected function rememberEnvironment(): void {
		foreach ( array( 'TZ', 'SOURCE_DATE_EPOCH' ) as $name ) {
			$this->environment[ $name ] = getenv( $name );
		}
	}

	/**
	 * Restores the environment variables the tests change.
	 *
	 * @since 0.1.0
	 *
	 * @after
	 */
	protected function restoreEnvironment(): void {
		foreach ( $this->environment as $name => $value ) {
			putenv( false === $value ? $name : $name . '=' . $value );
		}
	}

	/**
	 * Writes the fixture tree: a plugin root that is ready to be released.
	 *
	 * @since 0.1.0
	 *
	 * @return string Absolute path of the plugin root.
	 */
	private function tree(): string {
		$this->writeFile( 'tree/seocart.php', "<?php\n/**\n * Plugin Name: Fixture\n * Version: 1.2.3\n */\n" );
		$this->writeFile( 'tree/uninstall.php', "<?php\n" );
		$this->writeFile( 'tree/composer.json', "{\"name\":\"cirkuitnet/seocart\"}\n" );
		$this->writeFile( 'tree/src/Zeta.php', "<?php\n// Zeta.\n" );
		$this->writeFile( 'tree/src/Alpha/Beta.php', "<?php\n// Beta.\n" );
		$this->writeFile( 'tree/src/docs/Kept.php', "<?php\n// A directory named docs beneath src is not the root docs directory.\n" );
		$this->writeFile( 'tree/docs/reference/Skipped.md', "# Skipped\n" );
		$this->writeFile( 'tree/tests/Unit/SkippedTest.php', "<?php\n" );
		$this->writeFile( 'tree/languages/.gitkeep', '' );
		$this->writeFile( 'tree/vendor/autoload.php', "<?php\n" );
		$this->writeFile( 'tree/vendor-scoped/autoload.php', "<?php\n" );

		mkdir( $this->directory . '/tree/build' );

		return $this->directory . '/tree';
	}

	/**
	 * Builds the fixture tree into a fresh output directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root      Plugin root.
	 * @param string $output    Name of the output directory beneath the scratch directory.
	 * @param int    $timestamp Entry timestamp.
	 * @return array{path: string, files: int, bytes: int, sha256: string} What was written.
	 */
	private function build( string $root, string $output, int $timestamp = self::TIMESTAMP ): array {
		$builder = new ZipBuilder( $root, DistIgnore::fromString( self::PATTERNS ), $timestamp );

		return $builder->build( $this->directory . '/' . $output );
	}

	/**
	 * Lists the entry names of a zip in stored order.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path of the zip.
	 * @return list<string> Entry names.
	 */
	private function entryNames( string $path ): array {
		$zip = new ZipArchive();

		$this->assertTrue( $zip->open( $path, ZipArchive::RDONLY ) );

		$names = array();

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$names[] = (string) $zip->getNameIndex( $index );
		}

		$zip->close();

		return $names;
	}

	/**
	 * Tests that a tree without a built directory is refused, and that the refusal says how to build it.
	 *
	 * The refusal must also take away what an earlier build left, or bin/check-zip.php
	 * would pass a zip that this run never wrote.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider builtDirectories
	 *
	 * @param string $directory A directory that is built, not committed.
	 * @param string $remedy    How it is produced.
	 */
	public function test_refuses_to_build_without_a_built_directory( string $directory, string $remedy ): void {
		$root = $this->tree();

		$this->build( $root, 'dist' );
		$this->assertFileExists( $this->directory . '/dist/seocart-1.2.3.zip' );
		$this->assertFileExists( $this->directory . '/dist/SHA256SUMS' );

		if ( is_file( $root . '/' . $directory . '/autoload.php' ) ) {
			unlink( $root . '/' . $directory . '/autoload.php' );
		}

		rmdir( $root . '/' . $directory );

		$refusal = '';

		try {
			$this->build( $root, 'dist' );
		} catch ( RuntimeException $error ) {
			$refusal = $error->getMessage();
		}

		$this->assertStringContainsString( "{$directory}/ is missing", $refusal );
		$this->assertStringContainsString( $remedy, $refusal );
		$this->assertFileDoesNotExist( $this->directory . '/dist/seocart-1.2.3.zip' );
		$this->assertFileDoesNotExist( $this->directory . '/dist/SHA256SUMS' );
	}

	/**
	 * Provides the built directories and how each is produced.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string}>
	 */
	public function builtDirectories(): array {
		$cases = array();

		foreach ( ZipBuilder::BUILT_DIRECTORIES as $directory => $remedy ) {
			$cases[ $directory ] = array( $directory, $remedy );
		}

		return $cases;
	}

	/**
	 * Tests that every built directory is on the allow-list, since refusing to build without it means it ships.
	 *
	 * @since 0.1.0
	 */
	public function test_built_directories_are_allowed_to_ship(): void {
		foreach ( array_keys( ZipBuilder::BUILT_DIRECTORIES ) as $directory ) {
			$this->assertArrayHasKey( $directory . '/', PluginPackage::TOP_LEVEL );
		}
	}

	/**
	 * Tests the name, the single top-level folder, the order and the exclusions.
	 *
	 * @since 0.1.0
	 */
	public function test_zip_holds_the_files_that_ship_in_one_folder_in_byte_order(): void {
		$result = $this->build( $this->tree(), 'dist' );

		$this->assertSame( $this->directory . '/dist/seocart-1.2.3.zip', $result['path'] );
		$this->assertSame(
			array(
				'seocart/composer.json',
				'seocart/seocart.php',
				'seocart/src/Alpha/Beta.php',
				'seocart/src/Zeta.php',
				'seocart/src/docs/Kept.php',
				'seocart/uninstall.php',
				'seocart/vendor-scoped/autoload.php',
			),
			$this->entryNames( $result['path'] )
		);
		$this->assertSame( 7, $result['files'] );
		$this->assertSame( filesize( $result['path'] ), $result['bytes'] );
	}

	/**
	 * Tests that the checksum file names the zip in the format `sha256sum --check` reads.
	 *
	 * @since 0.1.0
	 */
	public function test_writes_the_checksum_file(): void {
		$result = $this->build( $this->tree(), 'dist' );

		$this->assertSame( hash_file( 'sha256', $result['path'] ), $result['sha256'] );
		$this->assertSame(
			$result['sha256'] . "  seocart-1.2.3.zip\n",
			file_get_contents( $this->directory . '/dist/SHA256SUMS' )
		);
	}

	/**
	 * Tests that file times, file modes and the time zone of the machine never reach the zip.
	 *
	 * @since 0.1.0
	 */
	public function test_rebuild_is_byte_identical(): void {
		$root = $this->tree();

		putenv( 'TZ=UTC' );
		$first = $this->build( $root, 'first' );

		touch( $root . '/seocart.php', 1000000000 );
		touch( $root . '/src/Zeta.php', 2000000000 );
		chmod( $root . '/src/Alpha/Beta.php', 0755 );
		putenv( 'TZ=America/New_York' );

		$second = $this->build( $root, 'second' );

		$this->assertSame( $first['sha256'], $second['sha256'] );
		$this->assertSame( 'America/New_York', getenv( 'TZ' ), 'The builder must put the time zone back.' );
	}

	/**
	 * Tests that every entry carries the given time and the mode of a plain file.
	 *
	 * @since 0.1.0
	 */
	public function test_entries_carry_the_given_time_and_a_fixed_mode(): void {
		$root = $this->tree();

		chmod( $root . '/uninstall.php', 0700 );

		$result = $this->build( $root, 'dist' );

		// The reader converts the stored local time back with the C library, so it needs the zone the builder wrote in.
		putenv( 'TZ=UTC' );

		$zip = new ZipArchive();
		$zip->open( $result['path'], ZipArchive::RDONLY );

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$system     = 0;
			$attributes = 0;
			$stat       = $zip->statIndex( $index );

			$this->assertIsArray( $stat );
			$this->assertSame( self::TIMESTAMP, $stat['mtime'], $stat['name'] );
			$this->assertTrue( $zip->getExternalAttributesIndex( $index, $system, $attributes ) );
			$this->assertSame( ZipArchive::OPSYS_UNIX, $system, $stat['name'] );
			$this->assertSame( 0100644, $attributes >> 16, $stat['name'] );
		}

		$zip->close();
	}

	/**
	 * Tests that the timestamp is really what is stored, and that a time before 1980 is raised to 1980.
	 *
	 * @since 0.1.0
	 */
	public function test_timestamp_decides_the_bytes(): void {
		$root = $this->tree();

		$this->assertNotSame(
			$this->build( $root, 'now' )['sha256'],
			$this->build( $root, 'later', self::TIMESTAMP + 86400 )['sha256']
		);
		$this->assertSame(
			$this->build( $root, 'epoch', 0 )['sha256'],
			$this->build( $root, 'earliest', 315532800 )['sha256']
		);
	}

	/**
	 * Tests that a symbolic link that would ship stops the build.
	 *
	 * @since 0.1.0
	 */
	public function test_symbolic_link_that_would_ship_stops_the_build(): void {
		$root    = $this->tree();
		$outside = $this->writeFile( 'outside/secret.txt', "Not part of the plugin.\n" );

		if ( ! function_exists( 'symlink' ) || ! symlink( $outside, $root . '/src/Secret.php' ) ) {
			$this->markTestSkipped( 'This platform cannot create symbolic links.' );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'src/Secret.php is a symbolic link' );

		$this->build( $root, 'dist' );
	}

	/**
	 * Tests that a linked directory that would ship stops the build instead of being walked.
	 *
	 * @since 0.1.0
	 */
	public function test_linked_directory_that_would_ship_stops_the_build(): void {
		$root = $this->tree();

		$this->writeFile( 'outside/Library/Code.php', "<?php\n" );

		if ( ! function_exists( 'symlink' ) || ! symlink( $this->directory . '/outside/Library', $root . '/src/Library' ) ) {
			$this->markTestSkipped( 'This platform cannot create symbolic links.' );
		}

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'src/Library is a symbolic link' );

		$this->build( $root, 'dist' );
	}

	/**
	 * Tests that a symbolic link beneath an excluded directory is never looked at.
	 *
	 * @since 0.1.0
	 */
	public function test_symbolic_link_beneath_an_excluded_path_is_ignored(): void {
		$root    = $this->tree();
		$outside = $this->writeFile( 'outside/tool', "#!/bin/sh\n" );

		mkdir( $root . '/vendor/bin' );

		if ( ! function_exists( 'symlink' ) || ! symlink( $outside, $root . '/vendor/bin/tool' ) ) {
			$this->markTestSkipped( 'This platform cannot create symbolic links.' );
		}

		$names = $this->entryNames( $this->build( $root, 'dist' )['path'] );

		$this->assertCount( 7, $names );
		$this->assertNotContains( 'seocart/vendor/bin/tool', $names );
	}

	/**
	 * Tests that a tree without the main plugin file is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_refuses_a_tree_without_a_version_header(): void {
		$root = $this->tree();

		$this->writeFile( 'tree/seocart.php', "<?php\n// No header.\n" );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no Version header' );

		$this->build( $root, 'dist' );
	}

	/**
	 * Tests that SOURCE_DATE_EPOCH decides the timestamp when it is set.
	 *
	 * @since 0.1.0
	 */
	public function test_source_date_epoch_decides_the_timestamp(): void {
		putenv( 'SOURCE_DATE_EPOCH=1767225600' );

		$this->assertSame( 1767225600, ZipBuilder::resolveTimestamp( $this->directory ) );
	}

	/**
	 * Tests that a malformed SOURCE_DATE_EPOCH is an error instead of being ignored.
	 *
	 * @since 0.1.0
	 */
	public function test_malformed_source_date_epoch_is_rejected(): void {
		putenv( 'SOURCE_DATE_EPOCH=yesterday' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'SOURCE_DATE_EPOCH must be a Unix timestamp' );

		ZipBuilder::resolveTimestamp( $this->directory );
	}

	/**
	 * Tests that the HEAD commit decides the timestamp when SOURCE_DATE_EPOCH is not set.
	 *
	 * @since 0.1.0
	 */
	public function test_head_commit_decides_the_timestamp_without_source_date_epoch(): void {
		putenv( 'SOURCE_DATE_EPOCH' );

		$root = dirname( __DIR__, 3 );

		if ( ! file_exists( $root . '/.git' ) ) {
			$this->markTestSkipped( 'Not a git checkout.' );
		}

		$this->assertGreaterThan( 1767225600, ZipBuilder::resolveTimestamp( $root ) );
	}

	/**
	 * Tests that there is no fallback to the current time outside a git checkout.
	 *
	 * @since 0.1.0
	 */
	public function test_no_timestamp_source_is_an_error(): void {
		putenv( 'SOURCE_DATE_EPOCH' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'set SOURCE_DATE_EPOCH' );

		ZipBuilder::resolveTimestamp( $this->directory );
	}

	/**
	 * Tests that bin/build-zip.php takes no arguments: one it does not know is a usage error, not something to ignore.
	 *
	 * @since 0.1.0
	 */
	public function test_command_line_refuses_an_argument_it_does_not_know(): void {
		$result = $this->runScript( 'build-zip.php', array( '--output=elsewhere' ) );

		$this->assertSame( 2, $result['exit'], $result['stderr'] );
		$this->assertStringContainsString( 'build-zip: unrecognized argument "--output=elsewhere"', $result['stderr'] );
		$this->assertStringContainsString( 'Usage: php bin/build-zip.php', $result['stderr'] );
		$this->assertSame( '', $result['stdout'] );

		$help = $this->runScript( 'build-zip.php', array( '--help' ) );

		$this->assertSame( 0, $help['exit'], $help['stderr'] );
		$this->assertStringContainsString( 'Usage: php bin/build-zip.php', $help['stdout'] );
	}
}
