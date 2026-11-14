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
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesNoIntent;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\NamesTheProviderIntentAlone;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rule 10, on the reference gateway's recorded deliveries, against a gateway whose result names the provider's id of the intent alone, which passes, and one whose result names neither it nor the store's uuid, which fails.
 *
 * Planted violation, shown red and removed: in ConformanceHarness::readsDeliveryRightly(), take a
 * result for one about an intent only when it names the store's uuid, as before: the gateway that
 * names the provider's id alone fails rule 10, and its test fails here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class ResultAboutAnIntentConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup;
	use RuleFailures;

	/**
	 * The test whose gateway's result names no intent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NO_INTENT = 'test_a_result_that_names_no_intent_fails_rule_10';

	/**
	 * Tests that rule 10 passes a result that names the provider's id of the intent and an empty uuid of the store's, as the contract allows.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_that_names_the_providers_intent_alone_passes_rule_10(): void {
		$this->check( Rule::WebhooksVerifiedFirst );
		$this->addToAssertionCount( 1 );
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
	 * Builds the gateway whose result names the provider's intent alone; the one whose result names no intent, in its test.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return self::NO_INTENT === $this->getName( false ) ? new NamesNoIntent( $context ) : new NamesTheProviderIntentAlone( $context );
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
