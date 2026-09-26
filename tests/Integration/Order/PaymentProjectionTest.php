<?php
/**
 * Tests the payment projection seam: an order locked for a payment, and the payment's amounts added in one checked statement
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderChannel;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * The order's authorized, paid, refunded and due amounts move only by a payment's delta, in the order's currencies, never past its total.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlOrderRepository::RECORD_PAYMENT, recompute the amount due from the grand total,
 *   `due_minor = grand_total_minor - ( paid_minor + %d ) + ( refunded_minor + %d )`: an order
 *   whose amount due is less than its total, because another tender paid part of it, is made to
 *   owe the whole total again;
 * - drop `AND currency = %s` (and its value): a delta in another currency is added;
 * - neutralise the refund cap, `( refunded_minor + %d <= paid_minor + %d OR 1 = 1 )`: a refund of
 *   money never captured is added;
 * - neutralise the base authorization cap, `( base_authorized_minor + %d <= base_grand_total_minor
 *   OR 1 = 1 )`: an authorization past the total in the base currency is added;
 * - drop the base refund cap, `AND base_refunded_minor + %d <= base_paid_minor + %d` (and its two
 *   values): a refund of more base units than were captured is added.
 *
 * @since 0.1.0
 */
final class PaymentProjectionTest extends OrderTestCase {

	/**
	 * Tests that an authorization and then a capture of the whole total move the projection as the ledger would, with the base twins.
	 *
	 * @since 0.1.0
	 */
	public function test_an_authorization_then_a_capture_move_the_projection(): void {
		$orderId = $this->place( NewOrders::forTwoLines( 'EUR', 'USD' ) )->id;

		$locked = $this->db->transaction( fn(): LockedOrder => $this->orders->lockForPayment( $orderId ) );

		$this->assertSame( array( '000001', OrderChannel::Storefront, OrderStatus::PendingPayment, PaymentStatus::Unpaid, 3080, 3080, 2464, NewOrders::HOLD_GROUP ), array( $locked->orderNumber, $locked->channel, $locked->status, $locked->paymentStatus, $locked->grandTotal->minorUnits(), $locked->due->minorUnits(), $locked->baseGrandTotal->minorUnits(), $locked->holdGroup ) );

		$this->record( $orderId, $this->delta( 3080, 0, 0, 2464, 0, 0 ), PaymentStatus::Authorized, 'payment_authorized' );
		$this->assertSame( array( '3080', '0', '0', '3080', '2464', '0', 'authorized' ), $this->projection( $orderId ) );

		$this->record( $orderId, $this->delta( 0, 3080, 0, 0, 2464, 0 ), PaymentStatus::Paid, 'payment_captured' );
		$this->assertSame( array( '3080', '3080', '0', '0', '2464', '2464', 'paid' ), $this->projection( $orderId ), 'A capture of the whole total leaves nothing due.' );

		$this->record( $orderId, $this->delta( 0, 0, 1000, 0, 0, 800 ), PaymentStatus::PartiallyRefunded, 'refund_recorded' );
		$this->assertSame( array( '3080', '3080', '1000', '1000', '2464', '2464', 'partially_refunded' ), $this->projection( $orderId ), 'A refund makes its amount due again.' );

		$this->assertSame(
			array( 'order:>pending_payment:placed', 'payment:unpaid>authorized:payment_authorized', 'payment:authorized>paid:payment_captured', 'payment:paid>partially_refunded:refund_recorded' ),
			$this->eventsOf( $orderId )
		);
	}

	/**
	 * Tests that the amount due moves by the payment, from what it was, and never below nothing.
	 *
	 * The fixture order owes 2000 of its 3080: another tender paid the rest. Authorizing 2000 leaves
	 * 2000 due, capturing it leaves nothing, a capture past what is due is refused, and a refund
	 * of 500 makes 500 due again.
	 *
	 * @since 0.1.0
	 */
	public function test_the_amount_due_moves_by_the_payment_and_never_below_nothing(): void {
		$orderId = $this->place( NewOrders::forTwoLines( 'EUR', 'USD' ) )->id;

		$this->db->execute( 'UPDATE %i SET due_minor = 2000 WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );

		$this->record( $orderId, $this->delta( 2000, 0, 0, 1600, 0, 0 ), PaymentStatus::Authorized, 'payment_authorized' );
		$this->assertSame( array( '2000', '0', '0', '2000', '1600', '0', 'authorized' ), $this->projection( $orderId ) );

		$this->record( $orderId, $this->delta( 0, 2000, 0, 0, 1600, 0 ), PaymentStatus::Paid, 'payment_captured' );
		$this->assertSame( array( '2000', '2000', '0', '0', '1600', '1600', 'paid' ), $this->projection( $orderId ) );

		$overpaid = $this->db->transaction( fn(): bool => $this->orders->recordPayment( $this->orders->lockForPayment( $orderId ), $this->delta( 0, 1, 0, 0, 1, 0 ), PaymentStatus::Paid, 'payment_captured', Actor::user( 0 ) ) );

		$this->assertFalse( $overpaid, 'A capture past what is due leaves less than nothing due.' );

		$this->record( $orderId, $this->delta( 0, 0, 500, 0, 0, 400 ), PaymentStatus::PartiallyRefunded, 'refund_recorded' );
		$this->assertSame( array( '2000', '2000', '500', '500', '1600', '1600', 'partially_refunded' ), $this->projection( $orderId ) );
	}

	/**
	 * Tests that a refund is capped by what was captured in the base currency too.
	 *
	 * 100 order units are captured, which are 80 base units. A refund of 100 order units and 81
	 * base units keeps the order-currency cap but not the base one, and is refused; a refund of
	 * 100 and 80 is added.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_is_capped_in_the_base_currency_too(): void {
		$orderId = $this->place( NewOrders::forTwoLines( 'EUR', 'USD' ) )->id;

		$this->record( $orderId, $this->delta( 100, 0, 0, 80, 0, 0 ), PaymentStatus::PartiallyPaid, 'payment_authorized' );
		$this->record( $orderId, $this->delta( 0, 100, 0, 0, 80, 0 ), PaymentStatus::PartiallyPaid, 'payment_captured' );

		$refunded = $this->db->transaction( fn(): bool => $this->orders->recordPayment( $this->orders->lockForPayment( $orderId ), $this->delta( 0, 0, 100, 0, 0, 81 ), PaymentStatus::Refunded, 'refund_recorded', Actor::user( 0 ) ) );

		$this->assertFalse( $refunded, 'A refund of 81 base units of 80 captured was added.' );

		$this->record( $orderId, $this->delta( 0, 0, 100, 0, 0, 80 ), PaymentStatus::Refunded, 'refund_recorded' );
		$this->assertSame( '80', (string) $this->db->fetchValue( 'SELECT base_refunded_minor FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ) );
	}

	/**
	 * Tests that a payment that leaves the payment status as it was is recorded without an event, even when it moves nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_payment_that_keeps_the_status_writes_no_event(): void {
		$orderId = $this->place()->id;

		$this->record( $orderId, $this->delta( 1000, 0, 0, 1000, 0, 0, 'USD', 'USD' ), PaymentStatus::PartiallyPaid, 'payment_authorized' );
		$this->record( $orderId, $this->delta( 0, 0, 0, 0, 0, 0, 'USD', 'USD' ), PaymentStatus::PartiallyPaid, 'payment_authorized' );

		$this->assertSame( array( '1000', '0', '0', '3080', '1000', '0', 'partially_paid' ), $this->projection( $orderId ) );
		$this->assertSame( array( 'order:>pending_payment:placed', 'payment:unpaid>partially_paid:payment_authorized' ), $this->eventsOf( $orderId ) );
	}

	/**
	 * Tests that a delta in another currency, or one that would authorize or capture more than the total, is refused and moves nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_payment_in_another_currency_or_past_the_total_is_refused(): void {
		$orderId = $this->place( NewOrders::forTwoLines( 'EUR', 'USD' ) )->id;

		foreach (
			array(
				'another currency'       => $this->delta( 3080, 0, 0, 2464, 0, 0, 'GBP', 'USD' ),
				'another base currency'  => $this->delta( 3080, 0, 0, 2464, 0, 0, 'EUR', 'GBP' ),
				'more than authorizable' => $this->delta( 3081, 0, 0, 2465, 0, 0 ),
				'more than capturable'   => $this->delta( 0, 3081, 0, 0, 2465, 0 ),
				'a refund of nothing'    => $this->delta( 0, 0, 1, 0, 0, 1 ),
				'more than authorizable in the base currency' => $this->delta( 3080, 0, 0, 2465, 0, 0 ),
				'more than capturable in the base currency' => $this->delta( 0, 3080, 0, 0, 2465, 0 ),
			) as $case => $delta
		) {
			$recorded = $this->db->transaction( fn(): bool => $this->orders->recordPayment( $this->orders->lockForPayment( $orderId ), $delta, PaymentStatus::Authorized, 'payment_authorized', Actor::user( 0 ) ) );

			$this->assertFalse( $recorded, $case );
		}

		$this->assertSame( array( '0', '0', '0', '3080', '0', '0', 'unpaid' ), $this->projection( $orderId ) );
		$this->assertSame( array( 'order:>pending_payment:placed' ), $this->eventsOf( $orderId ) );
	}

	/**
	 * Tests that the payment seam runs only inside the caller's transaction, and that an unknown order is not found.
	 *
	 * @since 0.1.0
	 */
	public function test_the_seam_needs_the_callers_transaction_and_an_order(): void {
		$log = $this->captureQueries(
			function (): void {
				try {
					$this->orders->lockForPayment( 1 );
					$this->fail( 'An order was locked outside a transaction.' );
				} catch ( \LogicException $expected ) {
					$this->assertStringContainsString( 'caller\'s transaction', $expected->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'statements before the refusal' );

		try {
			$this->db->transaction( fn(): LockedOrder => $this->orders->lockForPayment( 999999 ) );
			$this->fail( 'An unknown order was locked.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::NotFound, $refused->errorCode() );
		}
	}

	/**
	 * Records a payment on an order, in a transaction of its own, and requires it to be accepted.
	 *
	 * @since 0.1.0
	 *
	 * @param int           $orderId The order.
	 * @param PaymentDelta  $delta   The amounts.
	 * @param PaymentStatus $status  The payment status after them.
	 * @param string        $reason  Why.
	 */
	private function record( int $orderId, PaymentDelta $delta, PaymentStatus $status, string $reason ): void {
		$this->assertTrue( $this->db->transaction( fn(): bool => $this->orders->recordPayment( $this->orders->lockForPayment( $orderId ), $delta, $status, $reason, Actor::system( 'payment', 3 ) ) ) );
	}

	/**
	 * Builds a delta.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $authorized     Authorized, in order minor units.
	 * @param int    $captured       Captured.
	 * @param int    $refunded       Refunded.
	 * @param int    $baseAuthorized Authorized, in base minor units.
	 * @param int    $baseCaptured   Captured, in base minor units.
	 * @param int    $baseRefunded   Refunded, in base minor units.
	 * @param string $currency       Optional. The order currency. Default EUR.
	 * @param string $base           Optional. The base currency. Default USD.
	 * @return PaymentDelta The delta.
	 */
	private function delta( int $authorized, int $captured, int $refunded, int $baseAuthorized, int $baseCaptured, int $baseRefunded, string $currency = 'EUR', string $base = 'USD' ): PaymentDelta {
		$money = static fn( int $minor ): Money => Money::of( $minor, Currency::of( $currency ) );
		$twin  = static fn( int $minor ): Money => Money::of( $minor, Currency::of( $base ) );

		return new PaymentDelta( $money( $authorized ), $money( $captured ), $money( $refunded ), $twin( $baseAuthorized ), $twin( $baseCaptured ), $twin( $baseRefunded ) );
	}

	/**
	 * Reads an order's payment projection.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> authorized, paid, refunded, due, base authorized, base paid and payment status.
	 */
	private function projection( int $orderId ): array {
		return array_values( (array) $this->db->fetchRow( 'SELECT authorized_minor, paid_minor, refunded_minor, due_minor, base_authorized_minor, base_paid_minor, payment_status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId ) );
	}
}
