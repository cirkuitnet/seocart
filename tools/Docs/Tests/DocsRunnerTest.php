<?php
/**
 * Tests that the docs runner fails on drift and on any unasserted skip
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Docs\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Docs\DocsRunner;

/**
 * Plants each failure the runner exists to catch: drift, an unexpected skip, a stale
 * expected skip, a failing generator, an empty generator list and a mistyped argument.
 *
 * @since 0.1.0
 */
final class DocsRunnerTest extends TestCase {

	/**
	 * A temporary repository root, created for each test.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * The lines the runner printed during the current test.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $lines = array();

	/**
	 * Creates the temporary root.
	 *
	 * @since 0.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->root  = sys_get_temp_dir() . '/seocart-docs-runner-' . bin2hex( random_bytes( 6 ) );
		$this->lines = array();

		mkdir( $this->root );
	}

	/**
	 * Removes the temporary root.
	 *
	 * @since 0.1.0
	 */
	protected function tearDown(): void {
		if ( is_file( $this->root . '/generated.txt' ) ) {
			unlink( $this->root . '/generated.txt' );
		}

		rmdir( $this->root );

		parent::tearDown();
	}

	/**
	 * Builds a runner around one fake generator.
	 *
	 * @since 0.1.0
	 *
	 * @param FakeGenerator $generator The generator to run.
	 * @return DocsRunner The runner, printing into $this->lines.
	 */
	private function runner( FakeGenerator $generator ): DocsRunner {
		return new DocsRunner(
			array( $generator ),
			$this->root,
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);
	}

	/**
	 * Returns the committed content of the fake target, or null when it does not exist.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The file's content.
	 */
	private function committed(): ?string {
		return is_file( $this->root . '/generated.txt' ) ? (string) file_get_contents( $this->root . '/generated.txt' ) : null;
	}

	/**
	 * Tests that write mode writes the file and a following check passes.
	 *
	 * @since 0.1.0
	 */
	public function test_generate_writes_and_check_then_passes(): void {
		$runner = $this->runner( new FakeGenerator( "generated\n" ) );

		$this->assertSame( 0, $runner->run( false ) );
		$this->assertSame( "generated\n", $this->committed() );
		$this->assertSame( 0, $runner->run( true ) );
	}

	/**
	 * Tests that check mode fails on drift, prints the regeneration command and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_check_fails_on_drift_and_prints_the_regeneration_command(): void {
		file_put_contents( $this->root . '/generated.txt', "edited by hand\n" );

		$this->assertSame( 1, $this->runner( new FakeGenerator( "generated\n" ) )->run( true ) );
		$this->assertSame( "edited by hand\n", $this->committed() );

		$output = implode( "\n", $this->lines );

		$this->assertStringContainsString( 'DRIFT  generated.txt (fake): line 1 is "edited by hand" but its source generates "generated".', $output );
		$this->assertStringContainsString( 'composer docs:generate', $output );
	}

	/**
	 * Tests that check mode fails when the generated file was never committed.
	 *
	 * @since 0.1.0
	 */
	public function test_check_fails_when_the_file_is_missing(): void {
		$this->assertSame( 1, $this->runner( new FakeGenerator( "generated\n" ) )->run( true ) );
		$this->assertNull( $this->committed() );
	}

	/**
	 * Tests that an unexpected skip fails in both modes and that nothing is written.
	 *
	 * @since 0.1.0
	 */
	public function test_unexpected_skip_fails_and_writes_nothing(): void {
		$runner = $this->runner( new FakeGenerator( "generated\n", array( 'operation-without-schema' ) ) );

		$this->assertSame( 1, $runner->run( false ) );
		$this->assertNull( $this->committed() );
		$this->assertStringContainsString( 'unexpected skip: operation-without-schema', implode( "\n", $this->lines ) );

		$this->assertSame( 1, $runner->run( true ) );
	}

	/**
	 * Tests that an unexpected skip fails even when the committed file is otherwise in sync.
	 *
	 * @since 0.1.0
	 */
	public function test_unexpected_skip_fails_even_without_drift(): void {
		file_put_contents( $this->root . '/generated.txt', "generated\n" );

		$this->assertSame( 1, $this->runner( new FakeGenerator( "generated\n", array( 'a' ) ) )->run( true ) );
	}

	/**
	 * Tests that a declared skip that happens is accepted.
	 *
	 * @since 0.1.0
	 */
	public function test_expected_skip_passes(): void {
		$runner = $this->runner( new FakeGenerator( "generated\n", array( 'a', 'b' ), array( 'b', 'a' ) ) );

		$this->assertSame( 0, $runner->run( false ) );
		$this->assertSame( 0, $runner->run( true ) );
	}

	/**
	 * Tests that a declared skip that no longer happens fails, so the list cannot go stale.
	 *
	 * @since 0.1.0
	 */
	public function test_stale_expected_skip_fails(): void {
		$this->assertSame( 1, $this->runner( new FakeGenerator( "generated\n", array( 'a' ), array( 'a', 'b' ) ) )->run( false ) );
		$this->assertStringContainsString( 'stale expected skip: b', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a generator that throws fails the run.
	 *
	 * @since 0.1.0
	 */
	public function test_failing_generator_fails_the_run(): void {
		$this->assertSame( 1, $this->runner( new FakeGenerator( null ) )->run( false ) );
		$this->assertStringContainsString( 'FAIL   generated.txt (fake): the source is invalid.', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that a run without generators fails in both modes, because it would check nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_empty_generator_list_fails(): void {
		$runner = new DocsRunner(
			array(),
			$this->root,
			function ( string $line ): void {
				$this->lines[] = $line;
			}
		);

		$this->assertSame( 1, $runner->run( true ) );
		$this->assertSame( 1, $runner->run( false ) );
		$this->assertStringContainsString( 'no generators are registered', implode( "\n", $this->lines ) );
	}

	/**
	 * Tests that `--check` selects check mode and that an unknown argument is an error.
	 *
	 * @since 0.1.0
	 */
	public function test_main_parses_arguments_strictly(): void {
		$runner = $this->runner( new FakeGenerator( "generated\n" ) );

		$this->assertSame( 2, $runner->main( array( '--chek' ) ) );
		$this->assertNull( $this->committed(), 'A mistyped --check must not fall back to writing.' );

		$this->assertSame( 1, $runner->main( array( '--check' ) ) );
		$this->assertNull( $this->committed(), '--check must not write.' );

		$this->assertSame( 0, $runner->main( array() ) );
		$this->assertSame( "generated\n", $this->committed() );
		$this->assertSame( 0, $runner->main( array( '--check' ) ) );
	}
}
