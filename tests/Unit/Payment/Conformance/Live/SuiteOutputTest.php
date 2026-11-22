<?php
/**
 * Tests the check of the conformance suite's run and of a part's live tests against a provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance\Live;

use PHPUnit\Framework\TestCase;
use SEOCart\Tests\Conformance\Payment\Live\SuiteOutput;

/**
 * The run passes the check only when the unit group passed and the extension's test ran the tests the declaration states with exactly the skips it states, each for the scenario its reason names; a part's live tests pass it only when exactly the part's tests ran and passed.
 *
 * Planted violations, each shown red and removed: in SuiteOutput::conformanceProblems(), the
 * declared count read as 16: the expected output fails; the skip set's check removed: the output
 * with a third skip passes; in SuiteOutput::liveProblems(), the part's count not checked: a
 * payments run of 5 tests passes.
 *
 * @since 0.2.0
 */
final class SuiteOutputTest extends TestCase {

	/**
	 * Tests that the output of a run as the replay runs passes, with its counts read.
	 *
	 * @since 0.2.0
	 */
	public function test_the_expected_run_passes(): void {
		$output    = self::output();
		$summaries = SuiteOutput::summaries( $output );

		$this->assertSame( array(), SuiteOutput::conformanceProblems( StatedDeclaration::fixture(), $output ) );
		$this->assertSame( array( 17, 307, 2 ), array( $summaries[1]['tests'], $summaries[1]['assertions'], $summaries[1]['skipped'] ) );
		$this->assertSame( array_keys( StatedDeclaration::fixture()->suiteSkips() ), array_keys( SuiteOutput::skipped( $output ) ) );
	}

	/**
	 * Tests that the skips are compared as a set: a declaration that lists them in another order passes the same run.
	 *
	 * @since 0.2.0
	 */
	public function test_the_skips_are_compared_in_any_order(): void {
		$declaration = StatedDeclaration::with( array( 'suiteSkips' => array_reverse( StatedDeclaration::fixture()->suiteSkips() ) ) );

		$this->assertSame( array(), SuiteOutput::conformanceProblems( $declaration, self::output() ) );
	}

	/**
	 * Tests that a third skip, one the declaration does not name, fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_a_third_skip_fails(): void {
		$output = self::output(
			array(
				'test_rule_09_expiry'                    => 'expires',
				'test_rule_10_webhooks_verified_first'   => null,
				'test_rule_17_void_answers_the_approval' => 'requires_action_completed',
			)
		);

		$this->assertContains( 'The skipped tests are test_rule_09_expiry, test_rule_10_webhooks_verified_first, test_rule_17_void_answers_the_approval, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.', SuiteOutput::conformanceProblems( StatedDeclaration::fixture(), $output ) );
	}

	/**
	 * Tests that rule 17 run, rather than skipped, fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_17_run_fails(): void {
		$this->assertContains( 'The skipped tests are test_rule_09_expiry, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.', SuiteOutput::conformanceProblems( StatedDeclaration::fixture(), self::output( array( 'test_rule_09_expiry' => 'expires' ) ) ) );
	}

	/**
	 * Tests that another count than the declared one fails the check, as does a declaration of another count against this run.
	 *
	 * @since 0.2.0
	 */
	public function test_another_count_fails(): void {
		$this->assertContains( 'The extension\'s conformance test ran 16 tests, not 17.', SuiteOutput::conformanceProblems( StatedDeclaration::fixture(), str_replace( 'Tests: 17,', 'Tests: 16,', self::output() ) ) );
		$this->assertContains( 'The extension\'s conformance test ran 17 tests, not 18.', SuiteOutput::conformanceProblems( StatedDeclaration::with( array( 'suiteTests' => 18 ) ), self::output() ) );
	}

	/**
	 * Tests that a declared skip for another reason, a failure, or a unit group that failed, fails the check.
	 *
	 * @since 0.2.0
	 */
	public function test_another_reason_a_failure_or_a_failed_unit_group_fails(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertContains(
			'The test test_rule_17_void_answers_the_approval was skipped for another reason than the scenario requires_action_completed, which the provider\'s test environment cannot produce.',
			SuiteOutput::conformanceProblems(
				$declaration,
				self::output(
					array(
						'test_rule_09_expiry' => 'expires',
						'test_rule_17_void_answers_the_approval' => null,
					)
				)
			)
		);

		$failed = str_replace( 'Tests: 17, Assertions: 307, Skipped: 2.', 'Tests: 17, Assertions: 290, Failures: 1, Skipped: 2.', self::output() );
		$this->assertContains( 'The extension\'s conformance test has 1 failures, 0 errors, 0 incomplete, 0 risky and 0 warnings.', SuiteOutput::conformanceProblems( $declaration, $failed ) );

		$unit = str_replace( 'OK (40 tests, 120 assertions)', "FAILURES!\nTests: 40, Assertions: 119, Failures: 1.", self::output() );
		$this->assertContains( 'The unit group did not pass whole.', SuiteOutput::conformanceProblems( $declaration, $unit ) );

		$this->assertSame( array( 'The run printed 1 PHPUnit summaries, not 2: the unit group and the extension\'s conformance test.' ), SuiteOutput::conformanceProblems( $declaration, 'OK (40 tests, 120 assertions)' ) );
	}

	/**
	 * Tests that a run of the live tests passes only when every test ran and passed: a skip, a failure or no test at all fails it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_run_with_a_skip_or_a_failure_fails(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( array(), SuiteOutput::liveProblems( $declaration, "PHPUnit 9.6.36\n\n......  6 / 6 (100%)\n\nOK (6 tests, 60 assertions)\n", 'payments' ) );
		$this->assertSame( array(), SuiteOutput::liveProblems( $declaration, "PHPUnit 9.6.36\n\n...  3 / 3 (100%)\n\nOK (3 tests, 90 assertions)\n", 'settled-refunds' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( $declaration, "OK, but incomplete, skipped, or risky tests!\nTests: 6, Assertions: 40, Skipped: 1.\n", 'payments' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( $declaration, "FAILURES!\nTests: 6, Assertions: 40, Failures: 1.\n", 'payments' ) );
		$this->assertNotSame( array(), SuiteOutput::liveProblems( $declaration, "No tests executed!\n", 'payments' ) );
	}

	/**
	 * Tests that a part's run of one test fewer, or one more, than the part holds fails, however green PHPUnit called it; and that an unknown part fails.
	 *
	 * @since 0.2.0
	 */
	public function test_a_live_run_of_another_count_than_the_parts_fails(): void {
		$declaration = StatedDeclaration::fixture();

		$this->assertSame( array( 'The live tests of payments ran 5 tests, not 6.' ), SuiteOutput::liveProblems( $declaration, "OK (5 tests, 45 assertions)\n", 'payments' ) );
		$this->assertSame( array( 'The live tests of webhooks ran 1 tests, not 2.' ), SuiteOutput::liveProblems( $declaration, "OK (1 tests, 60 assertions)\n", 'webhooks' ) );
		$this->assertSame( array( 'The live tests of webhooks ran 3 tests, not 2.' ), SuiteOutput::liveProblems( $declaration, "OK (3 tests, 60 assertions)\n", 'webhooks' ) );
		$this->assertSame( array( 'The live tests have no part conformance: payments, settled-refunds, webhooks.' ), SuiteOutput::liveProblems( $declaration, "OK (17 tests, 307 assertions)\n", 'conformance' ) );
	}

	/**
	 * Returns what `extension.sh conformance . payments` prints, as PHPUnit 9 prints it, with the skips given.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string|null>|null $skips Each skipped test, by name, with the scenario its reason names, or null for another reason; null for the declared ones.
	 * @return string The output.
	 */
	private static function output( ?array $skips = null ): string {
		$skips ??= StatedDeclaration::fixture()->suiteSkips();
		$text    = "> @test:unit --group conformance --fail-on-empty-test-suite\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\n" . str_repeat( '.', 40 ) . "  40 / 40 (100%)\n\nTime: 00:01.234, Memory: 40.00 MB\n\nOK (40 tests, 120 assertions)\n";
		$text   .= "> @php tests/Conformance/Payment/integration.php\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.4.27\n\n";
		$text   .= str_repeat( '.', 17 - count( $skips ) ) . str_repeat( 'S', count( $skips ) ) . "  17 / 17 (100%)\n\nTime: 00:30.000, Memory: 80.00 MB\n\n";
		$text   .= sprintf( "There %s %d skipped test%s:\n\n", 1 === count( $skips ) ? 'was' : 'were', count( $skips ), 1 === count( $skips ) ? '' : 's' );
		$number  = 0;

		foreach ( $skips as $test => $scenario ) {
			$reason = null === $scenario ? 'Rule 10: a webhook delivery is verified before it is read: skipped: the gateway declares no webhooks.' : sprintf( 'Rule 0: the rule: skipped: the provider cannot produce the scenario %s in test mode: a reason.', $scenario );
			$text  .= sprintf( "%1\$d) Example\\GatewayForFixturePay\\Tests\\Integration\\ConformanceTest::%2\$s\n%3\$s\n\n", ++$number, $test, $reason );
		}

		return $text . sprintf( "OK, but incomplete, skipped, or risky tests!\nTests: 17, Assertions: 307, Skipped: %d.\n", count( $skips ) );
	}
}
