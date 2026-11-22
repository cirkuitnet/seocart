<?php
/**
 * Tests a gateway that calls no service and has live mode alone against the payment gateway conformance suite
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment\Conformance;

use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Support\Currency;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\LiveOnlyGateway;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * Runs every rule of the suite against a gateway that declares no host and live mode alone, and refuses a call in any other mode: the suite speaks to it in the suite's mode, live, so it passes.
 *
 * The gateway answers as the stand-in does, webhooks included, so rule 10 reads its deliveries in
 * live mode and replays them through the store's route at the live address. One delivery more than
 * the stand-in's is read: an authorization that names its payment by the provider's id alone,
 * which the store settles against the payment the test names for it (deliveryPayments()).
 *
 * Planted violations, shown red and removed: in ConformanceHarness, put back to the test mode, one
 * at a time, each mode the suite passes the gateway, or the store on its behalf, that a gateway of
 * no host and live mode alone can see: the calls of rules 3, 7, 8 and 19 and the delivery's
 * envelope (the gateway refuses each, naming the mode), the gateway the registry resolves, the
 * intents openIntent() opens, the webhook route's address, the receipt a delivery leaves and the
 * receipt that names a delivery's payment: this test fails each time. Such a gateway sees no other
 * mode the suite passes: configure() stores a mode only for a gateway of two modes, and the keys
 * rules 6 and 12 derive are read only for a gateway that declares a host, which declares test mode.
 *
 * @since 0.2.0
 */
final class LiveOnlyGatewayConformanceTest extends StandInConformanceCase {

	/**
	 * The name of the delivery that names its payment by the provider's id alone.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const BY_REFERENCE = 'authorized-by-reference';

	/**
	 * The provider's id of the payment that delivery is about, as the stand-in names an approved intent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const REFERENCED_PAYMENT = 'stub-pi-approve-019b76da-a800-7000-8000-00000000000b';

	/**
	 * Returns the gateway's id.
	 *
	 * @since 0.2.0
	 *
	 * @return string The id.
	 */
	protected function gatewayId(): string {
		return LiveOnlyGateway::ID;
	}

	/**
	 * Registers the gateway that has live mode alone.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 */
	protected function register( GatewayRegistry $registry ): void {
		$registry->register( new LiveOnlyGateway() );
	}

	/**
	 * Returns the stand-in's events, and an authorization that names its payment by the provider's id alone, with no uuid of the store's.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, StubWebhooks> The events by the delivery's name.
	 */
	protected function webhooks(): array {
		$approved = new GatewayResult( StubGateway::ID, Operation::Authorize, Outcome::Approved, '', Money::of( 3000, Currency::of( 'USD' ) ), 'stub-ch-019b76da-a800-7000-8000-00000000000b', self::REFERENCED_PAYMENT );

		return parent::webhooks() + array( self::BY_REFERENCE => StubWebhooks::of( $approved ) );
	}

	/**
	 * Returns the provider's id of the payment the delivery that names no uuid of the store's is about.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The provider's ids, by the delivery's name.
	 */
	protected function deliveryPayments(): array {
		return array( self::BY_REFERENCE => self::REFERENCED_PAYMENT );
	}
}
