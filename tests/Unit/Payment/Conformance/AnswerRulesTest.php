<?php
/**
 * Tests for the conformance rules on a gateway's answer
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Unit\Payment\Conformance;

use PHPUnit\Framework\TestCase;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Conformance\Payment\AnswerRules;
use SEOCart\Tests\Conformance\Payment\Plants\VoidReportsZero;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Rule 19 keeps a void that reports the amount asked, and fails the planted gateway's void of zero.
 *
 * @since 0.2.0
 */
final class AnswerRulesTest extends TestCase {

	/**
	 * Tests that an approved void of the request's amount keeps rule 19.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_19_keeps_a_void_that_reports_the_amount_asked(): void {
		$request = self::request();

		$this->assertNull( AnswerRules::voidAmountFailure( $request, self::answer( $request, Operation::Void, Outcome::Approved, $request->amount ) ) );
	}

	/**
	 * Tests that rule 19 fails what the planted gateway answers: an approved void of zero.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_19_fails_the_plant_that_reports_zero(): void {
		$request = self::request();
		$failure = (string) AnswerRules::voidAmountFailure( $request, VoidReportsZero::zeroed( self::answer( $request, Operation::Void, Outcome::Approved, $request->amount ) ) );

		$this->assertStringStartsWith( Rule::VoidReportsTheAmount->message(), $failure );
		$this->assertStringContainsString( 'asked for 3000 USD (minor units) reported 0 USD (minor units)', $failure );
		$this->assertStringContainsString( '`amount_mismatch`', $failure );
	}

	/**
	 * Tests that rule 19 fails an approved void reported in another currency.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_19_fails_a_void_in_another_currency(): void {
		$request = self::request();

		$this->assertStringStartsWith( Rule::VoidReportsTheAmount->message(), (string) AnswerRules::voidAmountFailure( $request, self::answer( $request, Operation::Void, Outcome::Approved, Money::of( 3000, Currency::of( 'EUR' ) ) ) ) );
	}

	/**
	 * Tests that rule 19 does not hold a declined void, or the approval a provider answers in its place, to the request's amount.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_rule_19_leaves_a_declined_void_and_an_approval_answered_instead(): void {
		$request = self::request();
		$zero    = Money::of( 0, Currency::of( 'USD' ) );

		$this->assertNull( AnswerRules::voidAmountFailure( $request, self::answer( $request, Operation::Void, Outcome::Declined, $zero ) ) );
		$this->assertNull( AnswerRules::voidAmountFailure( $request, self::answer( $request, Operation::Authorize, Outcome::Approved, Money::of( 2999, Currency::of( 'USD' ) ) ) ) );
	}

	/**
	 * Tests that an outcome naming the object the provider made for it, found in the provider's answer, keeps the object rule.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_an_outcome_naming_its_own_object_keeps_the_object_rule(): void {
		$this->assertNull( AnswerRules::objectFailure( Rule::LedgerShape, 'the capture', 'rcp_7', 'ri_1', array( '{"id":"ri_1","capture":{"id":"rcp_7"}}' ), false ) );
		$this->assertNull( AnswerRules::objectFailure( Rule::LedgerShape, 'the capture', 'stub-cap-1', 'stub-pi-1', array(), false ), 'A gateway that calls no service has no answer to find its object in.' );
		$this->assertNull( AnswerRules::objectFailure( Rule::VoidReportsTheAmount, 'the void', 'ri_1', 'ri_1', array( '{"id":"ri_1","status":"canceled"}' ), true ), 'A void names the intent where the provider makes no cancellation object.' );
	}

	/**
	 * Tests that the object rule fails an outcome that names the provider's intent, no object, or an object no answer holds.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_the_object_rule_fails_the_intent_a_missing_object_and_an_invented_one(): void {
		$failures = array(
			'the intent'   => AnswerRules::objectFailure( Rule::LedgerShape, 'the capture', 'ri_1', 'ri_1', array( '{"id":"ri_1"}' ), false ),
			'no object'    => AnswerRules::objectFailure( Rule::LedgerShape, 'the first refund', null, 'ri_1', array( '{"id":"re_1"}' ), false ),
			'an invention' => AnswerRules::objectFailure( Rule::LedgerShape, 'the authorization', 'ch_made_up', 'ri_1', array( '{"id":"ri_1","charge":"rch_2"}' ), false ),
		);

		foreach ( $failures as $case => $failure ) {
			$this->assertStringStartsWith( Rule::LedgerShape->message(), (string) $failure, sprintf( 'The object rule failed %s.', $case ) );
		}

		$this->assertStringContainsString( 'the capture named the provider\'s intent (ri_1) as its object', (string) $failures['the intent'] );
		$this->assertStringContainsString( 'the first refund named no provider object', (string) $failures['no object'] );
		$this->assertStringContainsString( 'the authorization named ch_made_up, which none of the provider\'s answers to it holds', (string) $failures['an invention'] );
	}

	/**
	 * Tests that, for a provider that keeps one object for a payment, an outcome names the object one of its answers names in the declared field, at any depth and after a byte-order mark, and that the payment named elsewhere in an answer about an object of its own fails.
	 *
	 * @since 0.2.0
	 *
	 * @group conformance
	 */
	public function test_one_object_per_payment_reads_the_object_from_the_declared_field(): void {
		$field   = 'transactionResponse.transId';
		$capture = '{"transactionResponse":{"transId":"60012","refTransId":"60011"}}';

		foreach ( array(
			'the field, nested'                           => array( '60011', array( '{"transactionResponse":{"transId":"60011"}}' ), true ),
			'the field, after a byte-order mark'          => array( '60011', array( "\xEF\xBB\xBF" . '{"transactionResponse":{"transId":"60011"}}' ), true ),
			'the field, a number'                         => array( '60011', array( '{"transactionResponse":{"transId":60011}}' ), true ),
			'the field of the second answer'              => array( '60011', array( '{"messages":{}}', '{"transactionResponse":{"transId":"60011"}}' ), true ),
			'no answer'                                   => array( '60011', array(), true ),
			'the payment, in an answer about its capture' => array( '60011', array( $capture ), false ),
			'an answer of no field'                       => array( '60011', array( '{"transactionResponse":{"refTransId":"60011"}}' ), false ),
			'an answer that is no JSON'                   => array( '60011', array( 'transId=60011' ), false ),
			'no object'                                   => array( null, array( $capture ), false ),
		) as $case => list( $object, $answers, $kept ) ) {
			$failure = AnswerRules::ownObjectFailure( Rule::LedgerShape, 'the capture', $object, $answers, $field );

			$this->assertSame( $kept, null === $failure, $case . ': ' . (string) $failure );
		}

		$this->assertStringContainsString( 'the capture named 60011, which is not the object any of the provider\'s answers to it is about, as its field transactionResponse.transId names it', (string) AnswerRules::ownObjectFailure( Rule::LedgerShape, 'the capture', '60011', array( $capture ), $field ) );
	}

	/**
	 * Returns a void of 3000 US cents.
	 *
	 * @since 0.2.0
	 *
	 * @return VoidRequest The request.
	 */
	private static function request(): VoidRequest {
		return new VoidRequest( '019b76da-a800-7000-8000-000000000019', 'ri_1', Money::of( 3000, Currency::of( 'USD' ) ), Mode::Test, 'action_window_ended' );
	}

	/**
	 * Returns an answer to the request.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidRequest $request   The request.
	 * @param Operation   $operation The operation answered.
	 * @param Outcome     $outcome   The outcome.
	 * @param Money       $amount    The amount reported.
	 * @return GatewayResult The answer.
	 */
	private static function answer( VoidRequest $request, Operation $operation, Outcome $outcome, Money $amount ): GatewayResult {
		return new GatewayResult( ReferenceGateway::ID, $operation, $outcome, $request->intentUuid, $amount, 'ri_1', 'ri_1' );
	}
}
