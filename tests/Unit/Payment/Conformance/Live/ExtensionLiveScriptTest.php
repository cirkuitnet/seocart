<?php
/**
 * Tests bin/ci/extension-live.sh and its lib, run for real on the fixture extension
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * The runner takes a step, the extension's root and the step's one argument, and refuses anything else as a usage error; its steps give their outputs, say why the optional part runs nothing, judge the run under the workflow's name, and refuse to run a part's tests before the settings step; the lib's paths live in one work directory of the runner's temporary directory, and its filter passes a command's output through the extension's guard and keeps the command's status.
 *
 * Each case runs the script in an environment of its own, with no GITHUB_OUTPUT, so the outputs
 * are printed.
 *
 * @since 0.2.0
 */
final class ExtensionLiveScriptTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * Tests that a step given the wrong arguments, or no step, is a usage error.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider wrongArguments
	 *
	 * @param string[] $arguments The arguments after the script.
	 */
	public function test_wrong_arguments_are_a_usage_error( array $arguments ): void {
		$run = $this->runner( array_map( static fn( string $argument ): string => str_replace( '<fixture>', StatedDeclaration::FIXTURE, $argument ), $arguments ) );

		$this->assertSame( 2, $run['status'], $run['out'] . $run['err'] );
		$this->assertStringContainsString( 'usage: extension-live.sh heads|site|conformance|verdict <extension root>', $run['err'] );
	}

	/**
	 * Returns arguments the script refuses.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<string>}> The arguments; by name.
	 */
	public static function wrongArguments(): array {
		return array(
			'nothing'                => array( array() ),
			'a step alone'           => array( array( 'heads' ) ),
			'no such step'           => array( array( 'settings', '<fixture>' ) ),
			'a part missing'         => array( array( 'live', '<fixture>' ) ),
			'a job missing'          => array( array( 'optional', '<fixture>' ) ),
			'an argument too many'   => array( array( 'verdict', '<fixture>', 'all' ) ),
			'two arguments too many' => array( array( 'live', '<fixture>', 'payments', 'more' ) ),
		);
	}

	/**
	 * Tests that a root that is no directory fails, naming it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_root_that_is_no_directory_fails(): void {
		$run = $this->runner( array( 'heads', $this->directory . '/nowhere' ) );

		$this->assertSame( 1, $run['status'] );
		$this->assertSame( '::error::' . $this->directory . "/nowhere is not a directory.\n", $run['out'] );
	}

	/**
	 * Tests that the heads step gives the extension's commit and SEOCart's.
	 *
	 * @since 0.2.0
	 */
	public function test_heads_gives_both_commits(): void {
		$run = $this->runner( array( 'heads', StatedDeclaration::FIXTURE ) );

		$this->assertSame( 0, $run['status'], $run['err'] );
		$this->assertMatchesRegularExpression( '/^ext-head=([0-9a-f]{40})\ncore-head=\1\n$/D', $run['out'], 'The fixture is a folder of SEOCart\'s checkout, so its commit is SEOCart\'s.' );
	}

	/**
	 * Tests that the optional step says whether its secret is set, as an output, never the secret, and says why the part runs nothing without it.
	 *
	 * @since 0.2.0
	 */
	public function test_optional_says_whether_the_secret_is_set(): void {
		$secret  = 'whsk_' . bin2hex( random_bytes( 16 ) );
		$absent  = $this->runner( array( 'optional', StatedDeclaration::FIXTURE, 'webhooks' ) );
		$present = $this->runner( array( 'optional', StatedDeclaration::FIXTURE, 'webhooks' ), array( 'FIXTURE_PAY_SIGNATURE_KEY' => $secret ) );
		$other   = $this->runner( array( 'optional', StatedDeclaration::FIXTURE, 'payments' ) );

		$this->assertSame( array( 0, 0, 1 ), array( $absent['status'], $present['status'], $other['status'] ) );
		$this->assertSame( "signature-key=absent\n::notice title=The webhooks part::The webhooks part is skipped: the repository has no FIXTURE_PAY_SIGNATURE_KEY secret yet, the key the sandbox signs its notifications with. The other parts run.\n", $absent['out'] );
		$this->assertSame( "signature-key=present\n", $present['out'] );
		$this->assertStringNotContainsString( $secret, $present['out'] . $present['err'] );
		$this->assertSame( "::error::The job payments is not the optional part of tests/live.php, which is webhooks.\n", $other['out'] );
	}

	/**
	 * Tests that the verdict judges the jobs it is given under the workflow's name, and fails a red run.
	 *
	 * @since 0.2.0
	 */
	public function test_the_verdict_judges_the_run_under_the_workflows_name(): void {
		$needs = array_map(
			static fn(): array => array(
				'result'  => 'success',
				'outputs' => array(),
			),
			StatedDeclaration::fixture()->jobs()
		);

		$needs['live-conformance']['outputs'] = array(
			'tests'      => '17',
			'assertions' => '307',
			'skipped'    => '2',
			'skip-set'   => 'expected',
		);

		$green = $this->runner(
			array( 'verdict', StatedDeclaration::FIXTURE ),
			array(
				'SEOCART_LIVE_NEEDS' => (string) json_encode( $needs ),
				'SEOCART_LIVE_PARTS' => 'all',
				'GITHUB_WORKFLOW'    => 'Fixture Pay sandbox',
			)
		);

		$needs['payments']['result'] = 'failure';

		$red = $this->runner(
			array( 'verdict', StatedDeclaration::FIXTURE ),
			array(
				'SEOCART_LIVE_NEEDS' => (string) json_encode( $needs ),
				'SEOCART_LIVE_PARTS' => '',
			)
		);

		$this->assertSame( 0, $green['status'], $green['out'] . $green['err'] );
		$this->assertStringStartsWith( "## Fixture Pay sandbox: green\n", $green['out'] );
		$this->assertSame( 1, $red['status'] );
		$this->assertStringStartsWith( "## fixture-pay-sandbox: red\n", $red['out'] );
		$this->assertStringContainsString( "::error::The job payments was selected and ended failure.\n", $red['out'] );
	}

	/**
	 * Tests that the steps that read the settings file, and the site step without its environment, fail before they run anything.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider unready
	 *
	 * @param string[] $arguments The arguments after the root.
	 * @param string   $error     The error printed.
	 */
	public function test_a_step_that_is_not_ready_fails_first( array $arguments, string $error ): void {
		$run = $this->runner( array_merge( array( $arguments[0], StatedDeclaration::FIXTURE ), array_slice( $arguments, 1 ) ) );

		$this->assertSame( 1, $run['status'], $run['err'] );
		$this->assertStringEndsWith( '::error::' . $error . "\n", $run['out'] );
	}

	/**
	 * Returns steps that are not ready to run.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<string>, string}> The step and its argument, and the error; by name.
	 */
	public static function unready(): array {
		return array(
			'the suite before the settings' => array( array( 'conformance' ), 'The settings file is missing: run the settings step first.' ),
			'a part before the settings'    => array( array( 'live', 'payments' ), 'The settings file is missing: run the settings step first.' ),
			'a part the declaration lacks'  => array( array( 'live', 'checkout' ), 'live: checkout is no live part of ' . realpath( StatedDeclaration::FIXTURE ) . '/tests/live.php.' ),
			'the site without its database' => array( array( 'site' ), 'site: the environment variable WORDPRESS_VERSION is not set.' ),
		);
	}

	/**
	 * Tests the lib: its work directory under the runner's temporary directory, and its filter, which passes a command's output through the guard, keeps it, and returns the command's status.
	 *
	 * @since 0.2.0
	 */
	public function test_the_lib_filters_through_the_guard_and_keeps_the_status(): void {
		$program = implode(
			"\n",
			array(
				'core=$1',
				'ext=$2',
				'. "$core/bin/ci/extension-live-lib.sh"',
				'printf "work=%s\n" "$work"',
				'mkdir -p "$work"',
				'filtered "$work/kept" sh -c \'printf "%s\n" "ok 1" "Bearer UnitTestAccessTokenOfTheFixturesOnly" "card 4012888888881881"; exit 3\'',
				'printf "status=%s\n" "$?"',
				'cat "$work/kept"',
			)
		);
		$run     = $this->process( array( 'sh', '-c', $program, 'sh', dirname( __DIR__, 5 ), StatedDeclaration::FIXTURE ), array( 'RUNNER_TEMP' => $this->directory ) );
		$shown   = "ok 1\n[redacted: an authorization header]\ncard [redacted: a card number]\n";

		$this->assertSame( 'work=' . $this->directory . "/seocart-live\n" . $shown . "status=3\n" . $shown, $run['out'], $run['err'] );
	}

	/**
	 * Runs bin/ci/extension-live.sh.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]              $arguments The arguments after the script.
	 * @param array<string, string> $env       The environment beside PATH, HOME and the temporary directory.
	 * @return array{status: int, out: string, err: string} The exit status, and what it printed on each stream.
	 */
	private function runner( array $arguments, array $env = array() ): array {
		return $this->process( array_merge( array( 'sh', dirname( __DIR__, 5 ) . '/bin/ci/extension-live.sh' ), $arguments ), $env );
	}

	/**
	 * Runs a process in an environment of its own.
	 *
	 * @since 0.2.0
	 *
	 * @param string[]              $command The command and its arguments.
	 * @param array<string, string> $env     The environment beside PATH, HOME and the temporary directory.
	 * @return array{status: int, out: string, err: string} The exit status, and what it printed on each stream.
	 */
	private function process( array $command, array $env ): array {
		// Standard input is a pipe closed at once, never a file: once a test has loaded Patchwork, which
		// Brain Monkey uses, a file is opened through Patchwork's stream wrapper, which cannot hand it
		// to a process.
		$process = proc_open(
			$command,
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			$env + array(
				'PATH'   => (string) getenv( 'PATH' ),
				'HOME'   => (string) getenv( 'HOME' ),
				'TMPDIR' => $this->directory,
			)
		);

		$this->assertIsResource( $process );

		fclose( $pipes[0] );

		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'out'    => $out,
			'err'    => $err,
		);
	}
}
