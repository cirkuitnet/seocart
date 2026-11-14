<?php
/**
 * Tests rule 10 under a test's declaration that the provider's deliveries carry no signed time
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
use SEOCart\Tests\Conformance\Payment\Reference\Variants\RefusesADeliveryDaysLate;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\UndatedDeliveries;
use SEOCart\Tests\Conformance\Payment\Reference\Variants\WindowsOnTheBodysDate;
use SEOCart\Tests\Conformance\Payment\Rule;
use SEOCart\Tests\Conformance\Payment\RuleFailures;
use SEOCart\Tests\Conformance\Payment\TapeScrubber;

/**
 * Runs rule 10 against a gateway whose provider signs a delivery's body alone and that keeps no window: it passes when the test declares deliveriesCarryNoSignedTime(), and fails as before when it does not; under the declaration, a gateway that windows on the time the body states fails, and a delivery whose headers hold a time refuses the declaration.
 *
 * The deliveries are made here, an authorization and an account's update signed with the
 * published secret, as a gateway without a tape has its test make them.
 *
 * Planted violation, shown red and removed: in ConformanceHarness::readsDeliveryRightly(), skip
 * the refusal of a delivery whose headers hold a time: the reference gateway's dated deliveries
 * are read under the declaration, and the test of the refusal fails here.
 *
 * @group conformance
 * @since 0.2.0
 */
final class UndatedDeliveriesConformanceTest extends ConformanceHarness {

	use ReferenceGatewaySetup {
		freshDeliveries as referenceDeliveries;
	}
	use RuleFailures;

	/**
	 * The test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const UNDECLARED = 'test_rule_10_fails_undated_deliveries_without_the_declaration';

	/**
	 * The test whose gateway windows on the time the body states.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WINDOWS = 'test_a_gateway_that_windows_on_the_bodys_time_fails_rule_10_under_the_declaration';

	/**
	 * The test whose deliveries carry a signed time in a header.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DATED = 'test_a_dated_header_refuses_the_declaration';

	/**
	 * The test whose gateway refuses a delivery read days after it was sent.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const DAYS_LATE = 'test_a_gateway_that_refuses_a_delivery_days_late_fails_rule_10_under_the_declaration';

	/**
	 * Tests that rule 10 passes undated deliveries and a gateway that keeps no window, when the test declares it: read an hour later or earlier, each delivery reads as on arrival.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_10_passes_undated_deliveries_under_the_declaration(): void {
		$this->check( Rule::WebhooksVerifiedFirst );
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Tests that rule 10 fails the same gateway without the declaration, as it did before the declaration existed: a delivery an hour old is not rejected.
	 *
	 * @since 0.2.0
	 */
	public function test_rule_10_fails_undated_deliveries_without_the_declaration(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'the delivery authorized with a signed time an hour old was answered result, not rejected.' );
	}

	/**
	 * Tests that rule 10 fails, under the declaration, a gateway that still refuses a delivery whose body states a time an hour from now.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_windows_on_the_bodys_time_fails_rule_10_under_the_declaration(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'and the delivery authorized read with the clock an hour later was answered rejected (stale), not as on arrival, result' );
	}

	/**
	 * Tests that rule 10 refuses the declaration for a delivery whose signature header holds the time it was signed at.
	 *
	 * @since 0.2.0
	 */
	public function test_a_dated_header_refuses_the_declaration(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'the test declares that deliveries carry no signed time (deliveriesCarryNoSignedTime()), and the header reference-signature of the delivery authorized holds a `t=<digits>` part' );
	}

	/**
	 * Tests that rule 10 fails, under the declaration, a gateway that reads a delivery an hour late or early but refuses one read days after it was sent, as a provider that signs no time sends one again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_gateway_that_refuses_a_delivery_days_late_fails_rule_10_under_the_declaration(): void {
		$this->assertRuleFails( Rule::WebhooksVerifiedFirst, 'and the delivery authorized read with the clock twenty-nine days later was answered rejected (stale), not as on arrival, result' );
	}

	/**
	 * Says that the provider's deliveries carry no signed time, but in the test that runs without the declaration.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True but in that test.
	 */
	protected function deliveriesCarryNoSignedTime(): bool {
		return self::UNDECLARED !== $this->getName( false );
	}

	/**
	 * Returns no tapes: the deliveries are made here.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null None.
	 */
	protected function tapes(): ?string {
		return null;
	}

	/**
	 * Returns the reference provider's two deliveries, signed with the published secret as the test's gateway signs them.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array<string, mixed>> The deliveries by name.
	 */
	protected function freshDeliveries(): array {
		$deliveries = array();

		foreach ( $this->referenceDeliveries() as $name => $delivery ) {
			$headers             = $this->signDelivery( array( 'content-type' => 'application/json' ), (string) $delivery['body'], TapeScrubber::PUBLISHED_WEBHOOK_SECRET, new \DateTimeImmutable( (string) $delivery['signed_at'] ) );
			$deliveries[ $name ] = array( 'headers' => $headers ) + $delivery;
		}

		return $deliveries;
	}

	/**
	 * Signs a delivery as the test's gateway verifies it: the reference gateway's signed time, or the body alone.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The headers.
	 * @param string                $body    The raw body.
	 * @param string                $secret  The secret.
	 * @param \DateTimeImmutable    $at      When.
	 * @return array<string, string> The headers, signed.
	 */
	protected function signDelivery( array $headers, string $body, #[\SensitiveParameter] string $secret, \DateTimeImmutable $at ): array {
		return self::DATED === $this->getName( false ) ? ReferenceGateway::signed( $headers, $body, $secret, $at ) : UndatedDeliveries::signedUndated( $headers, $body, $secret );
	}

	/**
	 * Builds the gateway of undated deliveries; the one that windows on the body's time, and the reference gateway, in their tests.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayRegistry $registry The registry.
	 * @return ReferenceGateway The gateway.
	 */
	protected function gatewayOf( GatewayRegistry $registry ): ReferenceGateway {
		$context = $registry->context( ReferenceGateway::ID );

		return match ( $this->getName( false ) ) {
			self::WINDOWS   => new WindowsOnTheBodysDate( $context ),
			self::DAYS_LATE => new RefusesADeliveryDaysLate( $context ),
			self::DATED   => new ReferenceGateway( $context ),
			default       => new UndatedDeliveries( $context ),
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
