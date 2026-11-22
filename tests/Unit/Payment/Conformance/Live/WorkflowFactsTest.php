<?php
/**
 * Tests the facts a payment extension's live workflow must keep, on the fixture extension's
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\WorkflowFacts;
use SEOCart\Tools\Packaging\Tests\TemporaryDirectory;

/**
 * The fixture's live workflow starts only on its schedule or by hand, has no job named ci, reads the repository and nothing more, runs each job only in the extension's repository, gives a secret only to a step's env, never traces the shell, prints the environment or uploads a file, runs one at a time uncancelled in the group its basename names, limits every job's time, has as its jobs and as the choices of its parts input the parts its declaration states and no other, and checks SEOCart out in the verdict's job before the verdict's step. Each fact is planted broken, in memory, and found; and a class of SEOCart's left in an extension's tests is found.
 *
 * Planted violations, each shown red and removed: in WorkflowFacts::secrets(), only the lines
 * holding `secrets.` read, and no comment's line: the bracket form, `toJSON(secrets)`, the
 * capitals and the shell comment are not found; an expression ended at its first `}}`, quoted or
 * not: the secrets after a quoted `}}` are not found.
 *
 * @since 0.2.0
 */
final class WorkflowFactsTest extends TestCase {

	use Plants;
	use TemporaryDirectory;

	/**
	 * Tests that the fixture's workflow as committed keeps every fact.
	 *
	 * @since 0.2.0
	 */
	public function test_the_workflow_keeps_every_fact(): void {
		$this->assertSame( array(), WorkflowFacts::violations( StatedDeclaration::fixture(), self::workflow() ) );
	}

	/**
	 * Tests that each fact, planted broken, is found.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider plants
	 *
	 * @param string   $expected Text of the violation expected.
	 * @param \Closure $plant    The plant: the lines in, the lines broken out.
	 */
	public function test_each_fact_planted_broken_is_found( string $expected, \Closure $plant ): void {
		$found = implode( "\n", WorkflowFacts::violations( StatedDeclaration::fixture(), $plant( self::workflow() ) ) );

		$this->assertStringContainsString( $expected, $found );
	}

	/**
	 * Returns one plant per fact.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string, \Closure}> Each violation expected and its plant, by name.
	 */
	public static function plants(): array {
		$repository = StatedDeclaration::fixture()->repository();
		$heads      = '/^              run: sh seocart\/bin\/ci\/extension-live.sh heads ext$/';
		$jobs       = 'checkout, live-conformance, payments, settled-refunds, verdict, webhooks';

		return array(
			'a push trigger'                 => array( 'The workflow starts on push, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    push:' ) ),
			'a pull request trigger'         => array( 'The workflow starts on pull_request, schedule, workflow_dispatch', static fn( array $lines ): array => self::after( $lines, '/^on:$/', '    pull_request:' ) ),
			'a job named ci'                 => array( 'a job is named ci', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    ci:' ) ),
			'a wider permission'             => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    contents: read$/', '    issues: write' ) ),
			'a job writing everything'       => array( 'the permissions are not exactly `contents: read`', static fn( array $lines ): array => self::after( $lines, '/^    verdict:$/', '        permissions: write-all' ) ),
			'a job without the guard'        => array( 'the job payments does not run only in ' . $repository, static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"payments"/', "        if: contains(fromJSON('[\"all\", \"payments\"]'), inputs.parts || 'all')" ) ),
			'a secret in a run'              => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets.FIXTURE_PAY_API_KEY }}"' ) ),
			'a secret in a job\'s env'       => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::after( $lines, '/^        timeout-minutes: 30$/', '        env:' ), '/^        env:$/', '            FIXTURE_KEY: ${{ secrets.FIXTURE_PAY_API_KEY }}' ) ),
			'a secret in an action\'s with'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  persist-credentials: false$/', '                  token: ${{ secrets.FIXTURE_PAY_API_KEY }}' ) ),
			'a secret in brackets in a run'  => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ secrets[\'FIXTURE_PAY_API_KEY\'] }}"' ) ),
			'a secret in brackets in env'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  FIXTURE_PAY_API_KEY: /', '                  FIXTURE_PAY_API_KEY: ${{ secrets[\'FIXTURE_PAY_API_KEY\'] }}' ) ),
			'every secret as JSON'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, '/^                  SEOCART_LIVE_NEEDS: /', '                  SEOCART_LIVE_NEEDS: ${{ toJSON(secrets) }}' ) ),
			'a secret in capitals'           => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ SECRETS.FIXTURE_PAY_API_KEY }}"' ) ),
			'a secret in a shell comment'    => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( $lines, '/^                  if \[ -n "\$CORE_REF" \]; then$/', '                  # ${{ secrets.FIXTURE_PAY_API_KEY }}' ) ),
			'a secret over two lines'        => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::after( self::replaced( $lines, $heads, '              run: echo "${{' ), '/^              run: echo "\$\{\{$/', '                  secrets.FIXTURE_PAY_API_KEY }}"' ) ),
			'a secret after a quoted }}'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'}}{0}\', secrets.FIXTURE_PAY_API_KEY) }}"' ) ),
			'a quoted }} past an escape'     => array( 'a secret is used other than as the value of an entry of a step\'s env', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: echo "${{ format(\'it\'\'s }}{0}\', secrets.FIXTURE_PAY_API_KEY) }}"' ) ),
			'a traced shell'                 => array( 'holds `set -x`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: set -x; sh seocart/bin/ci/extension-live.sh heads ext' ) ),
			'the environment printed'        => array( 'holds `printenv`', static fn( array $lines ): array => self::replaced( $lines, $heads, '              run: printenv' ) ),
			'an upload'                      => array( 'holds `upload-artifact`', static fn( array $lines ): array => self::after( $lines, '/^            - \*checkout-extension$/', '            - uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4.6.2' ) ),
			'a run cancelled by the next'    => array( 'with cancel-in-progress: false', static fn( array $lines ): array => self::replaced( $lines, '/^    cancel-in-progress: false$/', '    cancel-in-progress: true' ) ),
			'another concurrency group'      => array( 'is not the group fixture-pay-sandbox', static fn( array $lines ): array => self::replaced( $lines, '/^    group: fixture-pay-sandbox$/', '    group: ${{ github.workflow }}-${{ github.ref }}' ) ),
			'a job without a time limit'     => array( 'the job verdict has no timeout-minutes', static fn( array $lines ): array => self::without( $lines, '/^        timeout-minutes: 5$/' ) ),
			'a cli job'                      => array( 'The jobs are checkout, cli, live-conformance, payments, settled-refunds, verdict, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^jobs:$/', '    cli:' ) ),
			'a job removed'                  => array( 'The jobs are checkout, live-conformance, payments, settled-refunds, verdict, not exactly', static fn( array $lines ): array => self::without( $lines, '/^    webhooks:$/' ) ),
			'a cli choice'                   => array( 'The parts input offers all, checkout, cli, conformance, payments, settled-refunds, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli' ) ),
			'a part without a choice'        => array( 'The parts input offers all, checkout, conformance, payments, settled-refunds, not exactly', static fn( array $lines ): array => self::without( $lines, '/^                    - webhooks$/' ) ),
			'a verdict missing a need'       => array( 'the verdict needs live-conformance, payments, not exactly checkout, live-conformance, payments, settled-refunds, webhooks.', static fn( array $lines ): array => self::replaced( $lines, '/^        needs: /', '        needs: [live-conformance, payments]' ) ),
			'a condition without all'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == '{$repository}' && contains(fromJSON('[\"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition on another part'    => array( 'the job payments has no condition fromJSON(\'["all", "payments"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"payments"/', "        if: github.repository == '{$repository}' && contains(fromJSON('[\"all\", \"cli\"]'), inputs.parts || 'all')" ) ),
			'a condition on no part'         => array( 'the job live-conformance has no condition fromJSON(\'["all", "conformance"]\')', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"conformance"/', "        if: github.repository == '{$repository}'" ) ),
			'a verdict job removed'          => array( 'The jobs are checkout, live-conformance, payments, settled-refunds, webhooks, not exactly ' . $jobs . '.', static fn( array $lines ): array => self::without( $lines, '/^    verdict:$/' ) ),
			'a verdict without always'       => array( 'its if must hold always()', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == '{$repository}'" ) ),
			'a cli choice with a comment'    => array( 'The parts input offers all, checkout, cli, conformance, payments, settled-refunds, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli # back' ) ),
			'a condition with no fallback'   => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == '{$repository}' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts)" ) ),
			'a condition and false'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == '{$repository}' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all') && false" ) ),
			'a condition false first'        => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: false && github.repository == '{$repository}' && contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a negated condition'            => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == '{$repository}' && !contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a condition in a comment'       => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: .*"webhooks"/', "        if: github.repository == '{$repository}' # contains(fromJSON('[\"all\", \"webhooks\"]'), inputs.parts || 'all')" ) ),
			'a continued condition'          => array( 'the job webhooks has no condition fromJSON(\'["all", "webhooks"]\'), written exactly', static fn( array $lines ): array => self::after( $lines, '/^        if: .*"webhooks"/', '            && false' ) ),
			'a verdict that never runs'      => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: always() && github.repository == '{$repository}' && false" ) ),
			'always only in a comment'       => array( 'its if must hold always(), written exactly', static fn( array $lines ): array => self::replaced( $lines, '/^        if: always\(\) && /', "        if: github.repository == '{$repository}' # always()" ) ),
			'a choice with a space'          => array( 'The parts input offers - cli session, all, checkout, conformance, payments, settled-refunds, webhooks, not exactly', static fn( array $lines ): array => self::after( $lines, '/^                    - webhooks$/', '                    - cli session' ) ),
			'a choice with a hash'           => array( 'The parts input offers all, checkout, conformance, payments, settled-refunds, webhooks#2, not exactly', static fn( array $lines ): array => self::replaced( $lines, '/^                    - webhooks$/', '                    - webhooks#2' ) ),
			'the verdict without SEOCart'    => array( 'the verdict does not check SEOCart (cirkuitnet/seocart) out before its step', static fn( array $lines ): array => self::without( $lines, '/^            - \*checkout-core$/', true ) ),
			'SEOCart checked out after it'   => array( 'the verdict does not check SEOCart (cirkuitnet/seocart) out before its step', static fn( array $lines ): array => self::after( self::without( $lines, '/^            - \*checkout-core$/', true ), '/^                  SEOCART_LIVE_PARTS: /', '            - *checkout-core' ) ),
			'another repository checked out' => array( 'the verdict does not check SEOCart (cirkuitnet/seocart) out before its step', static fn( array $lines ): array => self::replaced( $lines, '/^                  repository: cirkuitnet\/seocart$/', '                  repository: cirkuitnet/seocart-fork' ) ),
		);
	}

	/**
	 * Tests that a workflow whose expressions cannot be read is a violation, not a pass.
	 *
	 * The regular expression gives up, and returns false, when PCRE's backtrack limit is lowered and
	 * its JIT is off. A pattern already compiled in this process keeps its JIT, so a PHP process of
	 * its own, with the JIT off from its start, reads the workflow once the declaration is loaded and
	 * the limit lowered.
	 *
	 * @since 0.2.0
	 */
	public function test_expressions_that_cannot_be_read_are_a_violation(): void {
		$code = sprintf(
			'foreach ( glob( %1$s ) as $class ) { require_once $class; } $declaration = \SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration::load( %2$s ); ini_set( "pcre.backtrack_limit", "10" ); echo implode( "\n", \%3$s::violations( $declaration, file( %4$s, FILE_IGNORE_NEW_LINES ) ) );',
			var_export( dirname( __DIR__, 4 ) . '/Conformance/Payment/Live/[A-Z]*.php', true ),
			var_export( StatedDeclaration::FIXTURE, true ),
			WorkflowFacts::class,
			var_export( StatedDeclaration::FIXTURE . '/.github/workflows/fixture-pay-sandbox.yml', true )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- a PHP process of its own, run with fixed settings.
		exec( escapeshellarg( PHP_BINARY ) . ' -d pcre.jit=0 -d max_execution_time=30 -r ' . escapeshellarg( $code ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertStringContainsString( 'The expressions of the workflow could not be read', implode( "\n", $output ) );
	}

	/**
	 * Tests that an extension's tests that hold no class of SEOCart's live workflow hold no copy.
	 *
	 * @since 0.2.0
	 */
	public function test_the_fixture_holds_no_copy(): void {
		$this->assertSame( array(), WorkflowFacts::copies( StatedDeclaration::FIXTURE ) );
	}

	/**
	 * Tests that a class of SEOCart's live workflow left in an extension's tests is found, by its short name, whatever its namespace; and that a dependency's copy and a class of another name are not.
	 *
	 * @since 0.2.0
	 */
	public function test_a_copy_left_behind_is_found(): void {
		$this->writeFile( 'extension/tests/Support/Live/Verdict.php', "<?php\nnamespace Example\\GatewayForFixturePay\\Tests\\Support\\Live;\n\nfinal class Verdict {\n}\n" );
		$this->writeFile( 'extension/tests/Support/Live/Steps.php', "<?php\nabstract class WorkflowStep {\n}\nclass VerdictTable {\n}\n" );
		$this->writeFile( 'extension/tests/browser/node_modules/x/Verdict.php', "<?php\nclass Verdict {\n}\n" );
		$this->writeFile( 'extension/tests/live.php', "<?php\nreturn new class() extends LiveDeclaration {\n};\n" );

		$this->assertSame(
			array(
				'tests/Support/Live/Steps.php declares the class WorkflowStep, which SEOCart holds in tests/Conformance/Payment/Live: a copy left behind drifts from it.',
				'tests/Support/Live/Verdict.php declares the class Verdict, which SEOCart holds in tests/Conformance/Payment/Live: a copy left behind drifts from it.',
			),
			WorkflowFacts::copies( $this->directory . '/extension' )
		);
	}

	/**
	 * Returns the fixture's workflow's lines.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string> The lines.
	 */
	private static function workflow(): array {
		return self::linesOf( StatedDeclaration::FIXTURE . '/.github/workflows/fixture-pay-sandbox.yml' );
	}
}
