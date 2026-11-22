<?php
/**
 * Tests that the workflow gate reports an extension's use of SEOCart's actions from GitHub instead of from the checkout beside it
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
 * Runs bin/ci/check-workflows.sh over small extension trees, and over SEOCart's own.
 *
 * An extension checks SEOCart out beside itself at the commit seocart-core.env pins, so it uses
 * SEOCart's composite actions from that checkout (./seocart/.github/actions/<name>); a use of them
 * from GitHub, at any ref, would be a second pin. The tools actionlint and shellcheck are not what
 * is tested here, so stand-ins that succeed take their place on PATH; the shared-facts check, an
 * awk program, is the part that runs for real.
 *
 * Planted violation, shown red and removed: the rule taken out of check-workflows.sh: the
 * extension's workflow using cirkuitnet/seocart/.github/actions/extension-live-setup@<40 hex> # v1
 * passes.
 *
 * @since 0.2.0
 */
final class CheckWorkflowsCoreActionsTest extends TestCase {

	use TemporaryDirectory;

	/**
	 * The commit the tree's seocart-core.env pins.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PIN = '0123456789abcdef0123456789abcdef01234567';

	/**
	 * Tests that a use of SEOCart's actions from GitHub, at any ref and in any form, is reported with the local path to use.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider remoteUses
	 *
	 * @param string $uses   The step's `uses:` line, as it stands in the job.
	 * @param string $action The action's name the report gives.
	 */
	public function test_a_remote_use_of_seocarts_actions_is_reported( string $uses, string $action ): void {
		$lines = self::workflow( $uses );
		$at    = array_search( $uses, $lines, true );
		$check = $this->check( $lines );

		$this->assertIsInt( $at );
		$this->assertSame( 1, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'planted.yml:' . ( $at + 1 ) . ': use SEOCart\'s actions from the checkout beside the extension: ./seocart/.github/actions/' . $action, $check['output'] );
		$this->assertStringContainsString( 'check-workflows: FAIL', $check['output'] );
	}

	/**
	 * Returns uses of SEOCart's actions from GitHub.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, string}> The `uses:` line and the action's name; by name.
	 */
	public static function remoteUses(): array {
		return array(
			'pinned to a commit' => array( '              uses: cirkuitnet/seocart/.github/actions/extension-live-setup@' . self::PIN . ' # v1', 'extension-live-setup' ),
			'at the pin itself'  => array( '              uses: cirkuitnet/seocart/.github/actions/extension-live-setup@' . self::PIN, 'extension-live-setup' ),
			'at a branch'        => array( '              uses: cirkuitnet/seocart/.github/actions/setup-php-composer@main', 'setup-php-composer' ),
			'at a tag'           => array( '              uses: cirkuitnet/seocart/.github/actions/extension-live-setup@v1', 'extension-live-setup' ),
			'in capitals'        => array( '              uses: CirkuitNet/SEOCart/.github/actions/extension-live-setup@' . self::PIN . ' # v1', 'extension-live-setup' ),
			'quoted'             => array( '              uses: "cirkuitnet/seocart/.github/actions/extension-live-setup@main"', 'extension-live-setup' ),
			'as a list item'     => array( '            - uses: cirkuitnet/seocart/.github/actions/extension-live-setup@main', 'extension-live-setup' ),
		);
	}

	/**
	 * Tests that the use of SEOCart's actions from the checkout beside the extension passes.
	 *
	 * @since 0.2.0
	 */
	public function test_the_local_use_of_seocarts_actions_passes(): void {
		$check = $this->check( self::workflow( '              uses: ./seocart/.github/actions/extension-live-setup' ) );

		$this->assertSame( 0, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'check-workflows: PASS', $check['output'] );
	}

	/**
	 * Tests that SEOCart's own tree, which holds the action and the fixture extension's workflow, passes.
	 *
	 * @since 0.2.0
	 */
	public function test_seocarts_own_tree_passes(): void {
		$check = $this->gate( dirname( __DIR__, 3 ) );

		$this->assertSame( 0, $check['status'], $check['output'] );
		$this->assertStringContainsString( 'check-workflows: PASS', $check['output'] );
	}

	/**
	 * Returns an extension's workflow whose one job uses an action.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uses The step's `uses:` line, as it stands in the job.
	 * @return list<string> The workflow's lines.
	 */
	private static function workflow( string $uses ): array {
		return array(
			'name: Live',
			'on:',
			'    workflow_dispatch:',
			'jobs:',
			'    live:',
			'        runs-on: ubuntu-24.04',
			'        steps:',
			'            - name: Check out SEOCart beside the extension',
			'              uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1',
			'              with:',
			'                  repository: cirkuitnet/seocart',
			'                  path: seocart',
			str_starts_with( ltrim( $uses ), '- ' ) ? $uses : '            - name: Set up',
			str_starts_with( ltrim( $uses ), '- ' ) ? '              name: Set up' : $uses,
		);
	}

	/**
	 * Runs the gate over an extension's tree holding one workflow.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $lines The workflow's lines.
	 * @return array{status: int, output: string} The exit status and everything the script printed.
	 */
	private function check( array $lines ): array {
		$this->writeFile( 'tree/.git/HEAD', "ref: refs/heads/main\n" );
		$this->writeFile( 'tree/.github/workflows/planted.yml', implode( "\n", $lines ) . "\n" );
		$this->writeFile( 'tree/seocart-core.env', 'SEOCART_CORE_REF=' . self::PIN . "\n" );

		return $this->gate( $this->directory . '/tree' );
	}

	/**
	 * Runs the gate over a tree, with stand-ins for actionlint and shellcheck.
	 *
	 * @since 0.2.0
	 *
	 * @param string $root The tree.
	 * @return array{status: int, output: string} The exit status and everything the script printed.
	 */
	private function gate( string $root ): array {
		foreach ( array( 'actionlint', 'shellcheck' ) as $tool ) {
			chmod( $this->writeFile( "tools/{$tool}", "#!/bin/sh\nexit 0\n" ), 0755 );
		}

		$process = proc_open(
			array( 'sh', dirname( __DIR__, 3 ) . '/bin/ci/check-workflows.sh', $root ),
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
