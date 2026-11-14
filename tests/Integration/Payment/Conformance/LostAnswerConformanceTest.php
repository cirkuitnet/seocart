<?php
/**
 * Tests that the answer rule 2 loses is that of the next request that can move money, past a read or a token request that comes first
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
use SEOCart\Tests\Conformance\Payment\Reference\Variants\ExchangesCredentialsFirst;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\ReadsBeforeRefunding;
use SEOCart\Tests\Conformance\Payment\Rule;

/**
 * Runs rule 2 against a gateway that reads the provider's intent before it refunds, and against one that exchanges its credentials for a token before every request, its test declaring the token request: in each, the refund's answer is lost, not the answer before it, so the refund asked again is found made.
 *
 * Planted violation, shown red and removed: in RecordingTransport::send(), lose the answer to the
 * next request whatever it is: the read's or the token's answer is lost, no refund is made, the
 * refund asked again is answered not found, and rule 2 fails both gateways with the refund refused.
 *
 * @group conformance
 * @since 0.2.0
 */
final class LostAnswerConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup;

	/**
	 * The test of the gateway that exchanges its credentials first.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const TOKEN_FIRST = 'test_rule_2_loses_the_refunds_answer_not_the_tokens';

	/**
	 * Tests that rule 2's refund, its first answer lost after a read, is found made when asked again.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_loses_the_refunds_answer_not_the_reads(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 2's refund, its first answer lost after a declared token request, is found made when asked again.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_2_loses_the_refunds_answer_not_the_tokens(): void {
		$this->check( Rule::MatrixMatchesBehaviour );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Returns the token request, in the test of the gateway that sends it.
	 *
	 * @since 0.2.0
	 *
	 * @return list<array{method: string, path: string}> The requests.
	 */
	protected function credentialRequests(): array {
		if ( self::TOKEN_FIRST !== $this->getName( false ) ) {
			return array();
		}

		return array(
			array(
				'method' => ExchangesCredentialsFirst::TOKEN_METHOD,
				'path'   => ExchangesCredentialsFirst::TOKEN_PATH,
			),
		);
	}

	/**
	 * Builds the gateway that reads before it refunds, or the one that exchanges its credentials first.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return self::TOKEN_FIRST === $this->getName( false ) ? new ExchangesCredentialsFirst( $context ) : new ReadsBeforeRefunding( $context );
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
