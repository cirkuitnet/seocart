<?php
/**
 * Tests the suite's refunds under a test's declaration that the provider refunds a part only once a payment has settled
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Contracts\Payment\CapabilityMatrix;
use SEOCart\Contracts\Payment\GatewayDescriptor;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\MatrixRow;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Conformance\Payment\RecordingTransport;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGateway;
use SEOCart\Tests\Conformance\Payment\Reference\ReferenceGatewaySetup;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\DeclinesThePartItSent;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\RefusesAPartBeforeSettlement;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\SendsThePartPastTheClient;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;

/**
 * Runs rules 1, 2, 5, 6 and 12 against a gateway that declines a part of a refund before settlement and refunds a payment whole: they pass when the test declares partialRefundsNeedSettlement(), and rules 1 and 2 fail as before when it does not; under the declaration, a gateway that refunds the part, and one that asks the provider for the part before declining it, fail rule 2, and a matrix that declares no partial_refund fails the set-up.
 *
 * Planted violation, shown red and removed: in ConformanceHarness::exerciseCell(), skip the
 * refund of one unit under the declaration: the gateway that refunds the part passes rule 2, and
 * its test fails here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class PartialRefundsNeedSettlementConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup {
		set_up as setUpReference;
	}
	use RuleFailures;

	/**
	 * The tests that run without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var list<string>
	 */
	private const UNDECLARED = array( 'test_rule_1_fails_without_the_declaration_as_before', 'test_rule_2_fails_without_the_declaration_as_before' );

	/**
	 * The test whose gateway refunds the part.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFUNDS_THE_PART = 'test_a_gateway_that_refunds_the_part_fails_rule_2_under_the_declaration';

	/**
	 * The test whose gateway asks the provider for the part before declining it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const SENDS_THE_PART = 'test_a_gateway_that_asks_the_provider_for_the_part_fails_rule_2_under_the_declaration';

	/**
	 * The test whose gateway's matrix declares no partial_refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NO_PARTIAL_REFUND = 'test_the_declaration_without_partial_refund_fails_the_set_up';

	/**
	 * The test whose gateway's matrix declares partial refunds in dollars alone.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const MIXED = 'test_rule_2_passes_a_matrix_of_partial_refunds_in_one_currency_under_the_declaration';

	/**
	 * The test whose gateway sends a declined part to the provider past its client.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PAST_THE_CLIENT = 'test_a_gateway_that_sends_the_part_past_the_client_fails_rule_2_under_the_declaration';

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
		} catch ( AssertionFailedError $refused ) {
			if ( self::NO_PARTIAL_REFUND !== $this->getName( false ) ) {
				throw $refused;
			}

			$this->refused = $refused;
		}
	}

	/**
	 * Tests that rule 1's ledger, under the declaration, is an authorization, a capture and one refund of the whole.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_1_passes_a_whole_refund_under_the_declaration(): void {
		$this->check( Rule::LedgerShape );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2, under the declaration, finds a part declined with nothing sent, and refunds the payment whole with its answer lost.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_passes_a_part_declined_before_settlement_under_the_declaration(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2, under the declaration, asks for a part only in the cells that declare partial refunds: a cell of another currency that declares none refuses the part before the gateway, as it must, and refunds the payment whole.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_passes_a_matrix_of_partial_refunds_in_one_currency_under_the_declaration(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2 fails, under the declaration, a gateway that declines a part and sends it to the provider with WordPress's HTTP API, past its client.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_sends_the_part_past_the_client_fails_rule_2_under_the_declaration(): void {
		$this->assertRuleFails( Rule::MatrixMatchesBehaviour, 'and a refund of one unit of the order captured in usd-any sent a request past the gateway\'s client' );
	}

	/**
	 * Tests that rule 5 reports the whole refund's amount.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_5_passes_a_whole_refund_under_the_declaration(): void {
		$this->check( Rule::AmountsEchoed );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 6 finds the whole refund's key, beside the read before it.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_6_passes_a_whole_refund_under_the_declaration(): void {
		$this->check( Rule::KeysAsRuled );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 12 finds the whole refund's own request.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_12_passes_a_whole_refund_under_the_declaration(): void {
		$this->check( Rule::EveryCallThroughTheClient );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 1 fails the same gateway without the declaration, as it did before the declaration existed: its first refund, of one unit, is declined.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_1_fails_without_the_declaration_as_before(): void {
		$this->assertRuleFails( Rule::LedgerShape, 'payment.refund_declined' );
	}

	/**
	 * Tests that rule 2 fails the same gateway without the declaration, as it did before the declaration existed.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_fails_without_the_declaration_as_before(): void {
		$this->assertRuleFails( Rule::MatrixMatchesBehaviour, 'and refunding the captured order one unit, then the rest, was refused' );
	}

	/**
	 * Tests that rule 2 fails, under the declaration, a gateway that refunds a part before settlement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_refunds_the_part_fails_rule_2_under_the_declaration(): void {
		$this->assertRuleFails( Rule::MatrixMatchesBehaviour, 'and a refund of one unit of the order captured in usd-any was made, not declined by the gateway.' );
	}

	/**
	 * Tests that rule 2 fails, under the declaration, a gateway that asks the provider for a part before it declines it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_asks_the_provider_for_the_part_fails_rule_2_under_the_declaration(): void {
		$this->assertRuleFails( Rule::MatrixMatchesBehaviour, 'and a refund of one unit of the order captured in usd-any sent a request that moves money' );
	}

	/**
	 * Tests that the declaration beside a matrix that declares no partial_refund fails the suite's set-up with one sentence.
	 *
	 * @since 0.2.0
	 */
	public function test_the_declaration_without_partial_refund_fails_the_set_up(): void {
		$this->assertNotNull( $this->refused, 'The suite set up the declaration beside a matrix of no partial_refund.' );
		$this->assertSame( 'The test declares that partial refunds wait for the provider\'s settlement (partialRefundsNeedSettlement()), and no cell of the gateway\'s matrix declares partial_refund: the declaration changes only how the suite refunds a part, so it is refused where no part is refunded.', $this->refused->getMessage() );
	}

	/**
	 * Says that partial refunds wait for the provider's settlement, but in the tests that run without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True but in those tests.
	 */
	protected function partialRefundsNeedSettlement(): bool {
		return ! in_array( $this->getName( false ), self::UNDECLARED, true );
	}

	/**
	 * Builds the gateway that declines a part before settlement; the reference gateway, the one that asks the provider for the part, and one of no partial_refund, in their tests.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return match ( $this->getName( false ) ) {
			self::REFUNDS_THE_PART  => new ReferenceGateway( $context ),
			self::SENDS_THE_PART    => new DeclinesThePartItSent( $context ),
			self::PAST_THE_CLIENT   => new SendsThePartPastTheClient( $context ),
			self::NO_PARTIAL_REFUND => new class( $context ) extends RefusesAPartBeforeSettlement {

				/**
				 * Returns the reference gateway's operations but partial_refund.
				 *
				 * @since 0.2.0
				 *
				 * @return list<string> The operations.
				 */
				protected function operations(): array {
					return array_values( array_diff( parent::operations(), array( Operations::PARTIAL_REFUND ) ) );
				}
			},
			self::MIXED             => new class( $context ) extends RefusesAPartBeforeSettlement {

				/**
				 * Returns the gateway's declaration with partial refunds in its dollar row alone.
				 *
				 * @since 0.2.0
				 *
				 * @return GatewayDescriptor The declaration.
				 */
				public function describe(): GatewayDescriptor {
					$described = parent::describe();
					$rows      = array_map(
						static fn( MatrixRow $row ): MatrixRow => 'USD' === $row->currency->code() ? $row : new MatrixRow( $row->currency, $row->accountCountry, array_values( array_diff( $row->operations, array( Operations::PARTIAL_REFUND ) ) ) ),
						$described->matrix->rows
					);

					return new GatewayDescriptor( $described->id, $described->label, $described->type, $described->contract, $described->modes, $described->settings, new CapabilityMatrix( $rows ), $described->idempotency, $described->hosts );
				}
			},
			default                 => new RefusesAPartBeforeSettlement( $context ),
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
