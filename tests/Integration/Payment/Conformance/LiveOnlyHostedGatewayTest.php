<?php
/**
 * Tests that the conformance suite refuses a gateway that calls a provider and declares no test mode
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use PHPUnit\Framework\AssertionFailedError;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Tests\Conformance\Payment\ConformanceHarness;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;

/**
 * A gateway that declares a host calls a provider, so the suite runs it in test mode only: one that declares a host and live mode alone is refused as the suite sets it up, before anything is placed.
 *
 * Planted violation, shown red and removed: in ConformanceHarness::suiteMode(), drop the refusal of
 * a gateway that declares a host: the gateway is configured in live mode, and this test fails.
 *
 * @group conformance
 *
 * @since 0.2.0
 */
final class LiveOnlyHostedGatewayTest extends ConformanceHarness {

	/**
	 * The gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const ID = 'hosted_live';

	/**
	 * What the suite's set-up refused, if it did.
	 *
	 * @since 0.2.0
	 *
	 * @var AssertionFailedError|null
	 */
	private ?AssertionFailedError $refused = null;

	/**
	 * Sets the suite up, keeping its refusal for the test to read.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		try {
			parent::set_up();
		} catch ( AssertionFailedError $refused ) {
			$this->refused = $refused;
		}
	}

	/**
	 * Tests the refusal: the rule it names, and the gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_calls_a_provider_must_declare_test_mode(): void {
		$this->assertNotNull( $this->refused, 'The suite set up a gateway that calls a provider in live mode.' );
		$this->assertSame( 'A gateway that calls a provider must declare test mode for the conformance suite, and hosted_live declares a host and no test mode.', $this->refused->getMessage() );
	}

	/**
	 * Returns the gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return self::ID;
	}

	/**
	 * Returns no tape: nothing is placed.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null Null.
	 */
	protected function tapes(): ?string {
		return null;
	}

	/**
	 * Registers a gateway that declares a provider's host and live mode alone, and no setting.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 */
	protected function register( GatewayRegistry $registry ): void {
		$registry->register( new DeclaredGateway( DeclaredGateway::descriptor( self::ID, array( Mode::Live ), array(), null, null, PaymentGateway::CONTRACT_VERSION, array( DeclaredGateway::host( 'hostedlive' ) ) ), $registry->context( self::ID ) ) );
	}
}
