<?php
/**
 * Tests that a person's clearance of an order's unreconciled money frees its payment's capture and void, until money a person must reconcile arrives again
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * A capture or a void of a payment holding a result a person must reconcile is refused before the gateway is asked; once a person has cleared the order's unreconciled money, only a result kept after the clearance refuses it, as for a refund.
 *
 * The result a person must reconcile here is an authorization the provider reports a second time,
 * under an object of its own, for a payment already authorized: the payment path keeps it applied
 * to nothing and flags the order.
 *
 * Planted violations, each shown red and removed: in PaymentService::refuseUnreconciled(), refuse
 * whatever the clearance, as before: the capture after the clearance is refused; compare the row
 * with the clearance strictly (`> 0`): a row dated at the clearance no longer refuses.
 *
 * @since 0.2.0
 */
final class CaptureAfterClearTest extends PaymentTestCase {

	/**
	 * Tests that a capture refused for a result a person must reconcile goes on once a person cleared the order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_goes_on_once_the_order_is_cleared(): void {
		list( $order, $intent ) = $this->authorizedWithAResultToReconcile( 'stub-again-1' );

		$this->assertRefusedUnreconciled( fn() => $this->payments->capture( $intent->uuid, $this->userWithRole() ), 'Before the clearance' );

		$this->clear( $order->uuid );

		$this->assertSame( ApplicationKind::Applied, $this->payments->capture( $intent->uuid, $this->userWithRole() )->kind );
		$this->assertSame( 'captured', $this->intentRow( $intent->uuid )['status'] );
		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ) );
	}

	/**
	 * Tests that a result a person must reconcile, kept after the clearance, refuses the capture and the void again before the gateway is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_kept_after_the_clearance_refuses_them_again(): void {
		list( $order, $intent ) = $this->authorizedWithAResultToReconcile( 'stub-again-1' );

		$this->clear( $order->uuid );
		$this->assertSame( ApplicationKind::Mismatch, $this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, self::GRAND_TOTAL, 'USD', 'stub-again-2' ) )->kind );

		$this->assertRefusedUnreconciled( fn() => $this->payments->capture( $intent->uuid, $this->userWithRole() ), 'A capture' );
		$this->assertRefusedUnreconciled( fn() => $this->payments->void( $intent->uuid, $this->userWithRole( 'seocart_manager' ), VoidReason::CustomerRequest ), 'A void' );
		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked nothing more.' );
	}

	/**
	 * Tests that a result dated at the very microsecond of the clearance refuses the capture and the void: a clearance is dated after every row it cleared, so a row of the same time was written after it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_result_dated_at_the_clearance_refuses_them(): void {
		list( $order, $intent ) = $this->authorizedWithAResultToReconcile( 'stub-again-1' );

		$this->clear( $order->uuid );
		$this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, self::GRAND_TOTAL, 'USD', 'stub-again-2' ) );
		$this->db->execute(
			'UPDATE %i t JOIN %i o ON o.id = t.order_id SET t.created_at = o.money_reconciled_at WHERE t.provider_object_id = %s',
			$this->table( PaymentTables::TRANSACTIONS ),
			$this->table( OrderTables::ORDERS ),
			'stub-again-2'
		);

		$this->assertSame(
			(string) $this->db->fetchValue( 'SELECT money_reconciled_at FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id ),
			(string) $this->db->fetchValue( 'SELECT created_at FROM %i WHERE provider_object_id = %s', $this->table( PaymentTables::TRANSACTIONS ), 'stub-again-2' ),
			'The row is dated at the clearance.'
		);

		$this->assertRefusedUnreconciled( fn() => $this->payments->capture( $intent->uuid, $this->userWithRole() ), 'A capture' );
		$this->assertRefusedUnreconciled( fn() => $this->payments->void( $intent->uuid, $this->userWithRole( 'seocart_manager' ), VoidReason::CustomerRequest ), 'A void' );
		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked nothing more.' );
	}

	/**
	 * Places an order in USD, authorizes its payment, and has the provider report the authorization again under another object: a result a person must reconcile.
	 *
	 * @since 0.2.0
	 *
	 * @param string $objectId The provider's object of the second report.
	 * @return array{0: \SEOCart\Order\Domain\InsertedOrder, 1: IntentRef} The order, flagged, and its intent, authorized.
	 */
	private function authorizedWithAResultToReconcile( string $objectId ): array {
		list( $order, $intent ) = $this->placeWithIntent( NewOrders::forTwoLines( 'USD', 'USD' ) );

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$this->assertSame( ApplicationKind::Mismatch, $this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, self::GRAND_TOTAL, 'USD', $objectId ) )->kind );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );

		return array( $order, $intent );
	}

	/**
	 * Clears an order's unreconciled money, as a person with the money-state override does.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 */
	private function clear( string $orderUuid ): void {
		$this->ordersOver( $this->db, $this->ids )->clearUnreconciledMoney(
			array(
				'order_uuid' => $orderUuid,
				'note'       => 'The provider reported the same authorization twice; one payment stands.',
			),
			$this->userWithRole( 'seocart_manager' )
		);
	}

	/**
	 * Asserts that a call is refused `payment.unreconciled`.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $call The call.
	 * @param string   $what What is refused.
	 */
	private function assertRefusedUnreconciled( \Closure $call, string $what ): void {
		try {
			$call();
			$this->fail( $what . ' went on with money a person must reconcile.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::Unreconciled, $refused->errorCode(), $what );
		}
	}
}
