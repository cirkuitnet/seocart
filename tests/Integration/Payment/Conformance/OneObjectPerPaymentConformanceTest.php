<?php
/**
 * Tests rule 1 under a test's declaration that the provider keeps one object for a payment
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\Plants\ObjectIsTheIntent;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\CapturesAnObjectOfItsOwn;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\OneObjectPerPayment;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rule 1 against a gateway that names the provider's intent as the object of the authorization and the capture: it passes when the test declares paymentIsOneObject(), and fails as before when it does not; under the declaration, a gateway whose two refunds name one object fails, and a matrix that declares multi_capture fails the set-up.
 *
 * Under the declaration, each outcome's object is the one an answer to it names in the field the
 * test declares (objectIdField()), the fake provider's `id`: a capture its provider answers as an
 * object of its own, named as the payment, fails.
 *
 * Planted violations, shown red and removed: in ConformanceHarness::declarationRefusal(), drop the
 * refusal beside multi_capture: the gateway whose matrix declares it is set up, and the test of
 * its refusal fails here; in ConformanceHarness::assertOwnObject(), take the object anywhere in
 * the answer under the declaration: the capture answered as an object of its own passes rule 1,
 * and its test fails here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class OneObjectPerPaymentConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup {
		set_up as setUpReference;
	}
	use RuleFailures;

	/**
	 * The test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNDECLARED = 'test_rule_1_fails_one_object_without_the_declaration';

	/**
	 * The test whose gateway names each refund by the intent too.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUNDS_ARE_ONE = 'test_two_refunds_of_one_object_fail_rule_1_under_the_declaration';

	/**
	 * The test whose gateway's matrix declares multi_capture.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MULTI_CAPTURE = 'test_the_declaration_beside_multi_capture_fails_the_set_up';

	/**
	 * The test whose declaration names no field of the answers.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NO_FIELD = 'test_the_declaration_without_an_answers_field_fails_the_set_up';

	/**
	 * The test whose gateway names the payment as the object of a capture its provider answers as an object of its own.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CAPTURE_OF_ITS_OWN = 'test_a_capture_answered_as_an_object_of_its_own_fails_rule_1_under_the_declaration';

	/**
	 * What the suite's set-up refused, in the test that expects it.
	 *
	 * @since 0.2.0
	 *
	 * @var AssertionFailedError|null
	 */
	private ?AssertionFailedError $refused = null;

	/**
	 * Sets the suite up, keeping its refusal for the test that expects one.
	 *
	 * @since 0.2.0
	 *
	 * @throws AssertionFailedError When the set-up is refused in any other test.
	 */
	public function set_up(): void {
		try {
			$this->setUpReference();

			// The gateway of one object per payment speaks to a provider that answers one id for it; the plants keep the provider's objects of their own.
			if ( ! in_array( $this->getName( false ), array( self::REFUNDS_ARE_ONE, self::CAPTURE_OF_ITS_OWN ), true ) ) {
				$this->provider->keepOneObject();
			}
		} catch ( AssertionFailedError $refused ) {
			if ( ! in_array( $this->getName( false ), array( self::MULTI_CAPTURE, self::NO_FIELD ), true ) ) {
				throw $refused;
			}

			$this->refused = $refused;
		}
	}

	/**
	 * Tests that rule 1 passes a gateway that names the provider's intent as the authorization's and the capture's object, when the test declares it.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_1_passes_one_object_under_the_declaration(): void {
		$this->check( Rule::LedgerShape );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 1 fails the same gateway without the declaration, as it did before the declaration existed.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_1_fails_one_object_without_the_declaration(): void {
		$this->assertRuleFails( Rule::LedgerShape, 'the authorization named the provider\'s intent' );
	}

	/**
	 * Tests that rule 1 fails, under the declaration, a gateway whose refunds name the provider's intent while each refund's answer is about a refund of its own.
	 *
	 * The first refund already names an object its answer is not about. Were it let through, the
	 * payment ledger, which keys a refund by its object, would keep the second refund of one object
	 * unreconciled, and the suite's own check would find two refunds named by one object.
	 *
	 * @since 0.2.0
	 */
	public function test_two_refunds_of_one_object_fail_rule_1_under_the_declaration(): void {
		$this->assertRuleFails( Rule::LedgerShape, 'the first refund named ' );
	}

	/**
	 * Tests that the declaration beside a matrix that declares multi_capture fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_the_declaration_beside_multi_capture_fails_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up the declaration beside multi_capture.' );
		$this->assertSame( 'The test declares that the provider keeps one object for a payment (paymentIsOneObject()), and a cell of the gateway\'s matrix declares multi_capture: two captures of one object would be one row of the payment ledger, so the declaration is refused.', $this->refused->getMessage() );
	}

	/**
	 * Tests that rule 1 fails, under the declaration, a gateway that names the payment as the object of a capture its provider answers as an object of its own, though that answer names the payment in another field.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_answered_as_an_object_of_its_own_fails_rule_1_under_the_declaration(): void {
		$this->assertRuleFails( Rule::LedgerShape, 'which is not the object any of the provider\'s answers to it is about, as its field id names it' );
	}

	/**
	 * Tests that the declaration without a field of the answers that names the object each is about fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_the_declaration_without_an_answers_field_fails_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up the declaration without a field of the answers.' );
		$this->assertStringStartsWith( 'The test declares that the provider keeps one object for a payment (paymentIsOneObject()), and names in objectIdField() no field of its answers', $this->refused->getMessage() );
	}

	/**
	 * Returns the field of the fake provider's answers that names the object each is about, its `id`; none in the test of a declaration without it.
	 *
	 * @since 0.2.0
	 *
	 * @return string The field.
	 */
	protected function objectIdField(): string {
		return self::NO_FIELD === $this->getName( false ) ? '' : 'id';
	}

	/**
	 * Says that the provider keeps one object for a payment, but in the test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True but in that test.
	 */
	protected function paymentIsOneObject(): bool {
		return self::UNDECLARED !== $this->getName( false );
	}

	/**
	 * Builds the gateway of one object per payment; the plant that names each refund by the intent too, and one whose matrix declares multi_capture, in their tests.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return match ( $this->getName( false ) ) {
			self::REFUNDS_ARE_ONE    => new ObjectIsTheIntent( $context ),
			self::CAPTURE_OF_ITS_OWN => new CapturesAnObjectOfItsOwn( $context ),
			self::MULTI_CAPTURE   => new class( $context ) extends OneObjectPerPayment {

				/**
				 * Returns the reference gateway's operations and multi_capture.
				 *
				 * @since 0.2.0
				 *
				 * @return list<string> The operations.
				 */
				protected function operations(): array {
					return array_merge( parent::operations(), array( Operations::MULTI_CAPTURE ) );
				}
			},
			default               => new OneObjectPerPayment( $context ),
		};
	}

	/**
	 * Lets the fake provider answer every request: no tape is compared or written.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx The transaction manager.
	 * @return RecordingTransport The transport.
	 */
	protected function transportOver( TransactionManager $tx ): RecordingTransport {
		return RecordingTransport::passingThrough( $tx, $this->http );
	}
}
