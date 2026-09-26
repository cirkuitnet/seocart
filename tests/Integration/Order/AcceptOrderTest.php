<?php
/**
 * Tests accepting an order: the one transition that publishes OrderPlaced
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Events\Outbox;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * An approved payment makes a pending order `processing`, and the order's summary is published once.
 *
 * Planted violations, each shown red and removed:
 * - publish OrderPlaced from Orders::transitionLocked() whenever the target is `processing`,
 *   instead of from accept() alone: reinstating a cancelled order announces it a second time;
 * - add `on_hold` to Orders::NOT_YET_ACCEPTED: a parked order is accepted again, and announced
 *   twice.
 *
 * @since 0.1.0
 */
final class AcceptOrderTest extends OrderTestCase {

	/**
	 * Tests that accepting a pending order makes it processing and publishes its summary with the transition.
	 *
	 * @since 0.1.0
	 */
	public function test_accepting_a_pending_order_publishes_its_summary(): void {
		$inserted = $this->place( NewOrders::forTwoLines( 'EUR', 'USD', 12 ), Actor::user( 12 ) );
		$log      = $this->captureQueries(
			function () use ( $inserted ): void {
				$transition = $this->orders->accept( $inserted->id, Actor::system( 'payment', 12 ) );

				$this->assertSame( array( OrderStatus::PendingPayment, OrderStatus::Processing ), array( $transition->from, $transition->to ) );
			}
		);

		$this->assertQueryCount( 5, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'an acceptance: a transition and one more outbox row' );
		$this->assertSame( array( 'order:>pending_payment:placed', 'order:pending_payment>processing:payment_approved' ), $this->eventsOf( $inserted->id ) );
		$this->assertSame( 1, $this->outboxRows( OrderStatusChanged::eventName() ) );

		$placed = $this->db->fetchValue( 'SELECT payload_json FROM %i WHERE event_name = %s', $this->table( OutboxTable::NAME ), OrderPlaced::eventName() );

		$this->assertSame(
			array(
				'order_id'               => $inserted->id,
				'order_uuid'             => $inserted->uuid,
				'order_number'           => $inserted->orderNumber,
				'channel'                => 'storefront',
				'currency'               => 'EUR',
				'grand_total_minor'      => 3080,
				'base_currency'          => 'USD',
				'base_grand_total_minor' => 2464,
				'customer_id'            => 12,
				'actor_type'             => 'user',
				'actor_id'               => 12,
			),
			Outbox::decode( (string) $placed )['p'],
			'The summary names who placed the order, not the process that accepted it.'
		);
	}

	/**
	 * Tests that an order that may not be accepted is refused, and publishes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_that_may_not_be_accepted_publishes_nothing(): void {
		$orderId = $this->place()->id;

		$this->plantStatus( $orderId, OrderStatus::Failed, PaymentStatus::Failed );

		try {
			$this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );
			$this->fail( 'A failed order was accepted.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::TransitionIllegal, $refused->errorCode() );
		}

		$this->assertSame( 0, $this->outboxRows( OrderPlaced::eventName() ) );
		$this->assertSame( 0, $this->outboxRows( OrderStatusChanged::eventName() ) );
	}

	/**
	 * Tests that only accept() publishes OrderPlaced: moving a cancelled order back to processing does not announce it again.
	 *
	 * @since 0.1.0
	 */
	public function test_only_an_acceptance_publishes_the_summary(): void {
		$orderId = $this->place()->id;

		$this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );
		$this->orders->transition( $orderId, OrderStatus::Cancelled, 'customer_request', Actor::user( 1 ) );
		$this->orders->transition( $orderId, OrderStatus::Processing, 'reinstated', Actor::user( 1 ) );

		$this->assertSame( 1, $this->outboxRows( OrderPlaced::eventName() ) );
		$this->assertSame( 3, $this->outboxRows( OrderStatusChanged::eventName() ) );
	}

	/**
	 * Tests that an order is accepted once: a parked order is not accepted again, and returns to processing through a transition that announces nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_parked_order_is_not_accepted_twice(): void {
		$orderId = $this->place()->id;

		$this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );
		$this->orders->transition( $orderId, OrderStatus::OnHold, 'stock_unavailable', Actor::system( 'checkout', 3 ) );

		try {
			$this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );
			$this->fail( 'A parked order was accepted a second time.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::TransitionIllegal, $refused->errorCode() );
			$this->assertSame(
				array(
					'from' => 'on_hold',
					'to'   => 'processing',
				),
				$refused->context()
			);
		}

		$this->orders->transition( $orderId, OrderStatus::Processing, 'stock_arrived', Actor::user( 1 ) );

		$this->assertSame( 1, $this->outboxRows( OrderPlaced::eventName() ), 'The order was announced once.' );
		$this->assertSame(
			array( 'order:>pending_payment:placed', 'order:pending_payment>processing:payment_approved', 'order:processing>on_hold:stock_unavailable', 'order:on_hold>processing:stock_arrived' ),
			$this->eventsOf( $orderId )
		);
	}

	/**
	 * Tests that an order awaiting review is accepted, the other status an order not yet accepted is in.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_awaiting_review_is_accepted(): void {
		$orderId = $this->place()->id;

		$this->plantStatus( $orderId, OrderStatus::AwaitingReview, PaymentStatus::Authorized );

		$transition = $this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );

		$this->assertSame( array( OrderStatus::AwaitingReview, OrderStatus::Processing ), array( $transition->from, $transition->to ) );
		$this->assertSame( 1, $this->outboxRows( OrderPlaced::eventName() ) );
	}
}
