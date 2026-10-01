<?php
/**
 * Tests doctor's payment check on refunds: each document against its lines and components, and each line's refunded units
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Refunds the service recorded pass doctor's payment check; a refund header or a refunded quantity changed outside it is named, and nothing is repaired.
 *
 * A refund states its tax, which must be what its components returned, and its total less its
 * shipping, fees and tax, which must be what its lines returned before tax, in both currencies.
 * An order line's refunded quantity must be the units its refunds returned. Both are compared a
 * page at a time, by id.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentLedgerCheck::run(), leave out refundDrift(): the header changed outside the refund
 *   is not reported;
 * - in PaymentLedgerCheck::run(), leave out refundedQuantityDrift(): the quantity changed outside
 *   the refund is not reported.
 *
 * @since 0.1.0
 */
final class RefundLedgerCheckTest extends RefundTestCase {

	/**
	 * Tests that the refunds the service recorded pass the check.
	 *
	 * @since 0.1.0
	 */
	public function test_the_refunds_the_service_recorded_pass(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );

		$this->refund( $order->uuid, array( $tee => 1 ) );
		$this->refund(
			$order->uuid,
			array(
				$tee => 2,
				$mug => 1,
			),
			true
		);

		$result = $this->check()->run();

		$this->assertSame( array(), $result->findings );
		$this->assertTrue( $result->passed );
	}

	/**
	 * Tests that a refund's header changed outside the refund is named, with what its rows add up to, in either currency.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_whose_rows_do_not_add_up_is_critical(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$first  = $this->refund( $order->uuid, array( $tee => 1 ) );
		$second = $this->refund( $order->uuid, array( $tee => 1 ), true );

		$this->db->execute( 'UPDATE %i SET tax_minor = tax_minor + 1, total_minor = total_minor + 1 WHERE id = %d', $this->table( RefundTables::REFUNDS ), $first->id );
		$this->db->execute( 'UPDATE %i SET base_total_minor = base_total_minor + 3 WHERE id = %d', $this->table( RefundTables::REFUNDS ), $second->id );

		$this->assertSame(
			array(
				sprintf( 'Critical: refund %1$s states tax_minor %2$d while its lines and components add up to %3$d.', $first->uuid, $first->tax->minorUnits() + 1, $first->tax->minorUnits() ),
				sprintf( 'Critical: refund %1$s states base_lines_net_minor %2$d while its lines and components add up to %3$d.', $second->uuid, $this->linesBaseNet( $second->id ) + 3, $this->linesBaseNet( $second->id ) ),
			),
			$this->check( 1 )->run()->findings,
			'Each refund is named, the second found on the last page.'
		);
	}

	/**
	 * Tests that an order line's refunded quantity changed outside the refund is named, with the units its refunds returned.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refunded_quantity_its_refunds_do_not_return_is_critical(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );

		$this->refund( $order->uuid, array( $tee => 1 ) );

		$this->db->execute( 'UPDATE %i SET refunded_quantity = 2 WHERE line_uuid = %s', $this->table( OrderTables::LINES ), $tee );
		$this->db->execute( 'UPDATE %i SET refunded_quantity = 1 WHERE line_uuid = %s', $this->table( OrderTables::LINES ), $mug );

		$this->assertSame(
			array(
				"Critical: order line {$tee} records 2 units refunded while its refunds returned 1.",
				"Critical: order line {$mug} records 1 units refunded while its refunds returned 0.",
			),
			$this->check( 1 )->run()->findings
		);
	}

	/**
	 * Builds the check over the test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page Optional. How many rows one read compares. Default the production page.
	 * @return PaymentLedgerCheck The check.
	 */
	private function check( int $page = PaymentLedgerCheck::PAGE ): PaymentLedgerCheck {
		return new PaymentLedgerCheck( new MysqlPaymentRepository( $this->db, $this->ids ), new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids ), $page );
	}

	/**
	 * Reads what a refund's lines returned before tax, in the base currency.
	 *
	 * @since 0.1.0
	 *
	 * @param int $refundId The refund.
	 * @return int The sum.
	 */
	private function linesBaseNet( int $refundId ): int {
		return (int) $this->db->fetchValue( 'SELECT SUM( base_net_minor ) FROM %i WHERE refund_id = %d', $this->table( RefundTables::LINES ), $refundId );
	}

	/**
	 * Builds the fixture order: three tees and two mugs, taxed, shipped.
	 *
	 * @since 0.1.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
