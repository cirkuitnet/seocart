<?php
/**
 * PrivateReferences: finds tracked files that cite the maintainers' private design and planning material
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs;

use RuntimeException;

/**
 * Keeps a public repository self-contained.
 *
 * The maintainers keep their design records, architecture notes, planning documents and
 * agent instructions outside the repository. A tracked file that cites one of them by name,
 * number or planning identifier points readers at something they cannot open, so every
 * comment, docblock, test and document says what the rule is instead, or names the public
 * file or test that enforces it. This class owns one fact: which spellings count as such a
 * citation. The patterns of the two ignore files are exempt, because they must name the
 * paths they ignore; their comments are not.
 *
 * SEOCart's own test and every extension's test run the same check, each over its own
 * checkout: tests/Unit/Docs/PrivateReferencesTest.php in this repository, and the test that
 * bin/dev/new-extension.sh writes into an extension.
 *
 * @since 0.2.0
 */
final class PrivateReferences {

	/**
	 * What a citation of private material looks like, each with the reason it is one.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string> Regular expression => what it catches.
	 */
	public const CITATIONS = array(
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
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const IGNORE_FILES = array(
		'.distignore',
		'.gitignore',
	);

	/**
	 * The files that spell every citation on purpose: this class and its self-test.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SPELL_THE_CITATIONS = array(
		'tools/Docs/PrivateReferences.php',
		'tools/Docs/Tests/PrivateReferencesTest.php',
	);

	/**
	 * Finds every citation of private material in the files git tracks under a root.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The root of a git checkout.
	 * @return list<string> One `file:line cites what ("match")` entry per citation; empty when there is none.
	 *
	 * @throws RuntimeException When git cannot list the tracked files, or lists none.
	 */
	public static function findIn( string $root ): array {
		$found = array();

		foreach ( self::trackedFiles( $root ) as $file ) {
			// A file deleted but not yet removed from git has nothing to read.
			if ( in_array( $file, self::SPELL_THE_CITATIONS, true ) || ! is_file( $root . '/' . $file ) ) {
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

				foreach ( self::citationsIn( $line ) as $match => $what ) {
					$found[] = sprintf( '%s:%d cites %s ("%s")', $file, $index + 1, $what, $match );
				}
			}
		}

		return $found;
	}

	/**
	 * Finds the citations of private material in one line of text.
	 *
	 * @since 0.2.0
	 *
	 * @param string $line The text.
	 * @return array<string, string> Matched text => what it cites.
	 */
	public static function citationsIn( string $line ): array {
		$found = array();

		foreach ( self::CITATIONS as $pattern => $what ) {
			if ( 1 === preg_match( $pattern, $line, $match ) ) {
				$found[ $match[0] ] = $what;
			}
		}

		return $found;
	}

	/**
	 * Explains a list of findings, for a failure message.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $found The findings, as findIn() returns them.
	 * @return string What to do, then one finding per line.
	 */
	public static function explain( array $found ): string {
		return "These tracked files cite material the maintainers keep private. Say what the rule is instead, or name the public file or test that enforces it:\n" . implode( "\n", $found );
	}

	/**
	 * Lists the files git tracks, relative to the root.
	 *
	 * An untracked file (a local configuration, a maintainer's own notes) is nobody else's
	 * business, so the list comes from git, not from the file system. Without git the check
	 * fails rather than passing on an empty list.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The root of a git checkout.
	 * @return list<string> Paths with forward slashes.
	 *
	 * @throws RuntimeException When git cannot list the tracked files, or lists none.
	 */
	private static function trackedFiles( string $root ): array {
		$pipes   = array();
		$process = proc_open(
			array( 'git', '-C', $root, 'ls-files', '-z' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		if ( ! is_resource( $process ) ) {
			throw new RuntimeException( 'git could not be started, so the tracked files could not be listed.' );
		}

		$output = (string) stream_get_contents( $pipes[1] );
		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		if ( 0 !== proc_close( $process ) ) {
			throw new RuntimeException( 'git ls-files failed: ' . $errors );
		}

		$files = array_values(
			array_filter(
				explode( "\0", $output ),
				static fn ( string $file ): bool => '' !== $file
			)
		);

		if ( array() === $files ) {
			throw new RuntimeException( "git listed no tracked files under {$root}, so nothing would have been checked." );
		}

		return $files;
	}
}
