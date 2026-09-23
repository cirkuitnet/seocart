<?php
/**
 * Tests that the documentation command runs exactly the real generators, and that each catches drift
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\DocsRunner;
use SEOCart\Tools\Docs\Generator;
use SEOCart\Tools\Docs\Generators;

/**
 * Pins the generator list to the documents it must keep in step.
 *
 * DocsRunner's own tests prove the runner with fake generators; these prove the real list. The
 * first runs `bin/generate-docs.php --check` exactly as `composer docs:check` does and requires
 * the targets it reports to be exactly the generated documents, so dropping a generator from the
 * list fails. The second runs the real generators through the runner against a copy of the
 * committed documents and drifts each one in turn, so each generator is shown to catch drift in
 * its own document.
 *
 * @since 0.1.0
 */
final class GeneratorsTest extends TestCase {

	/**
	 * Every committed document a generator owns.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const TARGETS = array(
		'readme.txt',
		'docs/openapi.json',
		'docs/reference/abilities.md',
		'docs/reference/cli.md',
		'docs/reference/errors.md',
	);

	/**
	 * A temporary copy of the committed documents, created per test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $copy = '';

	/**
	 * Sets up Brain Monkey: gettext returns its text, as it does when no translation is loaded.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
	}

	/**
	 * Removes the temporary copy and tears Brain Monkey down.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		if ( '' !== $this->copy ) {
			foreach ( self::TARGETS as $target ) {
				if ( is_file( $this->copy . '/' . $target ) ) {
					unlink( $this->copy . '/' . $target );
				}
			}

			foreach ( array( '/docs/reference', '/docs', '' ) as $directory ) {
				if ( is_dir( $this->copy . $directory ) ) {
					rmdir( $this->copy . $directory );
				}
			}
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Tests that the documentation command checks exactly the generated documents.
	 *
	 * @since 0.1.0
	 */
	public function test_the_command_checks_exactly_the_generated_documents(): void {
		$process = proc_open(
			array( PHP_BINARY, self::root() . '/bin/generate-docs.php', '--check' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);

		$this->assertIsResource( $process );

		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$this->assertSame( 0, proc_close( $process ), $output );

		preg_match_all( '/^(?:ok|wrote|DRIFT|FAIL) +(\S+) \(/m', $output, $matches );

		$targets = $matches[1];

		sort( $targets );

		$expected = self::TARGETS;

		sort( $expected );

		$this->assertSame( $expected, $targets, "The command does not check exactly the generated documents:\n" . $output );
	}

	/**
	 * Tests that the real generators own exactly the generated documents, and each catches drift in its own.
	 *
	 * @since 0.1.0
	 */
	public function test_each_generated_document_drifts_through_the_runner(): void {
		$generators = Generators::all( self::root() );

		$this->assertSame( self::TARGETS, array_map( static fn( Generator $generator ): string => $generator->target(), $generators ) );

		$this->copy = sys_get_temp_dir() . '/seocart-generators-' . bin2hex( random_bytes( 6 ) );

		mkdir( $this->copy . '/docs/reference', 0777, true );

		foreach ( self::TARGETS as $target ) {
			copy( self::root() . '/' . $target, $this->copy . '/' . $target );
		}

		$lines  = array();
		$runner = new DocsRunner(
			$generators,
			$this->copy,
			static function ( string $line ) use ( &$lines ): void {
				$lines[] = $line;
			}
		);

		$this->assertSame( 0, $runner->run( true ), "The committed documents are not in step:\n" . implode( "\n", $lines ) );

		foreach ( self::TARGETS as $target ) {
			$path     = $this->copy . '/' . $target;
			$original = (string) file_get_contents( $path );
			$lines    = array();

			// The readme's generator owns one section of it, so the edit goes into that section.
			$edited = 'readme.txt' === $target
				? str_replace( "== External services ==\n\n", "== External services ==\n\nEdited by hand.\n\n", $original )
				: $original . "Edited by hand.\n";

			$this->assertNotSame( $original, $edited, "The edit of {$target} changed nothing." );

			file_put_contents( $path, $edited );

			$this->assertSame( 1, $runner->run( true ), "Drift in {$target} was not caught." );
			$this->assertStringContainsString( 'DRIFT  ' . $target . ' (', implode( "\n", $lines ) );
			$this->assertStringContainsString( DocsRunner::REGENERATE_COMMAND, implode( "\n", $lines ) );

			file_put_contents( $path, $original );
		}
	}

	/**
	 * Returns the repository root.
	 *
	 * @since 0.1.0
	 *
	 * @return string The absolute path.
	 */
	private static function root(): string {
		return dirname( __DIR__, 3 );
	}
}
