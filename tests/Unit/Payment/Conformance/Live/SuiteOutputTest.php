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
use SEOCart\Tests\Conformance\Payment\Live\LiveDeclaration;
use SEOCart\Tests\Conformance\Payment\Live\SuiteOutput;

/**
 * The run passes the check only when the unit group passed and the extension's test ran the tests the declaration states with exactly the skips it states, each for the scenario its reason names; a part's live tests pass it only when exactly the part's tests ran and passed.
 *
 * Planted violations, each shown red and removed: in SuiteOutput::conformanceProblems(), the
 * declared count read as 16: the expected output fails; the skip set's check removed: the output
 * with a third skip passes; in SuiteOutput::liveProblems(), the part's count not checked: a
 * payments run of 5 tests passes. For a skip because the matrix declares no cell of an
 * operation: the declared operations left out of the expected skips: the run of a gateway without
 * `sca` fails; the reason's check removed: each other reason passes; the operation not read from
 * the reason: another operation's words pass; the words not anchored at the reason's end: words
 * with more after them pass; every skip in those words taken as declared: the fixture, whose
 * matrix declares `sca`, passes rule 11 skipped in them.
 *
 * @since 0.2.0
 */
final class SuiteOutputTest extends TestCase {

	/**
	 * The currencies the conformance suite's test store sells, as its skips name them.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const SELLABLE = array( 'USD', 'GBP', 'EUR' );

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
	 * Tests that the run of a gateway whose matrix declares no `sca` passes, with rules 11 and 17 skipped in the suite's words for that operation, as its declaration states; a declaration states none by default.
	 *
	 * @since 0.2.0
	 */
	public function test_rules_skipped_for_an_operation_the_matrix_does_not_declare_pass(): void {
		$this->assertSame( array(), SuiteOutput::conformanceProblems( self::withoutSca(), self::outputOf( self::reasonsWithoutSca() ) ) );
		$this->assertSame( array(), StatedDeclaration::fixture()->suiteUndeclared() );
	}

	/**
	 * Tests that the suite's words for an operation the matrix does not declare pass only the skips the declaration states so: not where a matrix declares `sca`, as the fixture's does, and a declaration of no `sca` not against the run of a matrix that declares it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_words_for_an_undeclared_operation_fail_where_the_matrix_declares_it(): void {
		$words = self::reasonsWithoutSca();

		$this->assertSame(
			array( 'The test test_rule_17_void_answers_the_approval was skipped for another reason than the scenario requires_action_completed, which the provider\'s test environment cannot produce.' ),
			SuiteOutput::conformanceProblems(
				StatedDeclaration::fixture(),
				self::outputOf(
					array(
						'test_rule_09_expiry' => self::scenarioReason( 'expires' ),
						'test_rule_17_void_answers_the_approval' => $words['test_rule_17_void_answers_the_approval'],
					)
				)
			)
		);

		$this->assertContains(
			'The skipped tests are test_rule_09_expiry, test_rule_11_next_action_shape, test_rule_17_void_answers_the_approval, not exactly test_rule_09_expiry, test_rule_17_void_answers_the_approval.',
			SuiteOutput::conformanceProblems( StatedDeclaration::fixture(), self::outputOf( array( 'test_rule_17_void_answers_the_approval' => self::scenarioReason( 'requires_action_completed' ) ) + $words ) )
		);

		$ran = SuiteOutput::conformanceProblems(
			self::withoutSca(),
			self::outputOf(
				array(
					'test_rule_09_expiry' => self::scenarioReason( 'expires' ),
					'test_rule_17_void_answers_the_approval' => self::scenarioReason( 'requires_action_completed' ),
				)
			)
		);

		$this->assertContains( 'The skipped tests are test_rule_09_expiry, test_rule_17_void_answers_the_approval, not exactly test_rule_09_expiry, test_rule_11_next_action_shape, test_rule_17_void_answers_the_approval.', $ran );
		$this->assertContains( 'The test test_rule_17_void_answers_the_approval was skipped for another reason than the operation sca, which no cell of the gateway\'s matrix declares.', $ran );
	}

	/**
	 * Tests that a rule the declaration says is skipped for an operation the matrix does not declare fails when it is skipped for anything else.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider otherReasons
	 *
	 * @param string $reason The rule's reason.
	 */
	public function test_a_skip_for_an_undeclared_operation_for_another_reason_fails( string $reason ): void {
		$this->assertSame(
			array( 'The test test_rule_11_next_action_shape was skipped for another reason than the operation sca, which no cell of the gateway\'s matrix declares.' ),
			SuiteOutput::conformanceProblems( self::withoutSca(), self::outputOf( array( 'test_rule_11_next_action_shape' => $reason ) + self::reasonsWithoutSca() ) )
		);
	}

	/**
	 * Returns reasons rule 11 is skipped for other than its operation `sca`, which the matrix does not declare.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{string}> The reasons; by name.
	 */
	public static function otherReasons(): array {
		$rule = 'Conformance rule 11 (next action shape): ';

		return array(
			'an arbitrary reason'        => array( $rule . 'skipped: not today.' ),
			'the scenario not produced'  => array( $rule . 'skipped: the provider cannot produce the scenario requires_action in test mode: a reason.' ),
			'another operation'          => array( $rule . SuiteOutput::undeclared( self::SELLABLE, 'void' ) ),
			'an operation sca begins'    => array( $rule . SuiteOutput::undeclared( self::SELLABLE, 'scab' ) ),
			'no currency named'          => array( $rule . SuiteOutput::undeclared( array(), 'sca' ) ),
			'more after the words'       => array( $rule . SuiteOutput::undeclared( self::SELLABLE, 'sca' ) . ' Run it elsewhere.' ),
			'the words in the rule name' => array( SuiteOutput::undeclared( self::SELLABLE, 'sca' ) . ' skipped: not today.' ),
		);
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

		return self::outputOf( array_map( static fn( ?string $scenario ): string => null === $scenario ? 'Rule 10: a webhook delivery is verified before it is read: skipped: the gateway declares no webhooks.' : self::scenarioReason( $scenario ), $skips ) );
	}

	/**
	 * Returns what `extension.sh conformance . payments` prints, as PHPUnit 9 prints it, with each skipped test's reason given.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $reasons Each skipped test's reason, by name.
	 * @return string The output.
	 */
	private static function outputOf( array $reasons ): string {
		$text   = "> @test:unit --group conformance --fail-on-empty-test-suite\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\n" . str_repeat( '.', 40 ) . "  40 / 40 (100%)\n\nTime: 00:01.234, Memory: 40.00 MB\n\nOK (40 tests, 120 assertions)\n";
		$text  .= "> @php tests/Conformance/Payment/integration.php\nPHPUnit 9.6.36 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.4.27\n\n";
		$text  .= str_repeat( '.', 17 - count( $reasons ) ) . str_repeat( 'S', count( $reasons ) ) . "  17 / 17 (100%)\n\nTime: 00:30.000, Memory: 80.00 MB\n\n";
		$text  .= sprintf( "There %s %d skipped test%s:\n\n", 1 === count( $reasons ) ? 'was' : 'were', count( $reasons ), 1 === count( $reasons ) ? '' : 's' );
		$number = 0;

		foreach ( $reasons as $test => $reason ) {
			$text .= sprintf( "%1\$d) Example\\GatewayForFixturePay\\Tests\\Integration\\ConformanceTest::%2\$s\n%3\$s\n\n", ++$number, $test, $reason );
		}

		return $text . sprintf( "OK, but incomplete, skipped, or risky tests!\nTests: 17, Assertions: 307, Skipped: %d.\n", count( $reasons ) );
	}

	/**
	 * Returns the reason the suite skips a rule with when the provider cannot produce its scenario.
	 *
	 * @since 0.2.0
	 *
	 * @param string $scenario The scenario.
	 * @return string The reason.
	 */
	private static function scenarioReason( string $scenario ): string {
		return sprintf( 'Rule 0: the rule: skipped: the provider cannot produce the scenario %s in test mode: a reason.', $scenario );
	}

	/**
	 * Returns the fixture's declaration as a gateway whose matrix declares no `sca` states its skips: rule 9 for its scenario, rules 11 and 17 for the operation.
	 *
	 * @since 0.2.0
	 *
	 * @return LiveDeclaration The declaration.
	 */
	private static function withoutSca(): LiveDeclaration {
		return StatedDeclaration::with(
			array(
				'suiteSkips'      => array( 'test_rule_09_expiry' => 'expires' ),
				'suiteUndeclared' => array(
					'test_rule_11_next_action_shape' => 'sca',
					'test_rule_17_void_answers_the_approval' => 'sca',
				),
			)
		);
	}

	/**
	 * Returns the reasons the suite gives the skips of a gateway whose matrix declares no `sca`: rule 9's scenario, and for rules 11 and 17 the words the suite says the operation with.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Each reason, by test.
	 */
	private static function reasonsWithoutSca(): array {
		return array(
			'test_rule_09_expiry'                    => self::scenarioReason( 'expires' ),
			'test_rule_11_next_action_shape'         => 'Conformance rule 11 (next action shape): ' . SuiteOutput::undeclared( self::SELLABLE, 'sca' ),
			'test_rule_17_void_answers_the_approval' => 'Conformance rule 17 (void answers an approval already given): ' . SuiteOutput::undeclared( self::SELLABLE, 'sca' ),
		);
	}
}
