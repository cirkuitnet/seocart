<?php
/**
 * Tests rule 8's decline half under a test's declaration that a decline leaves no payment
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
use SEOCart\Tests\Conformance\Payment\Reference\Variants\DeclineLeavesNoPayment;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rule 8 against a gateway whose decline names no provider payment: it passes when the test declares declineLeavesNoPayment(), and fails as before when it does not; under the declaration, a gateway that asks the provider about its decline fails.
 *
 * Planted violation, shown red and removed: in ConformanceHarness::checkNotFoundAuthority(), accept
 * any answer under the declaration (skip assertNothingToAskAbout()): the reference gateway, which
 * searches for its decline, passes, and its test here fails.
 *
 * @group conformance
 * @since 0.2.0
 */
final class DeclineLeavesNoPaymentConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup;
	use RuleFailures;

	/**
	 * The test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNDECLARED = 'test_rule_8_fails_without_the_declaration_as_before';

	/**
	 * The test whose gateway asks the provider about its decline.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ASKS = 'test_a_gateway_that_asks_about_its_decline_fails_rule_8_under_the_declaration';

	/**
	 * Tests that rule 8 passes a decline that leaves no payment, query() answering nothing without a request, when the test declares it.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_8_passes_a_decline_that_leaves_no_payment_under_its_declaration(): void {
		$this->check( Rule::NotFoundAuthority );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 8 fails the same gateway without the declaration, as it did before the declaration existed: its query() answers nothing about a decline.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_8_fails_without_the_declaration_as_before(): void {
		$this->assertRuleFails( Rule::NotFoundAuthority, 'query() of an authorization the provider declined answered nothing, not that decline.' );
	}

	/**
	 * Tests that rule 8 fails, under the declaration, a gateway whose query() asks the provider about the decline and answers it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_asks_about_its_decline_fails_rule_8_under_the_declaration(): void {
		$this->assertRuleFails( Rule::NotFoundAuthority, 'the test declares that a declined authorization leaves no payment to ask about (declineLeavesNoPayment()), and query() of one answered a declined authorize (card_declined) and sent a request to the provider' );
	}

	/**
	 * Says that a decline leaves no payment, but in the test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True but in that test.
	 */
	protected function declineLeavesNoPayment(): bool {
		return self::UNDECLARED !== $this->getName( false );
	}

	/**
	 * Builds the gateway whose decline names no payment; the reference gateway, which searches for its decline, in the test of a gateway that asks.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return self::ASKS === $this->getName( false ) ? new ReferenceGateway( $context ) : new DeclineLeavesNoPayment( $context );
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
