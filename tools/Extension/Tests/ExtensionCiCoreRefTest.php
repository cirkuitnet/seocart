<?php
/**
 * Tests that an extension's required CI tests the commit its seocart-core.env pins
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tools\Extension\Tests;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * Runs the step of extension-ci.yml that chooses the SEOCart commit, as the runner would.
 *
 * The step's `run:` text is cut out of the workflow and run with `sh` in a directory that holds
 * `ext/seocart-core.env` and a `GITHUB_OUTPUT` file, once per caller event. The workflow's
 * `core-ref` input exists for the nightly caller; a run that can be a required check must not be
 * able to use it, or an extension would go green against a SEOCart other than the one it pins.
 *
 * @since 0.2.0
 */
final class ExtensionCiCoreRefTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The commit seocart-core.env pins in these tests.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PIN = '0123456789abcdef0123456789abcdef01234567';

	/**
	 * Another commit, which only a scheduled or manually started run may name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const OTHER = 'fedcba9876543210fedcba9876543210fedcba98';

	/**
	 * Tests the commit a run chooses when the caller passes core-ref, or does not, on an event that may.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideAcceptedRuns
	 *
	 * @param string $event    The caller's event.
	 * @param string $input    The core-ref input; empty when the caller passes none.
	 * @param string $expected The commit the step outputs.
	 */
	public function test_the_step_chooses_the_expected_commit( string $event, string $input, string $expected ): void {
		$run = $this->runStep( $event, $input );

		$this->assertSame( 0, $run['status'], $run['output'] );
		$this->assertSame( "ref={$expected}\n", $run['written'] );
		$this->assertStringNotContainsString( '::error::', $run['output'] );
	}

	/**
	 * Provides the runs the step lets through.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string, string}> Event, input, the commit chosen.
	 */
	public static function provideAcceptedRuns(): array {
		return array(
			'no input, pull request: the pin'   => array( 'pull_request', '', self::PIN ),
			'no input, push: the pin'           => array( 'push', '', self::PIN ),
			'no input, schedule: the pin'       => array( 'schedule', '', self::PIN ),
			'no input, dispatch: the pin'       => array( 'workflow_dispatch', '', self::PIN ),
			'main, schedule: main'              => array( 'schedule', 'main', 'main' ),
			'main, dispatch: main'              => array( 'workflow_dispatch', 'main', 'main' ),
			'a commit, dispatch: that commit'   => array( 'workflow_dispatch', self::OTHER, self::OTHER ),
			'the pin itself, schedule: the pin' => array( 'schedule', self::PIN, self::PIN ),
		);
	}

	/**
	 * Tests that a run which can be a required check cannot name another commit, with one error that says what to do.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideRefusedOverrides
	 *
	 * @param string $event The caller's event.
	 * @param string $input The core-ref input.
	 */
	public function test_the_step_refuses_core_ref_unless_the_run_is_scheduled_or_dispatched( string $event, string $input ): void {
		$run = $this->runStep( $event, $input );

		$this->assertSame( 1, $run['status'], 'The step must fail.' );
		$this->assertSame( '', $run['written'], 'The step must choose nothing.' );
		$this->assertSame( 1, preg_match_all( '/^::error::/m', $run['output'] ), $run['output'] );

		foreach ( array( 'core-ref', 'seocart-core.env', 'bin/dev/bump-core.sh', 'schedule', 'workflow_dispatch' ) as $named ) {
			$this->assertStringContainsString( $named, $run['output'] );
		}
	}

	/**
	 * Provides the overrides the step refuses.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> Event, input.
	 */
	public static function provideRefusedOverrides(): array {
		return array(
			'main on a pull request'           => array( 'pull_request', 'main' ),
			'main on a push'                   => array( 'push', 'main' ),
			'a commit on a pull request'       => array( 'pull_request', self::OTHER ),
			'a commit on a push'               => array( 'push', self::OTHER ),
			'the pin itself on a pull request' => array( 'pull_request', self::PIN ),
			'main on pull_request_target'      => array( 'pull_request_target', 'main' ),
			'main on a merge group'            => array( 'merge_group', 'main' ),
			'main on an unknown event'         => array( 'release', 'main' ),
		);
	}

	/**
	 * Tests that an input which is neither main nor a commit is refused on the events that may pass one.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideMalformedInputs
	 *
	 * @param string $input The core-ref input.
	 */
	public function test_the_step_refuses_an_input_that_is_not_main_or_a_commit( string $input ): void {
		$run = $this->runStep( 'schedule', $input );

		$this->assertSame( 1, $run['status'], 'The step must fail.' );
		$this->assertSame( '', $run['written'], 'The step must choose nothing.' );
		$this->assertSame( 1, preg_match_all( '/^::error::No SEOCart commit to test against/m', $run['output'] ), $run['output'] );
	}

	/**
	 * Provides inputs that name no commit.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The input.
	 */
	public static function provideMalformedInputs(): array {
		return array(
			'a branch'                 => array( 'develop' ),
			'a qualified branch'       => array( 'refs/heads/main' ),
			'a short commit'           => array( substr( self::OTHER, 0, 39 ) ),
			'a long commit'            => array( self::OTHER . '0' ),
			'capital letters'          => array( strtoupper( self::OTHER ) ),
			'a commit that is not hex' => array( str_repeat( 'g', 40 ) ),
		);
	}

	/**
	 * Tests that a run with no input still needs the pin to be in seocart-core.env.
	 *
	 * @since 0.2.0
	 */
	public function test_the_step_fails_when_nothing_pins_a_commit(): void {
		$run = $this->runStep( 'pull_request', '', 'SEOCART_CORE_REF=main' );

		$this->assertSame( 1, $run['status'], 'The step must fail.' );
		$this->assertSame( '', $run['written'], 'The step must choose nothing.' );
		$this->assertSame( 1, preg_match_all( '/^::error::No SEOCart commit to test against/m', $run['output'] ), $run['output'] );
	}

	/**
	 * Tests that every job which checks SEOCart out gets its commit from this one step.
	 *
	 * The step is anchored once and repeated through an alias; a job that wrote a step of its own
	 * (an `id: core` step that reads core-ref without the refusal) would pass every test above.
	 *
	 * @since 0.2.0
	 */
	public function test_every_job_that_checks_seocart_out_uses_the_step(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/extension-ci.yml' );

		$this->assertSame( 1, substr_count( $workflow, '- &choose-core' ), 'The step is anchored once, in the first job.' );
		$this->assertSame( 1, substr_count( $workflow, '- &checkout-core' ), 'The checkout is anchored once, in the first job.' );
		$this->assertSame( 1, substr_count( $workflow, 'id: core' ), 'Only the anchored step may state `id: core`: another step would choose a commit of its own.' );
		$this->assertSame(
			4,
			substr_count( $workflow, "            - *choose-core\n            - *checkout-core\n" ),
			'unit-tests, integration-tests, package and seocart-zip each use the step through its alias, followed by the checkout.'
		);
		$this->assertSame( 1, substr_count( $workflow, 'ref: ${{ steps.core.outputs.ref }}' ), 'Only the anchored checkout may read the chosen commit; no job checks SEOCart out by another ref.' );
		$this->assertSame( 1, substr_count( $workflow, 'repository: cirkuitnet/seocart' ), 'Only the anchored checkout names SEOCart\'s repository.' );
	}

	/**
	 * Runs the step.
	 *
	 * @since 0.2.0
	 *
	 * @param string $event The caller's event: GITHUB_EVENT_NAME, which in a reusable workflow is the caller's.
	 * @param string $input The core-ref input; empty when the caller passes none.
	 * @param string $env   The contents of ext/seocart-core.env.
	 * @return array{status: int, output: string, written: string} The exit status, what the step printed and what it wrote to GITHUB_OUTPUT.
	 */
	private function runStep( string $event, string $input, string $env = 'SEOCART_CORE_REF=' . self::PIN ): array {
		$this->writeFile( 'ext/seocart-core.env', $env . "\n" );
		$output_file = $this->writeFile( 'github-output', '' );

		$process = proc_open(
			array( 'sh', '-c', self::stepScript() ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->directory,
			array(
				'PATH'              => (string) getenv( 'PATH' ),
				'CORE_REF'          => $input,
				'GITHUB_EVENT_NAME' => $event,
				'GITHUB_OUTPUT'     => $output_file,
			)
		);

		$this->assertIsResource( $process );

		$printed = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status'  => proc_close( $process ),
			'output'  => $printed,
			'written' => (string) file_get_contents( $output_file ),
		);
	}

	/**
	 * Returns the `run:` text of the step that chooses the SEOCart commit.
	 *
	 * Every job that checks SEOCart out repeats this step through a YAML alias, so the step the
	 * anchor marks is the one every job runs.
	 *
	 * @since 0.2.0
	 *
	 * @return string The script, without the indentation it has in the workflow.
	 */
	private static function stepScript(): string {
		$lines  = explode( "\n", (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/extension-ci.yml' ) );
		$anchor = array_search( '            - &choose-core', $lines, true );

		self::assertIsInt( $anchor, 'extension-ci.yml has no step anchored as &choose-core.' );

		$script = null;

		foreach ( array_slice( $lines, $anchor + 1 ) as $line ) {
			if ( null === $script ) {
				self::assertStringStartsWith( '              ', $line, 'The step ends before its run: text.' );
				$script = '              run: |' === $line ? array() : null;
			} elseif ( '' === $line || str_starts_with( $line, '                  ' ) ) {
				$script[] = substr( $line, 18 );
			} else {
				break;
			}
		}

		self::assertNotEmpty( $script, 'The &choose-core step has no run: | text.' );

		return implode( "\n", $script ) . "\n";
	}
}
