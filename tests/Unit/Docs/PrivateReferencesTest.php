<?php
/**
 * Tests that no tracked file cites the maintainers' private design and planning material
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the public repository self-contained.
 *
 * The maintainers keep their design records, architecture notes, planning documents and
 * agent instructions outside the repository. A tracked file that cites one of them by name,
 * number or planning identifier points readers at something they cannot open, so every
 * comment, docblock, test and document says what the rule is instead, or names the public
 * file or test that enforces it. This test owns one fact: which spellings count as such a
 * citation. The two ignore files are exempt, because they must name the paths they ignore.
 *
 * @since 0.1.0
 */
final class PrivateReferencesTest extends TestCase {

	/**
	 * What a citation of private material looks like, each with the reason it is one.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string> Regular expression => what it catches.
	 */
	private const CITATIONS = array(
		'/\bADR-\d{4}\b/'                                 => 'a design-record number',
		'#docs/(?:adr|architecture|phase-)#'              => 'a path of the private documentation',
		'/\btarget[- ]architecture\b/i'                   => 'the private architecture document',
		'/(?<![A-Za-z])(?:data-storage|security|extensibility|performance|domain-map|overview)\.md\b/' => 'a private architecture note (SECURITY.md, in capitals, is public)',
		'#\bresearch/\d{2}\b#'                            => 'a private research note',
		'/\b(?:open-questions|phase-1-backlog|repo-ci-worktree-strategy|test-strategy|wordpress-org-compliance|capability-inventory|SEOCart_Phase\d)\b/' => 'a private planning document',
		'/\bF-(?:SUP|DB|REG|OPS|AUT|SET|EVT|JOB|LOG|KRN|RST)\b/' => 'a planning task identifier',
		'/\b(?:A-0[1-9]|B-0[1-9]|B-S\d{1,2}|X-0[1-9])\b/' => 'a planning task identifier',
		'/\b(?:Wave \d|Slice [AB]|Checkpoint F|Gate [AB])\b/' => 'a planning phase',
		'/\b(?:AGENTS|CLAUDE)\.md\b/'                     => 'a maintainer-only instruction file',
	);

	/**
	 * Tracked files allowed to name private paths, relative to the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const EXEMPT = array(
		'.distignore',
		'.gitignore',
		'tests/Unit/Docs/PrivateReferencesTest.php',
	);

	/**
	 * Tests that no tracked text file cites private material.
	 *
	 * @since 0.1.0
	 */
	public function test_no_tracked_file_cites_private_material(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();

		foreach ( $this->trackedFiles( $root ) as $file ) {
			if ( in_array( $file, self::EXEMPT, true ) || str_starts_with( $file, 'vendor-scoped/' ) ) {
				continue;
			}

			$content = file_get_contents( $root . '/' . $file );

			// Binary files (images, fonts) cannot hold a citation a reader would follow.
			if ( false === $content || str_contains( $content, "\0" ) ) {
				continue;
			}

			foreach ( explode( "\n", $content ) as $index => $line ) {
				foreach ( $this->citationsIn( $line ) as $match => $what ) {
					$found[] = sprintf( '%s:%d cites %s ("%s")', $file, $index + 1, $what, $match );
				}
			}
		}

		$this->assertSame(
			array(),
			$found,
			"These tracked files cite material the maintainers keep private. Say what the rule is instead, or name the public file or test that enforces it:\n" . implode( "\n", $found )
		);
	}

	/**
	 * Tests that each spelling is recognised, and that ordinary text is not.
	 *
	 * @since 0.1.0
	 */
	public function test_the_citations_are_recognised_and_ordinary_text_is_not(): void {
		$citations = array(
			'rounding follows ADR-0004',
			'see docs/adr/README.md',
			'as the target architecture says',
			'the Target-Architecture rule',
			'data-storage.md §5.1',
			'security.md §4.2',
			'research/13 §9',
			'listed in phase-1-backlog',
			'the test-strategy checklist',
			'built by F-SUP',
			'consumed by A-05 and B-S7',
			'arrives in Wave 2',
			'before Slice B',
			'at Checkpoint F',
			'read AGENTS.md first',
		);

		foreach ( $citations as $text ) {
			$this->assertNotSame( array(), $this->citationsIn( $text ), "Not recognised as a citation: $text" );
		}

		$ordinary = array(
			'Report vulnerabilities as SECURITY.md describes.',
			'UTF-8 on PHP 8.4 and WordPress 7.1',
			'a B-tree index; ISO 3166-1 alpha-2',
			'the performance budget and the security model',
			'A-1 grade; F-35',
			'docs/development.md and docs/testing.md',
		);

		foreach ( $ordinary as $text ) {
			$this->assertSame( array(), $this->citationsIn( $text ), "Wrongly read as a citation: $text" );
		}
	}

	/**
	 * Finds the citations of private material in one line of text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $line The text.
	 * @return array<string, string> Matched text => what it cites.
	 */
	private function citationsIn( string $line ): array {
		$found = array();

		foreach ( self::CITATIONS as $pattern => $what ) {
			if ( 1 === preg_match( $pattern, $line, $match ) ) {
				$found[ $match[0] ] = $what;
			}
		}

		return $found;
	}

	/**
	 * Lists the files git tracks, relative to the repository root.
	 *
	 * An untracked file (a local configuration, a maintainer's own notes) is nobody else's
	 * business, so the list comes from git, not from the file system. Without git the test
	 * fails rather than passing on an empty list.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root The repository root.
	 * @return list<string> Paths with forward slashes.
	 */
	private function trackedFiles( string $root ): array {
		$pipes   = array();
		$process = proc_open(
			array( 'git', '-C', $root, 'ls-files', '-z' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process, 'git could not be started, so the tracked files could not be listed.' );

		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );

		$this->assertSame( 0, $status, 'git ls-files failed: ' . $errors );

		$files = array_values(
			array_filter(
				explode( "\0", $output ),
				static fn ( string $file ): bool => '' !== $file
			)
		);
		$this->assertNotSame( array(), $files, 'git listed no tracked files, so nothing would have been checked.' );

		return $files;
	}
}
