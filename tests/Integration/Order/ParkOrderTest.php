<?php
/**
 * Tests parking an order a payment did not match: on hold, flagged for a person, inside the caller's transaction
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Domain\Transition;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * A parked order is flagged always, and on hold where its status allows, with the transition's record and event, in the transaction that locked it.
 *
 * Parking reuses the order's lock the caller already holds: four statements, the flag, the
 * transition's update, its record and its outbox row. An order already on hold, or in a status
 * on hold may not follow, is flagged in one statement and left as it is: the payment that did
 * not match it is recorded whatever the order's status.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::park(), skip the flag: the parked order is not flagged;
 * - in Orders::park(), transition whatever the status, as before: the order in a final status
 *   is refused with `order.transition_illegal`, and its flag goes with it.
 *
 * @since 0.1.0
 */
final class ParkOrderTest extends OrderTestCase {

	/**
	 * Tests that a pending order is put on hold and flagged, in four statements after its lock.
	 *
	 * @since 0.1.0
	 */
	public function test_a_pending_order_is_put_on_hold_and_flagged(): void {
		$orderId    = $this->place()->id;
		$transition = null;

		$this->db->transaction(
			function () use ( $orderId, &$transition ): void {
				$locked = $this->orders->lockForPayment( $orderId );
				$log    = $this->captureQueries(
					function () use ( $locked, &$transition ): void {
						$transition = $this->orders->park( $locked, 'amount_mismatch', Actor::system( 'payment', 3 ) );
					}
				);

				$this->assertQueryCount( 4, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'parking an order already locked' );
			}
		);

		$this->assertInstanceOf( Transition::class, $transition );
		$this->assertSame( array( OrderStatus::PendingPayment, OrderStatus::OnHold ), array( $transition->from, $transition->to ) );
		$this->assertSame( array( 'on_hold', '1' ), $this->statusAndFlag( $orderId ) );
		$this->assertSame( array( 'order:>pending_payment:placed', 'order:pending_payment>on_hold:amount_mismatch' ), $this->eventsOf( $orderId ) );
		$this->assertSame( 1, $this->outboxRows( OrderStatusChanged::eventName() ) );
	}

	/**
	 * Tests that an order in a status on hold may not follow, final or already on hold, is flagged in one statement and left in its status.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_that_cannot_go_on_hold_is_flagged_and_left_as_it_is(): void {
		foreach ( array( OrderStatus::Failed, OrderStatus::Completed, OrderStatus::OnHold ) as $status ) {
			$orderId = $this->place()->id;

			$this->plantStatus( $orderId, $status, PaymentStatus::Unpaid );

			$parked = false;

			$this->db->transaction(
				function () use ( $orderId, $status, &$parked ): void {
					$locked = $this->orders->lockForPayment( $orderId );
					$log    = $this->captureQueries(
						function () use ( $locked, &$parked ): void {
							$parked = $this->orders->park( $locked, 'amount_mismatch', Actor::system( 'payment', 3 ) );
						}
					);

					$this->assertQueryCount( 1, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), "parking a {$status->value} order: the flag alone" );
				}
			);

			$this->assertNull( $parked, "A {$status->value} order is not moved." );
			$this->assertSame( array( $status->value, '1' ), $this->statusAndFlag( $orderId ), "A {$status->value} order is flagged." );
			$this->assertSame( array( 'order:>pending_payment:placed' ), $this->eventsOf( $orderId ) );
		}

		$this->assertSame( 0, $this->outboxRows( OrderStatusChanged::eventName() ) );
	}

	/**
	 * Tests that parking outside a transaction is refused before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_parking_runs_only_inside_the_callers_transaction(): void {
		$orderId = $this->place()->id;
		$locked  = $this->db->transaction( fn(): LockedOrder => $this->orders->lockForPayment( $orderId ) );
		$log     = $this->captureQueries(
			function () use ( $locked ): void {
				try {
					$this->orders->park( $locked, 'amount_mismatch', Actor::system( 'payment', 3 ) );
					$this->fail( 'An order was parked outside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'inside the caller\'s transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'statements before the refusal' );
		$this->assertSame( array( 'pending_payment', '0' ), $this->statusAndFlag( $orderId ) );
	}

	/**
	 * Reads an order's status and its flag.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return array{0: string, 1: string} The status, and `1` when flagged.
	 */
	private function statusAndFlag( int $orderId ): array {
		$row = (array) $this->db->fetchRow( 'SELECT status, has_unreconciled_money FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );

		return array( (string) $row['status'], (string) $row['has_unreconciled_money'] );
	}
}
