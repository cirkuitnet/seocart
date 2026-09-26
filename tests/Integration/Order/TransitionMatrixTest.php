<?php
/**
 * Tests every pair of order statuses against the registry: a transition lands exactly when the table allows it
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An illegal order transition is impossible, and the matrix that proves it is generated from the registry.
 *
 * Every status is tried against every status, once with a settled payment status and once
 * unpaid. Nothing here names a pair: the expectation is the registry's own table, so a row added
 * to it changes the expectation and the behaviour together. Each landed transition writes one
 * `order_events` row and one OrderStatusChanged outbox row; each refusal writes neither.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::transitionLocked(), send the update with the order's own status as the only
 *   allowed one when it is processing and the target failed, bypassing the registry: the pair the
 *   table refuses lands;
 * - in MysqlOrderRepository::TRANSITION, neutralise the status list,
 *   `AND ( status IN ({list}) OR 1 = 1 )`: every refused pair lands;
 * - in MysqlOrderRepository::TRANSITION, drop the settled-payment term: `completed` is entered
 *   unpaid;
 * - for the rollback test, run the transition without Orders' transaction and with
 *   OrderStatements::requireTransaction() returning at once: the update commits on its own, and
 *   the order keeps the new status although its event was never written.
 *
 * @since 0.1.0
 */
final class TransitionMatrixTest extends OrderTestCase {

	/**
	 * Tests every pair of statuses, with a settled payment status and unpaid, against the registry.
	 *
	 * @since 0.1.0
	 */
	public function test_a_transition_lands_exactly_when_the_registry_allows_it(): void {
		$registry  = new OrderStatusRegistry();
		$mismatch  = array();
		$transited = 0;

		foreach ( array( PaymentStatus::Paid, PaymentStatus::Unpaid ) as $paymentStatus ) {
			foreach ( OrderStatus::cases() as $from ) {
				foreach ( OrderStatus::cases() as $to ) {
					$orderId  = $this->place()->id;
					$expected = $registry->isAllowed( $from, $to ) && ( ! $registry->metadata( $to )->isPaid || in_array( $paymentStatus, PaymentStatus::settled(), true ) );

					$this->plantStatus( $orderId, $from, $paymentStatus );

					$landed = $this->tryTransition( $orderId, $from, $to );
					$status = (string) $this->db->fetchValue( 'SELECT status FROM %i WHERE id = %d', $this->table( OrderTables::ORDERS ), $orderId );
					$events = $this->eventsOf( $orderId );

					$transited += $landed ? 1 : 0;

					$wanted = $expected ? array( $to->value, 2 ) : array( $from->value, 1 );

					if ( $landed !== $expected || array( $status, count( $events ) ) !== $wanted ) {
						$mismatch[] = sprintf( '%s -> %s while %s: %s, now %s, %d events', $from->value, $to->value, $paymentStatus->value, $landed ? 'landed' : 'refused', $status, count( $events ) );
					}
				}
			}
		}

		$this->assertSame( array(), $mismatch, 'The registry\'s table is the order state machine.' );
		$this->assertSame( $transited, $this->outboxRows( OrderStatusChanged::eventName() ), 'One OrderStatusChanged per landed transition, none per refusal.' );
		$this->assertGreaterThan( 0, $transited );
	}

	/**
	 * Tests that a transition sends three statements and its outbox row, and records who made it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_transition_costs_three_statements_and_its_outbox_row(): void {
		$orderId    = $this->place()->id;
		$transition = null;
		$log        = $this->captureQueries(
			function () use ( $orderId, &$transition ): void {
				$transition = $this->orders->transition( $orderId, OrderStatus::OnHold, 'amount_mismatch', Actor::system( 'payment', 3 ) );
			}
		);

		$this->assertQueryCount( 4, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'a transition' );
		$this->assertNotNull( $transition );
		$this->assertSame( array( OrderStatus::PendingPayment, OrderStatus::OnHold ), array( $transition->from, $transition->to ) );
		$this->assertSame(
			array( 'order', 'pending_payment', 'on_hold', 'amount_mismatch', 'system', '3' ),
			array_values( (array) $this->db->fetchRow( 'SELECT machine, from_status, to_status, reason, actor_type, actor_id FROM %i WHERE id = %d', $this->table( OrderTables::EVENTS ), $transition->eventId ) )
		);
	}

	/**
	 * Tests that a transition rolled back after its update leaves the old status, no event and no outbox row.
	 *
	 * The `query` filter throws when the event is about to be written, after the update. Connection
	 * B, which sees only what is committed, then sees the order as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_a_transition_rolled_back_after_its_update_leaves_nothing(): void {
		$orderId = $this->place()->id;
		$b       = $this->secondConnection();
		$failure = new \RuntimeException( 'The connection failed after the update.' );

		add_filter(
			'query',
			static function ( string $query ) use ( $failure ): string {
				if ( 1 === preg_match( self::shapeOf( MysqlOrderRepository::APPEND_EVENT ), $query ) ) {
					throw $failure;
				}

				return $query;
			}
		);

		try {
			$this->orders->transition( $orderId, OrderStatus::Processing, 'payment_approved', Actor::user( 0 ) );
			$this->fail( 'The transition did not fail.' );
		} catch ( \RuntimeException $thrown ) {
			$this->assertSame( $failure, $thrown );
		}

		$this->assertSame(
			array(
				'status'         => 'pending_payment',
				'payment_status' => 'unpaid',
			),
			$this->committedStatus( $b, $orderId )
		);
		$this->assertSame( '1', $b->fetchValue( sprintf( 'SELECT COUNT(*) FROM `%s` WHERE order_id = %d', $this->table( OrderTables::EVENTS ), $orderId ) ), 'Only the placement\'s event.' );
		$this->assertSame( 0, $this->committedEvents( $b, OrderStatusChanged::eventName() ) );
	}

	/**
	 * Tests that a transition of an order that does not exist is refused as not found, and a malformed reason before any statement.
	 *
	 * @since 0.1.0
	 */
	public function test_an_unknown_order_and_a_malformed_reason_are_refused(): void {
		try {
			$this->orders->transition( 999999, OrderStatus::Processing, 'payment_approved', Actor::user( 0 ) );
			$this->fail( 'An unknown order changed status.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::NotFound, $refused->errorCode() );
		}

		$log = $this->captureQueries(
			function (): void {
				try {
					$this->orders->transition( 1, OrderStatus::Processing, 'Payment approved!', Actor::user( 0 ) );
					$this->fail( 'A malformed reason was accepted.' );
				} catch ( \InvalidArgumentException $expected ) {
					$this->assertStringContainsString( 'snake_case', $expected->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log, 'statements before the refusal' );
	}

	/**
	 * Tries a transition and tells whether it landed; a refusal must be `order.transition_illegal` naming both statuses.
	 *
	 * @since 0.1.0
	 *
	 * @param int         $orderId The order.
	 * @param OrderStatus $from    The status it is in.
	 * @param OrderStatus $to      The status wanted.
	 * @return bool True when it landed.
	 */
	private function tryTransition( int $orderId, OrderStatus $from, OrderStatus $to ): bool {
		try {
			$this->orders->transition( $orderId, $to, 'matrix', Actor::user( 0 ) );

			return true;
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::TransitionIllegal, $refused->errorCode() );
			$this->assertSame(
				array(
					'from' => $from->value,
					'to'   => $to->value,
				),
				$refused->context()
			);

			return false;
		}
	}
}
