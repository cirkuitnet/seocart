<?php
/**
 * Tests that rule 10 reads a delivery's result as about an intent when it names the store's uuid or the provider's id of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesAnotherPaymentsId;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesNoIntent;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesTheProviderIntentAlone;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rule 10, on the reference gateway's recorded deliveries, against a gateway whose result names the provider's id of the intent alone, which passes, with the payment the test names for the delivery opened in the store and the delivery settled against it; one whose result names another object's id, one whose test names no payment for the delivery, and one whose result names neither the provider's id nor the store's uuid fail.
 *
 * Planted violations, shown red and removed: in ConformanceHarness::readsDeliveryRightly(), take a
 * result for one about an intent only when it names the store's uuid, as before: the gateway that
 * names the provider's id alone fails rule 10, and its test fails here; skip the payment the test
 * names for a delivery (paymentDeliveredFor()): the result about another object's id passes, and
 * its test fails here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class ResultAboutAnIntentConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup;
	use RuleFailures;

	/**
	 * The provider's id of the payment the recorded `authorized` delivery is about.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const AUTHORIZED_PAYMENT = 'ri_webhook';

	/**
	 * The test whose gateway's result names no intent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NO_INTENT = 'test_a_result_that_names_no_intent_fails_rule_10';

	/**
	 * The test whose gateway's result names another object's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ANOTHER_ID = 'test_a_result_that_names_another_objects_id_fails_rule_10';

	/**
	 * The test whose test names no payment for the delivery.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNNAMED = 'test_a_result_by_the_providers_id_of_a_delivery_of_no_named_payment_fails_rule_10';

	/**
	 * Tests that rule 10 passes a result that names the provider's id of the intent and an empty uuid of the store's, as the contract allows: the payment the test names for the delivery, which the store's route settles the delivery against.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_that_names_the_providers_intent_alone_passes_rule_10(): void {
		$this->check( Rule::WebhooksVerifiedFirst );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 10 fails a result that names, by the provider's id alone, another object than the payment its delivery is about.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_that_names_another_objects_id_fails_rule_10(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'the delivery authorized reads as a payment result about the provider\'s payment rch_webhook, and the delivery is about the payment ri_webhook (deliveryPayments())' );
	}

	/**
	 * Tests that rule 10 fails a result that names its payment by the provider's id alone, of a delivery the test names no payment for.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_by_the_providers_id_of_a_delivery_of_no_named_payment_fails_rule_10(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'the delivery authorized reads as a payment result that names its payment by the provider\'s id alone, ri_webhook, and the test names no payment for it in deliveryPayments()' );
	}

	/**
	 * Tests that rule 10 fails a result that names neither the store's uuid of the intent nor the provider's id of it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_that_names_no_intent_fails_rule_10(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'the delivery authorized reads as a payment result that is not one of this gateway\'s, about an intent.' );
	}

	/**
	 * Returns the payment the recorded `authorized` delivery is about, but in the test of a delivery of no named payment.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The provider's ids, by the delivery's name.
	 */
	protected function deliveryPayments(): array {
		return self::UNNAMED === $this->getName( false ) ? array() : array( 'authorized' => self::AUTHORIZED_PAYMENT );
	}

	/**
	 * Builds the gateway whose result names the provider's intent alone; the one whose result names another object's id, or no intent, in their tests.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return match ( $this->getName( false ) ) {
			self::NO_INTENT  => new NamesNoIntent( $context ),
			self::ANOTHER_ID => new NamesAnotherPaymentsId( $context ),
			default          => new NamesTheProviderIntentAlone( $context ),
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
