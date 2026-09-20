<?php
/**
 * Tests for the .distignore pattern dialect
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Packaging\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\DistIgnore;

/**
 * Pins the dialect that the header comment of .distignore describes.
 *
 * @since 0.1.0
 */
final class DistIgnoreTest extends TestCase {

	/**
	 * Tests that comments and blank lines are skipped and everything else is a pattern.
	 *
	 * @since 0.1.0
	 */
	public function test_comments_and_blank_lines_are_not_patterns(): void {
		$ignore = DistIgnore::fromString( "# A comment.\n\n   \n/docs\n\t#indented\r\n.gitkeep\r\n#/src\n" );

		$this->assertTrue( $ignore->excludes( 'docs' ), 'A pattern after a comment and blank lines must still be read.' );
		$this->assertTrue( $ignore->excludes( 'languages/.gitkeep' ), 'A pattern on a line that ends in CRLF must still be read.' );
		$this->assertFalse( $ignore->excludes( 'src' ), 'A commented-out pattern must not exclude anything.' );
		$this->assertFalse( $ignore->excludes( '#indented' ), 'An indented comment is a comment, not a pattern.' );
		$this->assertFalse( $ignore->excludes( '# A comment.' ) );
	}

	/**
	 * Tests that a file without patterns excludes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_no_patterns_exclude_nothing(): void {
		$this->assertFalse( DistIgnore::fromString( "# Only a comment.\n" )->excludes( 'src/Platform/Kernel/Kernel.php' ) );
	}

	/**
	 * Tests one pattern against one path.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider patternsAndPaths
	 *
	 * @param string $pattern  One pattern.
	 * @param string $path     Plugin-relative path.
	 * @param bool   $excluded Whether the pattern must exclude the path.
	 */
	public function test_pattern_matching( string $pattern, string $path, bool $excluded ): void {
		$this->assertSame( $excluded, DistIgnore::fromString( $pattern )->excludes( $path ) );
	}

	/**
	 * Provides pattern, path and expectation.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public function patternsAndPaths(): array {
		return array(
			'anchored: matches at the root'               => array( '/docs', 'docs', true ),
			'anchored: covers descendants'                => array( '/docs', 'docs/adr/ADR-0001.md', true ),
			'anchored: not beneath vendor-scoped'         => array( '/docs', 'vendor-scoped/acme/lib/docs/Parser.php', false ),
			'anchored: not beneath src'                   => array( '/tests', 'src/Platform/tests/Fixture.php', false ),
			'anchored: not a longer name'                 => array( '/docs', 'docs-site/index.html', false ),
			'anchored: not a name ending the same way'    => array( '/docs', 'mydocs/index.html', false ),
			'anchored: path may carry slashes'            => array( '/docs', '/docs/', true ),
			'anchored, two segments: matches'             => array( '/bin/dev', 'bin/dev/provision-site.sh', true ),
			'anchored, two segments: sibling is kept'     => array( '/bin/dev', 'bin/build-zip.php', false ),
			'anchored, two segments: not at depth'        => array( '/bin/dev', 'src/bin/dev/tool.php', false ),
			'unanchored: matches at the root'             => array( '.gitkeep', '.gitkeep', true ),
			'unanchored: matches at depth'                => array( '.gitkeep', 'src/Platform/Jobs/.gitkeep', true ),
			'unanchored: directory covers descendants'    => array( '.git', 'vendor-scoped/acme/lib/.git/config', true ),
			'unanchored: not a longer name'               => array( '.git', '.github/workflows/ci.yml', false ),
			'unanchored: not the tail of a name'          => array( '.git', 'src/archive.git', false ),
			'star: matches inside one segment'            => array( '/*.dist', 'phpunit.xml.dist', true ),
			'star: anchored pattern stays at the root'    => array( '/*.dist', 'src/phpunit.xml.dist', false ),
			'star: may match nothing'                     => array( '/a*z', 'az', true ),
			'star: may match many characters'             => array( '/a*z', 'abcxyz', true ),
			'star: never crosses a slash'                 => array( '/a*z', 'a/z', false ),
			'star: unanchored'                            => array( '*.log', 'src/Platform/debug.log', true ),
			'star: matches a leading dot'                 => array( '/.*', '.editorconfig', true ),
			'star: root dot-directory covers descendants' => array( '/.*', '.github/workflows/ci.yml', true ),
			'star: /.* leaves nested dotfiles alone'      => array( '/.*', 'src/Platform/.htaccess', false ),
			'literal: a dot is a dot'                     => array( '/a.b', 'axb', false ),
			'literal: other expression characters'        => array( '/c++(1)', 'c++(1)', true ),
			'case: matching is case-sensitive'            => array( 'Thumbs.db', 'thumbs.db', false ),
		);
	}

	/**
	 * Tests that syntax outside the dialect is an error, not a pattern that matches nothing.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unsupportedPatterns
	 *
	 * @param string $pattern A pattern that .gitignore would accept and this dialect does not.
	 */
	public function test_syntax_outside_the_dialect_is_rejected( string $pattern ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '.distignore line 2' );

		DistIgnore::fromString( "/docs\n{$pattern}\n" );
	}

	/**
	 * Provides patterns that must be rejected.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string}>
	 */
	public function unsupportedPatterns(): array {
		return array(
			'negation'         => array( '!/docs/keep.md' ),
			'double star'      => array( 'src/**/tests' ),
			'trailing slash'   => array( '/docs/' ),
			'trailing comment' => array( '/docs # Internal.' ),
			'inner space'      => array( 'My File.txt' ),
			'question mark'    => array( 'file?.txt' ),
			'character class'  => array( '*.[oa]' ),
			'backslash'        => array( 'docs\\adr' ),
			'empty segment'    => array( '/docs//adr' ),
			'unanchored path'  => array( 'bin/dev' ),
		);
	}

	/**
	 * Tests that the refusal of an unanchored path says how to write it.
	 *
	 * @since 0.1.0
	 */
	public function test_unanchored_path_is_told_to_start_with_a_slash(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( '"fixtures/large": a pattern with a `/` inside it is ambiguous without a leading `/`' );

		DistIgnore::fromString( "fixtures/large\n" );
	}

	/**
	 * Tests that an unreadable file is an error.
	 *
	 * @since 0.1.0
	 */
	public function test_missing_file_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		DistIgnore::fromFile( __DIR__ . '/no-such-distignore' );
	}

	/**
	 * Tests what the repository's own .distignore promises in its comments.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider repositoryPaths
	 *
	 * @param string $path     Plugin-relative path.
	 * @param bool   $excluded Whether the repository's .distignore must exclude it.
	 */
	public function test_the_repository_distignore( string $path, bool $excluded ): void {
		$ignore = DistIgnore::fromFile( dirname( __DIR__, 3 ) . '/.distignore' );

		$this->assertSame( $excluded, $ignore->excludes( $path ) );
	}

	/**
	 * Provides path and expectation for the repository's .distignore.
	 *
	 * `false` here means only that no pattern strips the path: the anchored `/tests`,
	 * `/vendor` and `/.*` stay at the root. It does not mean the path may ship. The zip
	 * checker refuses a segment named tests or vendor, and a dotfile, at every depth, so
	 * a nested one that ever appears turns the release red until it gets a pattern of
	 * its own. The matcher is exact and the checker fails closed; the rows marked
	 * "checker refuses" are where the two layers differ on purpose.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function repositoryPaths(): array {
		return array(
			'root dotfile'                          => array( '.editorconfig', true ),
			'root dot-directory'                    => array( '.github/workflows/ci.yml', true ),
			'nested dotfile (checker refuses)'      => array( 'build/.vite-manifest.json', false ),
			'root docs'                             => array( 'docs/adr/ADR-0008.md', true ),
			'docs inside a scoped library'          => array( 'vendor-scoped/acme/lib/docs/Parser.php', false ),
			'root tests'                            => array( 'tests/Unit/Packaging/IgnoreListsTest.php', true ),
			'tests inside src (checker refuses)'    => array( 'src/Platform/tests/Fixture.php', false ),
			'tests inside build (checker refuses)'  => array( 'build/tests/index.js', false ),
			'unscoped vendor'                       => array( 'vendor/autoload.php', true ),
			'scoped vendor ships'                   => array( 'vendor-scoped/autoload.php', false ),
			'nested vendor (checker refuses)'       => array( 'vendor-scoped/acme/lib/vendor/autoload.php', false ),
			'placeholder file at depth'             => array( 'src/Platform/Jobs/.gitkeep', true ),
			'this tool'                             => array( 'tools/Packaging/DistIgnore.php', true ),
			'the build script'                      => array( 'bin/build-zip.php', true ),
			'a development configuration at root'   => array( 'phpunit.xml.dist', true ),
			'the same name inside a scoped library' => array( 'vendor-scoped/acme/lib/phpunit.xml.dist', false ),
		);
	}
}
