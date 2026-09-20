<?php
/**
 * Tests that the hand-maintained lists of what ships and what does not agree with each other
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Packaging;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\DistIgnore;
use SEOCart\Tools\Packaging\PluginPackage;
use SEOCart\Tools\Packaging\ZipBuilder;

/**
 * Guards the parallel lists that git, the release zip and the zip checker each need.
 *
 * .gitattributes, .gitignore and .distignore are read by different tools and cannot
 * include one another, and the header of .distignore tells its readers in prose what
 * the zip checker enforces in code. This test owns one fact: those lists agree. Every
 * question about .distignore is put to DistIgnore, the one implementation of its dialect.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class IgnoreListsTest extends TestCase {

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
	 * Returns the matcher for the repository's .distignore.
	 *
	 * @since 0.1.0
	 *
	 * @return DistIgnore The matcher.
	 */
	private function distIgnore(): DistIgnore {
		return DistIgnore::fromFile( $this->root() . '/.distignore' );
	}

	/**
	 * Reads the lines of a git pattern file that are neither blank nor comments.
	 *
	 * @since 0.1.0
	 *
	 * @param string $file File name relative to the repository root.
	 * @return list<string> Trimmed lines.
	 */
	private function lines( string $file ): array {
		$lines = array();

		foreach ( explode( "\n", (string) file_get_contents( $this->root() . '/' . $file ) ) as $line ) {
			$line = trim( $line );

			if ( '' !== $line && '#' !== $line[0] ) {
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Turns a git pattern into concrete paths it matches.
	 *
	 * Git anchors a pattern that has a slash at its start or in its middle; any other
	 * pattern matches at every depth, so it is tried at the root and deep inside src/.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern A pattern from .gitignore or .gitattributes, without a leading `!`.
	 * @return list<string> Plugin-relative paths.
	 */
	private function samplePaths( string $pattern ): array {
		$path = str_replace( '*', 'x', trim( $pattern, '/' ) );

		if ( str_contains( rtrim( $pattern, '/' ), '/' ) ) {
			return array( $path );
		}

		return array( $path, 'src/Platform/Deep/' . $path );
	}

	/**
	 * Tests that whatever `git archive` leaves out, the release zip leaves out too.
	 *
	 * @since 0.1.0
	 */
	public function test_every_export_ignored_path_is_excluded_from_the_zip(): void {
		$ignore  = $this->distIgnore();
		$checked = 0;

		foreach ( $this->lines( '.gitattributes' ) as $line ) {
			$fields = preg_split( '/\s+/', $line );

			if ( ! is_array( $fields ) || ! in_array( 'export-ignore', array_slice( $fields, 1 ), true ) ) {
				continue;
			}

			foreach ( $this->samplePaths( $fields[0] ) as $path ) {
				$this->assertTrue( $ignore->excludes( $path ), ".gitattributes marks {$fields[0]} export-ignore, but .distignore does not exclude {$path}." );
				++$checked;
			}
		}

		$this->assertGreaterThan( 0, $checked, 'No export-ignore line was found in .gitattributes, so nothing was compared.' );
	}

	/**
	 * Tests that whatever git ignores stays out of the zip, except the built directories that ship.
	 *
	 * @since 0.1.0
	 */
	public function test_every_git_ignored_path_is_excluded_from_the_zip_unless_it_is_built_to_ship(): void {
		$ignore = $this->distIgnore();
		$built  = array_keys( ZipBuilder::BUILT_DIRECTORIES );
		$seen   = array();

		foreach ( $this->lines( '.gitignore' ) as $pattern ) {
			// A negated pattern names a file that is tracked after all; it is not an ignored path.
			if ( '!' === $pattern[0] ) {
				continue;
			}

			foreach ( $this->samplePaths( $pattern ) as $path ) {
				if ( in_array( $path, $built, true ) ) {
					$this->assertFalse( $ignore->excludes( $path ), "{$path}/ is built to ship, but .distignore excludes it." );
					$seen[] = $path;
					continue;
				}

				$this->assertTrue( $ignore->excludes( $path ), ".gitignore ignores {$pattern}, but .distignore does not exclude {$path}." );
			}
		}

		$this->assertSame( $built, array_values( array_intersect( $built, $seen ) ), 'Every directory that is built to ship must be git-ignored: it is never committed.' );
	}

	/**
	 * Tests that nothing on the allow-list is excluded, which would make a required entry unshippable.
	 *
	 * @since 0.1.0
	 */
	public function test_no_allow_listed_entry_is_excluded_from_the_zip(): void {
		$ignore = $this->distIgnore();

		foreach ( array_keys( PluginPackage::TOP_LEVEL ) as $entry ) {
			$this->assertFalse( $ignore->excludes( $entry ), "{$entry} is on the allow-list of the zip checker, but .distignore excludes it." );
		}
	}

	/**
	 * Tests that every entry at the repository root is either excluded or on the allow-list.
	 *
	 * The zip checker would refuse the zip anyway. This test says so when the file is
	 * added, not when a release is cut.
	 *
	 * @since 0.1.0
	 */
	public function test_every_entry_at_the_repository_root_is_excluded_or_allow_listed(): void {
		$ignore = $this->distIgnore();
		$names  = scandir( $this->root() );

		$this->assertIsArray( $names );

		foreach ( array_diff( $names, array( '.', '..' ) ) as $name ) {
			if ( $ignore->excludes( $name ) ) {
				continue;
			}

			$entry = is_dir( $this->root() . '/' . $name ) ? $name . '/' : $name;

			$this->assertArrayHasKey(
				$entry,
				PluginPackage::TOP_LEVEL,
				"{$entry} would ship, but it is not on the allow-list. Exclude it in .distignore; if it has to ship, add it to PluginPackage::TOP_LEVEL and to the header of .distignore."
			);
		}
	}

	/**
	 * Tests that the sentence in the header of .distignore lists exactly the allow-list.
	 *
	 * @since 0.1.0
	 */
	public function test_distignore_header_states_the_allow_list(): void {
		$comment = '';

		foreach ( explode( "\n", (string) file_get_contents( $this->root() . '/.distignore' ) ) as $line ) {
			if ( str_starts_with( $line, '#' ) ) {
				$comment .= ' ' . trim( substr( $line, 1 ) );
			}
		}

		$this->assertSame(
			1,
			preg_match( '/The zip ships:(.+?)\.\s+The packaging check/', $comment, $matches ),
			'The header of .distignore must keep the sentence "The zip ships: <list>." followed by "The packaging check ...".'
		);

		// Drop the notes in parentheses, then read the list: items separated by commas and a final "and".
		$items = preg_replace( '/\([^)]*\)/', '', $matches[1] );
		$items = array_map( 'trim', explode( ',', str_replace( ' and ', ',', (string) $items ) ) );

		$expected = array_keys( PluginPackage::TOP_LEVEL );

		sort( $items );
		sort( $expected );

		$this->assertSame( $expected, $items, 'The header of .distignore and PluginPackage::TOP_LEVEL must list the same entries.' );
	}
}
