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
 * citation. The patterns of the two ignore files are exempt, because they must name the
 * paths they ignore; their comments are not.
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
		'/\bADRs?[- #]?\d{1,4}\b/i'               => 'a design-record number',
		'#docs[\\\\/](?:adr|architecture|phase-\d)(?![\w.-])#' => 'a path of the private documentation',
		'#(?:^|[\s(\[<"\'`])(?:\.\./)*(?:adr|architecture|phase-\d)/#' => 'a relative link into the private documentation',
		'/\btarget[- ]architecture\b/i'           => 'the private architecture document',
		'/(?<![\w-])(?:data-storage|extensibility|domain-map)\.md\b/' => 'a private architecture note',
		'/(?<![\w-])(?:overview|performance|security)\.md\s*§/' => 'a section of a private architecture note (SECURITY.md, in capitals, is public)',
		'#\bresearch/\d{2}\b#'                    => 'a private research note',
		'/\b(?:open-questions|phase-1-backlog|repo-ci-worktree-strategy|test-strategy|wordpress-org-compliance|capability-inventory)\b/' => 'a private planning document',
		'/(?<![\w-])(?:executive-summary|legacy-data-model|legacy-system-map|migration-strategy|woocommerce-case-study|woocommerce-pain-points|wordpress-mapping|DISPOSITIONS|wave-\d-exit-report|wave-\d-log|_AGENT_BRIEF|_RECONCILIATION)\.md\b/' => 'a private planning document',
		'/\bSEOCart_\w*Handoff\b|\bhandoff\s*§/i' => 'a private handoff document',
		'/(?<![\w-])F-(?:SUP|DB|REG|OPS|AUT|SET|EVT|JOB|LOG|KRN|RST)(?![\w-])/' => 'a planning task identifier',
		'/(?<![\w-])(?:A-0[1-9]|B-0[1-9]|B-S\d{1,2}|X-0[1-9])(?![\w-])/' => 'a planning task identifier',
		'/\b(?:Wave \d|Slice [AB]|Checkpoint F|Gate [AB])\b/' => 'a planning phase',
		'/\b(?:AGENTS|CLAUDE)\.md\b/'             => 'a maintainer-only instruction file',
	);

	/**
	 * Ignore files, whose patterns must name the private paths they ignore. Their comment
	 * lines are still checked.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const IGNORE_FILES = array(
		'.distignore',
		'.gitignore',
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
			// This file spells every citation it looks for; a file deleted but not yet removed from git has nothing to read.
			if ( 'tests/Unit/Docs/PrivateReferencesTest.php' === $file || ! is_file( $root . '/' . $file ) ) {
				continue;
			}

			$content = file_get_contents( $root . '/' . $file );

			// Binary files (images, fonts) cannot hold a citation a reader would follow.
			if ( false === $content || str_contains( $content, "\0" ) ) {
				continue;
			}

			$patterns_only = in_array( $file, self::IGNORE_FILES, true );

			foreach ( explode( "\n", $content ) as $index => $line ) {
				if ( $patterns_only && ! str_starts_with( ltrim( $line ), '#' ) ) {
					continue;
				}

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
			'see SEOCart_Phase1_Wave1_Handoff.md',
			'as the handoff §8 says',
			'[records](adr/README.md)',
			'](../phase-0/reviews/DISPOSITIONS.md)',
			'W:\docs\adr\README.md',
			'ADR 0004 and adr-0012',
			'the legacy-system-map.md notes',
			'recorded in wave-1-log.md',
			'performance.md §4',
			'SKU B-S7 is reserved',
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
			'the architecture-overview.md and docs/architecture-tour.md pages',
			'https://developer.wordpress.org/plugins/architecture/',
			'a stock location SKU-B-01',
			'the performance.md and security.md pages of a public manual',
			'"_readme": "https://getcomposer.org/doc/01-basic-usage.md#installing-dependencies"',
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
