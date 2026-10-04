<?php
/**
 * Tests for the check that keeps citations of private material out of a repository
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SEOCart\Tools\Docs\PrivateReferences;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * Plants every spelling the check must recognise, and runs it over a scratch git checkout.
 *
 * @since 0.2.0
 */
final class PrivateReferencesTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * Tests that each spelling is recognised, and that ordinary text is not.
	 *
	 * @since 0.2.0
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
			$this->assertNotSame( array(), PrivateReferences::citationsIn( $text ), "Not recognised as a citation: $text" );
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
			$this->assertSame( array(), PrivateReferences::citationsIn( $text ), "Wrongly read as a citation: $text" );
		}
	}

	/**
	 * Tests that a citation in a tracked file is reported with its file and line, and that
	 * the same text in an untracked file is nobody else's business.
	 *
	 * @since 0.2.0
	 */
	public function test_only_tracked_files_are_read(): void {
		$this->git( 'init', '--quiet' );
		$this->writeFile( 'src/Tracked.php', "<?php\n// Clean.\n// As the target architecture says.\n" );
		$this->writeFile( 'notes.md', 'Arrives in Wave 2.' );
		$this->git( 'add', 'src/Tracked.php' );

		$this->assertSame(
			array( 'src/Tracked.php:3 cites the private architecture document ("target architecture")' ),
			PrivateReferences::findIn( $this->directory )
		);
	}

	/**
	 * Tests that an ignore file may name a private path in a pattern but not in a comment,
	 * and that a binary file is skipped.
	 *
	 * @since 0.2.0
	 */
	public function test_ignore_file_patterns_are_exempt_and_their_comments_are_not(): void {
		$this->git( 'init', '--quiet' );
		$this->writeFile( '.gitignore', "/docs/adr\n# Kept out: AGENTS.md\n" );
		$this->writeFile( 'image.bin', "ADR-0001\0" );
		$this->git( 'add', '.gitignore', 'image.bin' );

		$this->assertSame(
			array( '.gitignore:2 cites a maintainer-only instruction file ("AGENTS.md")' ),
			PrivateReferences::findIn( $this->directory )
		);
	}

	/**
	 * Tests that a checkout git lists nothing for fails instead of passing on an empty list.
	 *
	 * @since 0.2.0
	 */
	public function test_an_empty_checkout_is_an_error_not_a_pass(): void {
		$this->git( 'init', '--quiet' );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'git listed no tracked files' );

		PrivateReferences::findIn( $this->directory );
	}

	/**
	 * Tests that a directory git does not know fails instead of passing on an empty list.
	 *
	 * @since 0.2.0
	 */
	public function test_a_directory_outside_git_is_an_error_not_a_pass(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'git ls-files failed' );

		PrivateReferences::findIn( $this->directory );
	}

	/**
	 * Runs git in the scratch directory.
	 *
	 * @since 0.2.0
	 *
	 * @param string ...$arguments The git command and its arguments.
	 */
	private function git( string ...$arguments ): void {
		$command = array_merge( array( 'git', '-C', $this->directory ), $arguments );
		$process = proc_open( $command, array( 2 => array( 'pipe', 'w' ) ), $pipes );

		$this->assertIsResource( $process, 'git could not be started.' );

		$errors = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), implode( ' ', $command ) . ' failed: ' . $errors );
	}
}
