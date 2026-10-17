<?php
/**
 * Tests that a rule failing on a missing or stale tape names the tape first
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\Scenario;
use SEOCart\Tests\Conformance\Payment\Tape;

/**
 * A rule that fails after the tape could not answer a request names the tape first, whether the failure is an exception or an assertion about the outcome.
 *
 * A gateway's author meets a missing tape whenever a rule gains a scenario before its tape is
 * recorded, and a stale one whenever the gateway's requests change: the plugin turns the refused
 * request into an answer or an exception of its own, and only the tape's note says why.
 *
 * @since 0.2.0
 */
final class TapeNotesTest extends ConformanceHarness {

	use ReferenceGatewaySetup;

	/**
	 * Replays the reference tapes even in a recording run, which would otherwise record what these tests need missing or stale.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The plugin's transactions.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		return RecordingTransport::replaying( new Tape( self::TAPES ), $tx, $this->http );
	}

	/**
	 * Tests that a payment placed in a scenario with no tape fails the rule with "there is no tape" and how to record it.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_rule_names_the_missing_tape(): void {
		$message = $this->failureOf(
			Rule::LedgerShape,
			function ( Rule $rule ): void {
				$cell = $this->firstCell( $rule );
				$this->begin( 'never-recorded' );
				$this->place( $rule, Scenario::Approve, $cell );
				$this->end();
			}
		);

		$this->assertStringStartsWith( Rule::LedgerShape->message() . 'the tape: there is no tape of the scenario never-recorded in ', $message );
		$this->assertStringContainsString( 'record it with SEOCART_TAPE=record. That surfaced as: ', $message );
	}

	/**
	 * Tests that an assertion about the outcome that fails after a request differed from its tape names the tape first, and keeps its own words.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_a_failed_assertion_names_the_stale_tape_first(): void {
		$message = $this->failureOf(
			Rule::MatrixMatchesBehaviour,
			function ( Rule $rule ): void {
				$this->firstCell( $rule );
				$this->begin( 'rule01-money-path-usd-any' );

				try {
					$this->gateway()->capture( new CaptureRequest( 'not-on-the-tape', 'ri_404', Money::of( 3000, Currency::of( 'USD' ) ), Mode::Test ) );
				} catch ( \Throwable $refused ) {
					unset( $refused );
				}

				$this->assertSame( 'captured', 'authorized', $rule->message() . 'an assertion about the outcome, which fails before the scenario ends.' );
			},
			false
		);

		$this->assertStringStartsWith( Rule::MatrixMatchesBehaviour->message() . 'the tape: request 1 of rule01-money-path-usd-any differs from its tape in its path', $message );
		$this->assertStringContainsString( 'That surfaced as: an assertion about the outcome, which fails before the scenario ends.', $message );
	}

	/**
	 * Runs a rule's body and returns the message it fails with.
	 *
	 * @since 0.2.0
	 *
	 * @param Rule     $rule              The rule.
	 * @param \Closure $body              The body.
	 * @param bool     $needsDeclarations Whether the rule is skipped for a gateway that declares nothing.
	 * @return string The failure's message.
	 */
	private function failureOf( Rule $rule, \Closure $body, bool $needsDeclarations = true ): string {
		try {
			$this->rule( $rule, $body, $needsDeclarations );
		} catch ( AssertionFailedError $failed ) {
			return $failed->getMessage();
		}

		self::fail( 'The rule passed a scenario whose tape could not answer it.' );
	}
}
