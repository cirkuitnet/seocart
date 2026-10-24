<?php
/**
 * Tests that the workflow gate reports a core-ref passed from a workflow that can be a required check
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
 * Runs bin/ci/check-workflows.sh over small trees, each holding the workflow files a case needs.
 *
 * The tools actionlint and shellcheck are not what is tested here, so stand-ins that succeed take
 * their place on PATH; the shared-facts check, an awk program, is the part that runs for real. The
 * CI job that runs the script has the real tools.
 *
 * @since 0.2.0
 */
final class CheckWorkflowsCoreRefTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The commit the tree's seocart-core.env pins, and the one the calls name.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PIN = '0123456789abcdef0123456789abcdef01234567';

	/**
	 * Tests that a call that passes core-ref is reported when the workflow runs on a pull request or a push.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideRequiredTriggers
	 *
	 * @param array<int, string> $on The lines of the workflow's `on:` block.
	 */
	public function test_core_ref_is_reported_in_a_workflow_that_can_be_a_required_check( array $on ): void {
		$lines = self::caller( $on, array( 'extension-type: payments', 'core-ref: main' ) );
		$at    = array_search( '            core-ref: main', $lines, true );
		$check = $this->check( array( 'planted.yml' => $lines ), self::PIN );

		$this->assertIsInt( $at, 'The planted caller must pass core-ref; otherwise nothing is planted.' );
		$this->assertSame( 1, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'planted.yml:' . ( $at + 1 ) . ': passes core-ref', $check['output'] );
		$this->assertStringContainsString( 'nightly', $check['output'] );
		$this->assertStringContainsString( 'check-workflows: FAIL', $check['output'] );
	}

	/**
	 * Provides the triggers of a workflow that can be a required check.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<string>}> The lines of the `on:` block.
	 */
	public static function provideRequiredTriggers(): array {
		return array(
			'pull_request'                     => array( array( 'on:', '    pull_request:' ) ),
			'push with its branches'           => array( array( 'on:', '    push:', '        branches:', '            - main' ) ),
			'pull_request_target'              => array( array( 'on:', '    pull_request_target:' ) ),
			'a schedule beside a pull_request' => array( array( 'on:', '    schedule:', "        - cron: '41 4 * * *'", '    pull_request:' ) ),
			'a flow list'                      => array( array( 'on: [push, workflow_dispatch]' ) ),
			'a single event'                   => array( array( 'on: pull_request' ) ),
			'a block list'                     => array( array( 'on:', '    - schedule', '    - pull_request' ) ),
			'a quoted key'                     => array( array( '"on":', '    push:' ) ),
			'merge_group'                      => array( array( 'on:', '    merge_group:' ) ),
			'a comment on the on: line'        => array( array( 'on: # a comment', '    push:' ) ),
		);
	}

	/**
	 * Tests that core-ref is accepted from a workflow that only a schedule or a person starts.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideNightlyTriggers
	 *
	 * @param array<int, string> $on The lines of the workflow's `on:` block.
	 */
	public function test_core_ref_is_accepted_in_a_workflow_that_cannot_be_a_required_check( array $on ): void {
		$check = $this->check( array( 'nightly.yml' => self::caller( $on, array( 'extension-type: payments', 'core-ref: main' ) ) ), self::PIN );

		$this->assertSame( 0, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'check-workflows: PASS', $check['output'] );
	}

	/**
	 * Provides the triggers of a workflow that cannot be a required check.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{list<string>}> The lines of the `on:` block.
	 */
	public static function provideNightlyTriggers(): array {
		return array(
			'schedule and workflow_dispatch'       => array( array( 'on:', '    schedule:', "        - cron: '41 4 * * *'", '    workflow_dispatch:' ) ),
			'a flow list'                          => array( array( 'on: [schedule, workflow_dispatch]' ) ),
			'a single event'                       => array( array( 'on: workflow_dispatch' ) ),
			'an input that is named like an event' => array( array( 'on:', '    workflow_dispatch:', '        inputs:', '            push:', '                type: boolean' ) ),
			'a comment on the on: line'            => array( array( 'on: # nightly only, never on push', '    schedule:', "        - cron: '41 4 * * *'", '    workflow_dispatch:' ) ),
			'a comment on an event'                => array( array( 'on:', '    schedule: # not on push', '    workflow_dispatch: # nor on pull_request' ) ),
			'a comment on a flow list'             => array( array( 'on: [schedule, workflow_dispatch] # never push' ) ),
		);
	}

	/**
	 * Tests that core-ref is reported in each form a job's `with:` mapping can state it in.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideWithForms
	 *
	 * @param array<int, string> $with The lines of the job's `with:` mapping, indented as they stand in the job.
	 */
	public function test_core_ref_is_reported_in_every_form_of_a_with_mapping( array $with ): void {
		$base = self::caller( array( 'on:', '    push:' ), array() );

		// A form that states `with:` itself replaces the caller's empty one.
		if ( 1 === preg_match( '/^        ["\']?with["\']?:/', $with[0] ) ) {
			$this->assertSame( '        with:', array_pop( $base ) );
		}

		$lines = array_merge( $base, $with );
		$at    = null;

		foreach ( $lines as $number => $line ) {
			if ( null === $at && str_contains( $line, 'core-ref' ) ) {
				$at = $number + 1;
			}
		}

		$check = $this->check( array( 'planted.yml' => $lines ), self::PIN );

		$this->assertIsInt( $at, 'The planted caller must pass core-ref; otherwise nothing is planted.' );
		$this->assertSame( 1, $check['status'], $check['output'] );
		$this->assertStringContainsString( "planted.yml:{$at}: passes core-ref", $check['output'] );
	}

	/**
	 * Provides the forms of a `with:` mapping that pass core-ref.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<int, string>}> The mapping's lines.
	 */
	public static function provideWithForms(): array {
		return array(
			'a quoted key'                    => array( array( '            "core-ref": main' ) ),
			'a single-quoted key'             => array( array( "            'core-ref': main" ) ),
			'a space before the colon'        => array( array( '            core-ref : main' ) ),
			'a flow mapping'                  => array( array( '        with: { extension-type: payments, core-ref: main }' ) ),
			'a flow mapping starting with it' => array( array( '        with: {core-ref: main, extension-type: payments}' ) ),
			'a quoted key in a flow mapping'  => array( array( '        with: { "core-ref": main }' ) ),
			'a flow mapping over lines'       => array( array( '        with: {', '            extension-type: payments,', '            core-ref: main', '        }' ) ),
			'a quoted with:'                  => array( array( '        "with":', '            core-ref: main' ) ),
		);
	}

	/**
	 * Tests that a core-ref outside the `with:` mapping of the call is not an input and is not reported.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideKeysOutsideWith
	 *
	 * @param array<int, string> $extra Lines that follow the call's `with:` mapping, indented as they stand in the job.
	 */
	public function test_a_core_ref_outside_the_with_mapping_is_not_reported( array $extra ): void {
		$lines = array_merge( self::caller( array( 'on:', '    push:' ), array( 'extension-type: payments' ) ), $extra );
		$check = $this->check( array( 'planted.yml' => $lines ), self::PIN );

		$this->assertContains( true, array_map( static fn ( string $line ): bool => str_contains( $line, 'core-ref' ), $extra ), 'The case must state a core-ref.' );
		$this->assertSame( 0, $check['status'], $check['output'] );
	}

	/**
	 * Provides keys named core-ref that are not inputs of the call.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{array<int, string>}> The lines.
	 */
	public static function provideKeysOutsideWith(): array {
		return array(
			'a matrix entry in a flow list'  => array( array( '        strategy:', '            matrix:', '                include: [{core-ref: main}]' ) ),
			'a matrix entry in a block list' => array( array( '        strategy:', '            matrix:', '                include:', '                    - core-ref: main' ) ),
			'a key of the job'               => array( array( '        core-ref: main' ) ),
		);
	}

	/**
	 * Tests the callers a new extension starts from: all three pass, and the nightly passes its core-ref.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider provideTemplates
	 *
	 * @param string $template The template's file name in bin/dev/extension-template/.github/workflows.
	 */
	public function test_the_callers_a_new_extension_starts_from_pass( string $template ): void {
		$source   = self::template( $template );
		$rendered = str_replace( array( '{{core_ref}}', '{{type}}' ), array( self::PIN, 'payments' ), $source );
		$check    = $this->check( array( basename( $template, '.tmpl' ) => explode( "\n", $rendered ) ), self::PIN );

		$this->assertDoesNotMatchRegularExpression( '/\{\{[a-z_]+\}\}/', $rendered, "{$template} has a placeholder this test does not fill." );
		$this->assertSame( 0, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'check-workflows: PASS', $check['output'] );
	}

	/**
	 * Tests the shape the passing cases rely on: the nightly template passes core-ref and starts only on a schedule or by hand.
	 *
	 * Without it, the tests above would pass for a nightly that no longer exercises the rule.
	 *
	 * @since 0.2.0
	 */
	public function test_the_nightly_template_passes_core_ref_and_starts_only_on_a_schedule_or_by_hand(): void {
		$nightly = self::template( 'nightly.yml.tmpl' );

		$this->assertMatchesRegularExpression( '/^            core-ref: main$/m', $nightly, 'The nightly template must pass core-ref: main.' );
		$this->assertSame( 1, preg_match( '/^on:\n((?:[ \t]+.*\n|\n)+)/m', $nightly, $on ), 'The nightly template has no on: block.' );
		$this->assertMatchesRegularExpression( '/^    schedule:$/m', $on[1] );
		$this->assertMatchesRegularExpression( '/^    workflow_dispatch:$/m', $on[1] );
		$this->assertDoesNotMatchRegularExpression( '/^    (push|pull_request|pull_request_target):/m', $on[1] );
	}

	/**
	 * Tests that the reusable workflow still has the input these tests are about.
	 *
	 * @since 0.2.0
	 */
	public function test_the_reusable_workflow_declares_the_core_ref_input(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/extension-ci.yml' );

		$this->assertMatchesRegularExpression( '/^            core-ref:$/m', $workflow, 'extension-ci.yml declares no core-ref input: the rule has nothing to guard.' );
	}

	/**
	 * Provides the caller templates.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The template's file name.
	 */
	public static function provideTemplates(): array {
		return array(
			'ci'      => array( 'ci.yml.tmpl' ),
			'nightly' => array( 'nightly.yml.tmpl' ),
			'release' => array( 'release.yml.tmpl' ),
		);
	}

	/**
	 * Tests that only a call of SEOCart's workflows is reported, and only its own job's core-ref counts.
	 *
	 * @since 0.2.0
	 */
	public function test_a_core_ref_that_is_not_passed_to_a_call_of_seocarts_workflows_is_not_reported(): void {
		$lines = array(
			'name: Unrelated',
			'on:',
			'    pull_request:',
			'# core-ref: a comment names it, nothing passes it',
			'jobs:',
			'    ci:',
			'        uses: cirkuitnet/seocart/.github/workflows/extension-ci.yml@' . self::PIN,
			'        with:',
			'            extension-type: payments',
			'    other:',
			'        runs-on: ubuntu-24.04',
			'        steps:',
			'            - uses: ./.github/actions/anything',
			'              with:',
			'                  core-ref: main',
		);
		$check = $this->check( array( 'unrelated.yml' => $lines ), self::PIN );

		$this->assertSame( 0, $check['status'], $check['output'] );
	}

	/**
	 * Tests that the rule holds wherever the job states `uses:` and `with:`, and in SEOCart's own tree.
	 *
	 * @since 0.2.0
	 */
	public function test_core_ref_is_reported_wherever_it_stands_in_the_job_and_in_a_tree_without_a_pin(): void {
		$lines = array(
			'name: Planted',
			'on:',
			'    push:',
			'jobs:',
			'    ci:',
			'        with:',
			'            core-ref: main',
			'        uses: cirkuitnet/seocart/.github/workflows/extension-ci.yml@' . self::PIN . ' # v1',
		);

		$trees = array(
			'an extension'   => self::PIN,
			'SEOCart itself' => null,
		);

		foreach ( $trees as $tree => $pin ) {
			$check = $this->check( array( 'planted.yml' => $lines ), $pin );

			$this->assertSame( 1, $check['status'], "{$tree}: " . $check['output'] );
			$this->assertStringContainsString( 'planted.yml:7: passes core-ref', $check['output'], $tree );
		}
	}

	/**
	 * Returns a caller template of a new extension, after checking it has the shape the tests rely on.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name The template's file name in bin/dev/extension-template/.github/workflows.
	 * @return string The template's text.
	 */
	private static function template( string $name ): string {
		$source = (string) file_get_contents( dirname( __DIR__, 3 ) . '/bin/dev/extension-template/.github/workflows/' . $name );

		self::assertNotSame( '', $source, "The template {$name} is missing or empty." );
		self::assertStringContainsString( '{{core_ref}}', $source, "The template {$name} must name the pin through {{core_ref}}." );
		self::assertStringContainsString( 'cirkuitnet/seocart/.github/workflows/', $source, "The template {$name} must call a SEOCart workflow." );

		return $source;
	}

	/**
	 * Returns a workflow that calls SEOCart's reusable workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $on   The lines of the `on:` block.
	 * @param array<int, string> $with The inputs the call passes, unindented.
	 * @return list<string> The workflow's lines.
	 */
	private static function caller( array $on, array $with ): array {
		return array_merge(
			array( 'name: Caller' ),
			$on,
			array(
				'jobs:',
				'    ci:',
				'        uses: cirkuitnet/seocart/.github/workflows/extension-ci.yml@' . self::PIN,
				'        with:',
			),
			array_map( static fn ( string $input ): string => '            ' . $input, $with )
		);
	}

	/**
	 * Runs the gate over a tree.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, list<string>> $workflows File name to lines, for .github/workflows.
	 * @param string|null                 $pin        The commit seocart-core.env pins; null for a tree that is not an extension.
	 * @return array{status: int, output: string} The exit status and everything the script printed.
	 */
	private function check( array $workflows, ?string $pin ): array {
		$this->writeFile( 'tree/.git/HEAD', "ref: refs/heads/main\n" );

		foreach ( $workflows as $name => $lines ) {
			$this->writeFile( "tree/.github/workflows/{$name}", implode( "\n", $lines ) . "\n" );
		}

		if ( null !== $pin ) {
			$this->writeFile( 'tree/seocart-core.env', "SEOCART_CORE_REF={$pin}\n" );
		}

		foreach ( array( 'actionlint', 'shellcheck' ) as $tool ) {
			chmod( $this->writeFile( "tools/{$tool}", "#!/bin/sh\nexit 0\n" ), 0755 );
		}

		$process = proc_open(
			array( 'sh', dirname( __DIR__, 3 ) . '/bin/ci/check-workflows.sh', $this->directory . '/tree' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array( 'PATH' => $this->directory . '/tools:' . getenv( 'PATH' ) )
		);

		$this->assertIsResource( $process );

		$output = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'status' => proc_close( $process ),
			'output' => $output,
		);
	}
}
