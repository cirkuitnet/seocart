<?php
/**
 * GatewayConformanceCase: the payment gateway conformance suite, as a test case a gateway's own test extends
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

/**
 * The payment gateway conformance suite: every rule a gateway is held to, run against the gateway its plugin registers.
 *
 * Owns one fact: how a gateway is proved against the public payment contract. A gateway's test
 * extends this case and names two things, the id its plugin registers the gateway under and the
 * directory of its tapes:
 *
 *     final class ConformanceTest extends GatewayConformanceCase {
 *         protected function gatewayId(): string { return Gateway::ID; }
 *         protected function tapes(): string { return dirname( __DIR__ ) . '/tapes'; }
 *     }
 *
 * The case builds the plugin as it runs on a site, with three things swapped where the plugin
 * hands them to a gateway: the outbound transport under the gateway's HTTP client (a
 * RecordingTransport, which replays the provider's recorded answers or, with SEOCART_TAPE=record,
 * records them), the gateway's logger (an AssertingLogger) and its clock (frozen, moved by the
 * webhook rule). The plugin registers the gateway through its own registration listener, so the
 * gateway the case drives is the one the plugin's payment service resolves. Ids are seeded per
 * scenario, with the recording's nonce when it has one (SEOCART_TAPE_NONCE), and amounts are
 * fixed, so a scenario sends the same bytes every run.
 *
 * Each rule is one test; its failure begins with the rule's message (Rule::message()). A rule
 * that needs something the gateway does not declare is skipped and says so: a gateway whose
 * capability matrix has no rows skips every rule that places a payment ("declares nothing yet").
 * A gateway that declares rows names a payment token for each scenario a rule places (tokens());
 * a scenario its provider cannot produce is named NotProducible, and the rules that need it are
 * skipped with that reason. Rules 4 and 15 are proved without WordPress, in the unit suite's
 * conformance group; rule 18 by the extension kit's idle step.
 *
 * @since 0.2.0
 */
abstract class GatewayConformanceCase extends ConformanceHarness {

	/**
	 * Rule 1: the ledger an authorization, a capture, a partial and a full refund leave.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_01_ledger_shape(): void {
		$this->check( Rule::LedgerShape );
	}

	/**
	 * Rule 2: every declared operation succeeds; every undeclared one is refused before any request.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_02_matrix_matches_behaviour(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
	}

	/**
	 * Rule 3: never offered where the cell lacks `authorize`; isAvailable() pure.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_03_availability_never_looser(): void {
		$this->check( Rule::AvailabilityNeverLooser );
	}

	/**
	 * Rule 5: every answer reports the amount and currency asked.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_05_amounts_echoed(): void {
		$this->check( Rule::AmountsEchoed );
	}

	/**
	 * Rule 6: every money request carries the key derived for it, and a repeated one the same.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_06_keys_as_ruled(): void {
		$this->check( Rule::KeysAsRuled );
	}

	/**
	 * Rule 7: a capture or void asked again after its answer was lost answers the same.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_07_already_done_answers_the_same(): void {
		$this->check( Rule::AlreadyDoneAnswersTheSame );
	}

	/**
	 * Rule 8: who may say "not found", and when the plugin believes it.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_08_not_found_authority(): void {
		$this->check( Rule::NotFoundAuthority );
	}

	/**
	 * Rule 9: an intent the provider cancelled is answered expired, and the placement ends as a decline does.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_09_expiry(): void {
		$this->check( Rule::Expiry );
	}

	/**
	 * Rule 10: a webhook delivery is verified before it is read.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_10_webhooks_verified_first(): void {
		$this->check( Rule::WebhooksVerifiedFirst );
	}

	/**
	 * Rule 11: the shape of what a customer is asked to do.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_11_next_action_shape(): void {
		$this->check( Rule::NextActionShape );
	}

	/**
	 * Rule 12: every request goes through the gateway's client, outside any transaction.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_12_every_call_through_the_client(): void {
		$this->check( Rule::EveryCallThroughTheClient );
	}

	/**
	 * Rule 13: no provider answer, card field or card number in a log line.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_13_no_raw_response_logged(): void {
		$this->check( Rule::NoRawResponseLogged );
	}

	/**
	 * Rule 14: no credential in a URL, a header, a log line or a tape.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_14_credentials_stay(): void {
		$this->check( Rule::CredentialsStay );
	}

	/**
	 * Rule 16: each mode's payments carry that mode's credential.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_16_mode_credentials(): void {
		$this->check( Rule::ModeCredentials );
	}

	/**
	 * Rule 17: a void at the end of the customer's time answers an approval the provider already gave.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_17_void_answers_the_approval(): void {
		$this->check( Rule::VoidAnswersTheApproval );
	}

	/**
	 * Rule 19: an approved void reports the request's amount, what the cancellation released.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_19_void_reports_the_amount(): void {
		$this->check( Rule::VoidReportsTheAmount );
	}

	/**
	 * The extension's capabilities page is what its gateway declares.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_the_capability_page_is_current(): void {
		$this->checkCapabilityPage();
	}
}
