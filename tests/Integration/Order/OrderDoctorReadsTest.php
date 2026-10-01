<?php
/**
 * Tests the order reads doctor's payment check pages through: payment amounts, flagged orders and the totals drift
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\Transition;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * Each read returns orders in id order, and only the orders it is about; the payment amounts a page at a time, after the last id of the page before.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlOrderRepository::PAYMENT_AMOUNTS, write `id >= %d`: the next page repeats the last
 *   order of the page before;
 * - in MysqlOrderRepository::CURRENT_TOTALS_DRIFT, drop `o.current_totals_id IS NULL OR`: the order
 *   pointing at no snapshot is not reported;
 * - the one named on its test.
 *
 * @since 0.1.0
 */
final class OrderDoctorReadsTest extends OrderTestCase {

	/**
	 * Tests that the payment amounts are read a page at a time, each page after the last id of the one before.
	 *
	 * @since 0.1.0
	 */
	public function test_payment_amounts_are_paged_by_id(): void {
		$ids = array( $this->place()->id, $this->place()->id, $this->place()->id );

		$this->db->execute( 'UPDATE %i SET authorized_minor = 3080, base_authorized_minor = 2464, paid_minor = 100 WHERE id = %d', $this->table( OrderTables::ORDERS ), $ids[1] );

		$first  = $this->repository()->paymentAmounts( 0, 2 );
		$second = $this->repository()->paymentAmounts( $first[1]['id'], 2 );

		$this->assertSame( array( $ids[0], $ids[1] ), array_column( $first, 'id' ) );
		$this->assertSame( array( $ids[2] ), array_column( $second, 'id' ) );
		$this->assertSame( array(), $this->repository()->paymentAmounts( $ids[2], 2 ) );
		$this->assertSame(
			array(
				'authorized'      => 3080,
				'paid'            => 100,
				'refunded'        => 0,
				'base_authorized' => 2464,
				'base_paid'       => 0,
				'base_refunded'   => 0,
			),
			array_diff_key( $first[1], array_flip( array( 'id', 'uuid', 'grand_total', 'age_seconds' ) ) )
		);
		$this->assertSame( 3080, $first[1]['grand_total'], 'Each order comes with its grand total.' );
		$this->assertLessThan( 60, $first[1]['age_seconds'], 'Each order comes with its age, by the database clock.' );
	}

	/**
	 * Tests that the orders with nothing due are read by their status, their total and their age: only those still pending payment, with a grand total of zero, placed long enough ago; in id order, after the id of the page before.
	 *
	 * Planted violation: in MysqlOrderRepository::NOTHING_DUE_IN_STATUS, drop `AND grand_total_minor = 0`:
	 * an order with its total due is read as one with nothing due.
	 *
	 * @since 0.1.0
	 */
	public function test_orders_with_nothing_due_still_pending_are_read_by_status_total_and_age(): void {
		$orders  = $this->table( OrderTables::ORDERS );
		$first   = $this->place();
		$due     = $this->place();
		$fresh   = $this->place();
		$settled = $this->place();
		$second  = $this->place();

		$this->db->execute( 'UPDATE %i SET grand_total_minor = 0, due_minor = 0 WHERE id IN ( %d, %d, %d, %d )', $orders, $first->id, $fresh->id, $settled->id, $second->id );
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE id <> %d', $orders, $fresh->id );
		$this->orders->accept( $settled->id, Actor::system( 'payment', 3 ) );

		$this->assertSame( array( $first->id, $second->id ), $this->repository()->pendingNothingDue( 600, 0, 20 ), 'Not the one with its total due, the one placed a moment ago, or the one accepted.' );
		$this->assertSame( array( $first->id ), $this->repository()->pendingNothingDue( 600, 0, 1 ), 'The limit is the most it reads, the first in id order.' );
		$this->assertSame( array( $second->id ), $this->repository()->pendingNothingDue( 600, $first->id, 20 ), 'A page starts after the last id of the page before.' );
		$this->assertNotContains( $due->id, $this->repository()->pendingNothingDue( 0, 0, 20 ) );
	}

	/**
	 * Tests that only the flagged orders are read, in id order, at most as many as asked.
	 *
	 * @since 0.1.0
	 */
	public function test_only_flagged_orders_are_read(): void {
		$this->place();

		$first  = $this->place();
		$second = $this->place();

		foreach ( array( $first, $second ) as $flagged ) {
			$this->db->transaction( fn(): ?Transition => $this->orders->park( $this->orders->lockForPayment( $flagged->id ), 'amount_mismatch', Actor::system( 'payment', 3 ) ) );
		}

		$this->assertSame(
			array(
				array(
					'id'   => $first->id,
					'uuid' => $first->uuid,
				),
				array(
					'id'   => $second->id,
					'uuid' => $second->uuid,
				),
			),
			$this->repository()->unreconciled( 20 )
		);
		$this->assertSame( array( $first->id ), array_column( $this->repository()->unreconciled( 1 ), 'id' ), 'The limit is the most it reads, the first in id order.' );
	}

	/**
	 * Tests that an order pointing at no snapshot, or at another than its current one, is read, and one pointing at its own is not.
	 *
	 * @since 0.1.0
	 */
	public function test_orders_not_pointing_at_their_current_totals_are_read(): void {
		$pointsAtNone  = $this->place();
		$pointsAtOther = $this->place();
		$consistent    = $this->place();

		$this->assertSame( array(), $this->repository()->currentTotalsDrift( 20 ), 'Every placed order points at its current snapshot.' );

		$this->db->execute( 'UPDATE %i SET current_totals_id = NULL WHERE id = %d', $this->table( OrderTables::ORDERS ), $pointsAtNone->id );
		$this->db->execute( 'UPDATE %i SET current_totals_id = %d WHERE id = %d', $this->table( OrderTables::ORDERS ), $this->currentTotals( $consistent->id ), $pointsAtOther->id );

		$this->assertSame(
			array(
				array(
					'id'        => $pointsAtNone->id,
					'uuid'      => $pointsAtNone->uuid,
					'points_at' => null,
					'current'   => $this->currentTotals( $pointsAtNone->id ),
				),
				array(
					'id'        => $pointsAtOther->id,
					'uuid'      => $pointsAtOther->uuid,
					'points_at' => $this->currentTotals( $consistent->id ),
					'current'   => $this->currentTotals( $pointsAtOther->id ),
				),
			),
			$this->repository()->currentTotalsDrift( 20 )
		);
	}

	/**
	 * Builds the order repository over the test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @return MysqlOrderRepository The repository.
	 */
	private function repository(): MysqlOrderRepository {
		return new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids );
	}

	/**
	 * Reads the id of an order's totals snapshot marked current.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return int The snapshot's id.
	 */
	private function currentTotals( int $orderId ): int {
		return (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE order_id = %d AND is_current = 1', $this->table( OrderTables::TOTALS ), $orderId );
	}
}
