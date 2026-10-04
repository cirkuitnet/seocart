<?php
/**
 * Tests that an operation a gateway does not declare is refused before anything is asked of it
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
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A gateway's capability matrix is the one declaration of what it can do: an authorization or a capture in a currency it has no row for, a partial refund its row does not declare, and a status query in such a currency are each refused `payment.operation_unsupported`, naming the gateway and the operation, with not one call made to the gateway and nothing written.
 *
 * Planted violation, shown red and removed: in PaymentService::capture(), move require() after the
 * call to the gateway: the capture is then refused only after the gateway was asked, and the
 * count of its calls is one.
 *
 * @since 0.2.0
 */
final class OperationUnsupportedTest extends RefundTestCase {

	/**
	 * Tests that a capture in a currency the matrix has no row for is refused before the gateway is called.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_the_matrix_does_not_declare_is_refused_before_the_call(): void {
		$gateway                = $this->limited( array( 'USD' ), Operations::REQUIRED );
		$payments               = $this->paymentsOver( $this->db, $this->ids, $gateway );
		list( $order, $intent ) = $this->authorizedThrough( $payments, 'EUR' );

		$this->assertUnsupported( Operations::AUTHORIZE, static fn() => $payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => 'stub:approve' ), $order->uuid, $order->orderNumber ) );
		$this->assertUnsupported( Operations::CAPTURE, fn() => $payments->capture( $intent->uuid, $this->userWithRole() ) );
		$this->assertSame( array(), $gateway->calls, 'Not one call was made to the gateway.' );
		$this->assertSame( 'authorized', $this->intentRow( $intent->uuid )['status'] );

		$this->assertUnsupported( Operations::QUERY, static fn() => $payments->queryGateway( $intent ) );
		$this->assertSame( array(), $gateway->calls, 'Not one status query either.' );
	}

	/**
	 * Tests that a partial refund the row does not declare is refused before it is claimed or asked of the gateway, and that a whole refund is made.
	 *
	 * @since 0.2.0
	 */
	public function test_a_partial_refund_the_matrix_does_not_declare_is_refused_before_the_claim(): void {
		$gateway  = $this->limited( array( 'USD' ), Operations::REQUIRED );
		$refunds  = $this->refundsOver( $this->db, $this->ids, $gateway );
		$payments = $this->paymentsOver( $this->db, $this->ids, $gateway );

		list( $order, $intent ) = $this->authorizedThrough( $payments, 'USD' );

		$lines = $this->lineUuids( $order->id );

		$this->db->transaction( static fn() => $payments->applyGatewayResult( self::capture( $intent ), Actor::user( 0 ) ) );

		$gateway->calls = array();

		$this->assertUnsupported( Operations::PARTIAL_REFUND, fn() => $this->refund( $order->uuid, array( $lines[0] => 1 ), false, $refunds ) );
		$this->assertSame( array(), $gateway->calls, 'Not one call was made to the gateway.' );
		$this->assertSame( 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i', $this->table( RefundClaimTables::CLAIMS ) ), 'No refund was claimed.' );
	}

	/**
	 * Builds a gateway `limited` that declares the currencies and operations given, without settings.
	 *
	 * @since 0.2.0
	 *
	 * @param array $currencies The currencies.
	 * @param array $operations The operations of every row.
	 * @return DeclaredGateway The gateway.
	 *
	 * @phpstan-param list<string> $currencies
	 * @phpstan-param list<string> $operations
	 */
	private function limited( array $currencies, array $operations ): DeclaredGateway {
		return new DeclaredGateway( DeclaredGateway::descriptor( 'limited', array( Mode::Test ), array(), DeclaredGateway::matrix( $currencies, $operations ) ) );
	}

	/**
	 * Places an order and creates its intent through `limited`, bypassing the checkout's own check, and applies the approval the gateway gave while its matrix still declared the cell.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentService $payments The service over `limited`.
	 * @param string         $currency The order's currency, also its base.
	 * @return array{0: InsertedOrder, 1: IntentRef} The order, and its intent, authorized.
	 */
	private function authorizedThrough( PaymentService $payments, string $currency ): array {
		$document = NewOrders::forTwoLines( $currency, $currency );

		list( $order, $intent ) = $this->db->transaction(
			function () use ( $payments, $document ): array {
				$order = $this->orders->insert( $document, Actor::user( 0 ) );

				return array( $order, $payments->createIntent( $order->id, 'limited', Mode::Test, $document->totals->grandTotal, $document->totals->baseGrandTotal, $order->conversionContextId ) );
			}
		);

		$approval = new GatewayResult( 'limited', Operation::Authorize, Outcome::Approved, $intent->uuid, $intent->amount, 'limited-ch-' . $intent->uuid );

		$this->db->transaction( static fn() => $payments->applyGatewayResult( $approval, Actor::user( 0 ) ) );

		return array( $order, $intent );
	}

	/**
	 * Returns the capture of an intent's whole amount, as `limited` reports one.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef $intent The intent.
	 * @return GatewayResult The approval.
	 */
	private static function capture( IntentRef $intent ): GatewayResult {
		return new GatewayResult( 'limited', Operation::Capture, Outcome::Approved, $intent->uuid, $intent->amount, 'limited-cap-' . $intent->uuid );
	}

	/**
	 * Asserts that a call is refused `payment.operation_unsupported`, naming `limited` and the operation.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $operation The operation.
	 * @param callable $call      The call.
	 */
	private function assertUnsupported( string $operation, callable $call ): void {
		try {
			$call();
			$this->fail( "The {$operation} was made." );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::OperationUnsupported, $refused->errorCode() );
			$this->assertSame(
				array(
					'gateway_id' => 'limited',
					'operation'  => $operation,
				),
				$refused->context()
			);
		}
	}
}
