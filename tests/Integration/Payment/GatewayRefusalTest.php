<?php
/**
 * Tests that an intent whose gateway cannot serve it is refused before anything is asked of a gateway or written
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Every call about an intent is refused `payment.gateway_unavailable` before a gateway is asked, a refund is claimed or the ledger moves, when the intent's gateway is no longer registered, and when the gateway no longer declares the mode the intent was created in, whether or not it has settings.
 *
 * Planted violations, each shown red and removed:
 * - in Gateways, take a gateway that is not registered for the first one that is: the stand-in
 *   is then asked to capture and refund the gone gateway's payments;
 * - in Gateways::get(), drop the check of the intent's mode: the test-mode gateway is asked to
 *   authorize a live payment.
 *
 * @since 0.2.0
 */
final class GatewayRefusalTest extends RefundTestCase {

	/**
	 * Tests capture, a status query and a refund for intents whose gateway has gone: each refused `not_registered`, nothing asked, claimed or recorded.
	 *
	 * @since 0.2.0
	 */
	public function test_an_intent_whose_gateway_is_gone_is_refused_before_anything(): void {
		list( $paid, $captured ) = $this->placePaid( NewOrders::forTwoLines( 'USD', 'USD' ) );
		list( , $authorized )    = $this->placeWithIntent( NewOrders::forTwoLines( 'USD', 'USD' ) );

		$this->deliver( $this->authorizeWith( $authorized, 'stub:approve' ) );

		// The gateway plugin both were paid through has been removed.
		$this->db->execute( "UPDATE %i SET gateway_id = 'gone'", $this->table( PaymentTables::INTENTS ) );

		$ledger = $this->ledgerRows();
		$lines  = $this->lineUuids( $paid->id );

		$this->gateway->calls = array();

		$this->assertRefused( 'not_registered', 'gone', fn() => $this->payments->capture( $authorized->uuid, $this->userWithRole() ) );
		$this->assertRefused( 'not_registered', 'gone', fn() => $this->payments->queryGateway( new IntentRef( $authorized->uuid, $authorized->orderId, 'gone', Mode::Test, IntentStatus::Authorized, null, $authorized->amount, 3600 ) ) );
		$this->assertRefused( 'not_registered', 'gone', fn() => $this->refund( $paid->uuid, array( $lines[0] => 1 ) ) );

		$this->assertSame( array(), $this->gateway->calls, 'No gateway was asked.' );
		$this->assertSame( 0, $this->claims(), 'No refund was claimed.' );
		$this->assertSame( $ledger, $this->ledgerRows(), 'The ledger did not move.' );
		$this->assertSame( array( 'authorized', 'captured' ), array( $this->intentRow( $authorized->uuid )['status'], $this->intentRow( $captured->uuid )['status'] ), 'Neither intent moved.' );
	}

	/**
	 * Tests that a gateway with one mode and no settings is refused for an intent of a mode it does not declare, on every call, before it is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_an_intent_in_a_mode_its_gateway_does_not_declare_is_refused(): void {
		$testOnly = new DeclaredGateway( DeclaredGateway::descriptor( 'test_only', array( Mode::Test ), array() ) );
		$payments = $this->paymentsOver( $this->db, $this->ids, $testOnly );
		$refunds  = $this->refundsOver( $this->db, $this->ids, $testOnly );
		$document = NewOrders::forTwoLines( 'USD', 'USD' );

		// An intent created live, as the gateway declared before it dropped its live mode.
		list( $order, $intent ) = $this->db->transaction(
			function () use ( $payments, $document ): array {
				$order = $this->orders->insert( $document, Actor::user( 0 ) );

				return array( $order, $payments->createIntent( $order->id, 'test_only', Mode::Live, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId ) );
			}
		);

		$this->assertRefused( 'live_mode_not_declared', 'test_only', static fn() => $payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => 'stub:approve' ), $order->uuid, $order->orderNumber ) );

		// Its earlier answers, as the gateway gave them while it still declared the mode.
		$this->db->transaction( static fn() => $payments->applyGatewayResult( new GatewayResult( 'test_only', Operation::Authorize, Outcome::Approved, $intent->uuid, $intent->amount, 'test-only-ch-' . $intent->uuid ), Actor::user( 0 ) ) );

		$this->assertRefused( 'live_mode_not_declared', 'test_only', fn() => $payments->capture( $intent->uuid, $this->userWithRole() ) );

		$this->db->transaction( static fn() => $payments->applyGatewayResult( new GatewayResult( 'test_only', Operation::Capture, Outcome::Approved, $intent->uuid, $intent->amount, 'test-only-cap-' . $intent->uuid ), Actor::user( 0 ) ) );

		$lines = $this->lineUuids( $order->id );

		$this->assertRefused( 'live_mode_not_declared', 'test_only', fn() => $this->refund( $order->uuid, array( $lines[0] => 1 ), false, $refunds ) );

		$this->assertSame( array(), $testOnly->calls, 'The gateway was never asked.' );
		$this->assertSame( 0, $this->claims(), 'No refund was claimed.' );
	}

	/**
	 * Asserts that a call is refused `payment.gateway_unavailable` for a gateway and a reason.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $reason    The reason.
	 * @param string   $gatewayId The gateway.
	 * @param callable $call      The call.
	 */
	private function assertRefused( string $reason, string $gatewayId, callable $call ): void {
		try {
			$call();
			$this->fail( "The call was made despite {$reason}." );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::GatewayUnavailable, $refused->errorCode(), $reason );
			$this->assertSame(
				array(
					'gateway_id' => $gatewayId,
					'reason'     => $reason,
				),
				$refused->context()
			);
		}
	}

	/**
	 * Counts the ledger's rows.
	 *
	 * @since 0.2.0
	 *
	 * @return int The rows.
	 */
	private function ledgerRows(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( PaymentTables::TRANSACTIONS ) );
	}

	/**
	 * Counts the refund claims.
	 *
	 * @since 0.2.0
	 *
	 * @return int The claims.
	 */
	private function claims(): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( RefundClaimTables::CLAIMS ) );
	}
}
