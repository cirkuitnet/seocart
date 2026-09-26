<?php
/**
 * Tests two transitions of one order at once: the second is judged against the status the first left
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * A transition waits for the one in flight, and then meets the status it left, not the one it read before.
 *
 * Connection A is the order service over wpdb, accepting a pending order. Just before A records
 * its event, connection B sends the repository's own TRANSITION statement to fail the same order,
 * as a payment decline would; the server shows B waiting for A's lock. When A commits, B's update
 * finds the order `processing`, which the registry does not let `failed` follow, and changes
 * nothing. Then B runs the service over a connection of its own, and is told why.
 *
 * Planted violation, shown red and removed: in MysqlOrderRepository::TRANSITION, neutralise the
 * status list, `AND ( status IN ({list}) OR 1 = 1 )`: B's update then lands on the processing
 * order.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class TransitionConcurrencyTest extends OrderTestCase {

	/**
	 * Tests that a decline racing an acceptance waits for it, and then changes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_decline_racing_an_acceptance_waits_and_then_changes_nothing(): void {
		$orderId  = $this->place()->id;
		$registry = new OrderStatusRegistry();
		$b        = $this->secondConnection();
		$decline  = $this->raw(
			MysqlOrderRepository::TRANSITION,
			OrderStatus::Failed->value,
			$orderId,
			array_map( static fn( OrderStatus $status ): string => $status->value, $registry->allowedFrom( OrderStatus::Failed ) ),
			0,
			array_map( static fn( PaymentStatus $status ): string => $status->value, PaymentStatus::settled() )
		);

		$raced = $this->beforeStatement(
			self::shapeOf( MysqlOrderRepository::APPEND_EVENT ),
			function () use ( $b, $decline ): void {
				$b->queryAsync( $decline );
				$this->awaitWaiting( $b, $decline, 'updating' );
			}
		);

		$this->orders->accept( $orderId, Actor::system( 'payment', 3 ) );

		$this->assertTrue( $raced->fired, 'B never raced A.' );
		$this->assertTrue( $b->isReady( 5000 ), 'B\'s update must go through once A commits.' );
		$this->assertSame( 0, $b->reap(), 'B failed an order A had just accepted.' );
		$this->assertSame(
			array(
				'status'         => 'processing',
				'payment_status' => 'unpaid',
			),
			$this->committedStatus( $b, $orderId )
		);

		list( $second ) = $this->secondOrders();

		try {
			$second->transition( $orderId, OrderStatus::Failed, 'payment_declined', Actor::system( 'payment', 3 ) );
			$this->fail( 'The service failed an accepted order.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( OrderError::TransitionIllegal, $refused->errorCode() );
			$this->assertSame(
				array(
					'from' => 'processing',
					'to'   => 'failed',
				),
				$refused->context()
			);
		}

		$this->assertSame( array( 'order:>pending_payment:placed', 'order:pending_payment>processing:payment_approved' ), $this->eventsOf( $orderId ) );
	}
}
