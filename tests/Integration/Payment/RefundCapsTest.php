<?php
/**
 * Tests the caps a refund's statements carry: each component, the shipping, and each line's units
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Platform\Database\ModuleStatements;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Connection B sends the refund module's own statements, prepared from their constants, against what refunds already returned: each cap refuses on its own.
 *
 * These are the database's checks, which hold whatever the service checked before: a share of a
 * tax component never goes against the component's sign and never past what is left of it, in
 * gross and in base gross; a document never returns more shipping than is left; and a line's
 * refunded quantity moves only from the quantity the refund was worked out from, never past the
 * units sold.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlRefundRepository::INSERT_COMPONENTS, neutralise both remainder caps,
 *   `( … ) >= 0 OR 1 = 1`: a share of a component already returned lands;
 * - in MysqlRefundRepository::INSERT_COMPONENTS, drop the sign, `IF( … ) * ` removed from the
 *   remainder caps: a free-shipping discount's negative component is returned twice;
 * - in MysqlRefundRepository::INSERT_REFUND, neutralise the shipping cap, `OR 1 = 1`: a second
 *   document returns the shipping again;
 * - in MysqlOrderRepository::ADD_REFUNDED_QUANTITIES, drop `order_line.refunded_quantity =
 *   asked.refunded_before`: units worked out from an older read land.
 *
 * @since 0.1.0
 */
final class RefundCapsTest extends RefundTestCase {

	/**
	 * Tests that a share of a component returned in full is refused, whichever way it points.
	 *
	 * @since 0.1.0
	 */
	public function test_a_component_returned_in_full_takes_no_more(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ), null ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$component     = $this->componentRows( $order->id, $tee )[0];

		$this->refund( $order->uuid, array( $tee => 3 ) );

		$b = $this->secondConnection();

		$b->query( $this->componentShare( $component, 1 ) );
		$this->assertSame( 0, $b->affectedRows(), 'A component returned in full takes no more.' );

		$b->query( $this->componentShare( $component, -1 ) );
		$this->assertSame( 0, $b->affectedRows(), 'A share never goes against its component\'s sign.' );
	}

	/**
	 * Tests that the negative component of a free-shipping discount, returned once, is refused a second time: its cap follows its sign.
	 *
	 * @since 0.1.0
	 */
	public function test_a_negative_component_is_capped_by_its_sign(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 2, 'standard' ) ), 'standard', freeShipping: true ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$discount      = $this->componentRows( $order->id, null )[1];

		$this->assertTrue( (int) $discount['gross_minor'] < 0 );

		$this->refund( $order->uuid, array( $tee => 1 ), true );

		$b = $this->secondConnection();

		$b->query( $this->componentShare( $discount, (int) $discount['gross_minor'] ) );
		$this->assertSame( 0, $b->affectedRows(), 'A negative component returned in full takes no more.' );
	}

	/**
	 * Tests that a document returning shipping already returned is refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_document_never_returns_more_shipping_than_is_left(): void {
		list( $order, $intent ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 2, 'untaxed' ) ), 'untaxed' ) );
		$first                  = $this->refund( $order->uuid, array(), true );

		$this->assertSame( 499, $first->shipping->minorUnits() );

		$b = $this->secondConnection();

		$b->query(
			$this->rawRefund(
				MysqlRefundRepository::INSERT_REFUND,
				'01928c3e-0000-7000-8000-0000000000b1',
				$order->id,
				(int) $this->intentRow( $intent->uuid )['id'],
				$first->transactionId + 1000,
				$order->conversionContextId,
				499,
				499,
				0,
				'EUR',
				'USD',
				$first->baseShipping->minorUnits(),
				$first->baseShipping->minorUnits(),
				0,
				'customer_return',
				'user',
				0,
				499,
				$order->id,
				499,
				499,
				$first->baseShipping->minorUnits(),
				$order->id,
				$first->baseShipping->minorUnits(),
				$first->baseShipping->minorUnits()
			)
		);

		$this->assertSame( 0, $b->affectedRows(), 'The shipping was returned already.' );
	}

	/**
	 * Tests that a line's refunded quantity moves only from the quantity a refund read, and never past the units sold.
	 *
	 * @since 0.1.0
	 */
	public function test_a_lines_units_move_only_from_the_quantity_the_refund_read(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ), null ) );
		list( $tee )   = $this->lineUuids( $order->id );
		$lineId        = (int) $this->lineRow( $tee )['id'];

		$this->refund( $order->uuid, array( $tee => 1 ) );

		$b = $this->secondConnection();

		$b->query( $this->units( $lineId, 1, 0, $order->id ) );
		$this->assertSame( 0, $b->affectedRows(), 'Units worked out when the line had none refunded do not land on a line with one.' );

		$b->query( $this->units( $lineId, 3, 1, $order->id ) );
		$this->assertSame( 0, $b->affectedRows(), 'No more units than were sold.' );

		$b->query( $this->units( $lineId, 2, 1, $order->id ) );
		$this->assertSame( 1, $b->affectedRows(), 'The units left, from the quantity the line has, land.' );
		$this->assertSame( '3', (string) $this->lineRow( $tee )['refunded_quantity'] );
	}

	/**
	 * Returns the insert of one share of a stored component, its gross and base gross the given amount, as connection B sends it.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $component The stored component's row.
	 * @param int                  $gross     The share's gross, and its base gross.
	 * @return string The statement.
	 */
	private function componentShare( array $component, int $gross ): string {
		return $this->rawRefund(
			MysqlRefundRepository::INSERT_COMPONENTS,
			999,
			0,
			(int) $component['id'],
			$gross,
			0,
			$gross,
			'EUR',
			$gross,
			0,
			$gross,
			(int) $component['gross_minor'],
			(int) $component['base_gross_minor']
		);
	}

	/**
	 * Returns the update of one line's refunded quantity, as connection B sends it.
	 *
	 * @since 0.1.0
	 *
	 * @param int $lineId         The line.
	 * @param int $quantity       The units returned.
	 * @param int $refundedBefore The refunded quantity the units were worked out from.
	 * @param int $orderId        The order.
	 * @return string The statement.
	 */
	private function units( int $lineId, int $quantity, int $refundedBefore, int $orderId ): string {
		global $wpdb;

		list( $sql, $arguments ) = OrderStatements::expand( ModuleStatements::forDerivedRows( MysqlOrderRepository::ADD_REFUNDED_QUANTITIES, 1 ), array( $lineId, $quantity, $refundedBefore, $orderId ), fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}
}
