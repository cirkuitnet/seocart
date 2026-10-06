<?php
/**
 * Tests that the refund caps of a role hold for two refunds at once, on two real connections
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\BarrierRefundRepository;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * Two refunds at once, the second in a process of its own, keep the caps a role is held to.
 *
 * The cap of a day: one order agent refunds two orders at once, each for 548.07 of a cap of
 * 1000.00 a day. A has added up what the agent asked, under the agent's lock row, and has not
 * claimed yet; B, refunding the other order, waits for the row. A claims and commits; B then adds
 * up what the agent asked, A's claim with it, and is refused `payment.refund_cap_exceeded` whole:
 * no claim, nothing asked of the gateway.
 *
 * The cap of one order: two order agents refund one order of 197.30 and 98.65, with a cap of
 * 250.00 of one order. Whether the second waits for the first's claim under the intent's lock, or
 * worked its refund out before the first was recorded and claims after it, the second is refused
 * and writes nothing; asked again, it is refused `payment.refund_cap_exceeded` by the cap of one
 * order, counting the first's refund.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::claim(), decide the cap of one order from the figure the plan read, and drop
 *   the check of what the refund's uuid is named by: the second agent's refund is made, and the
 *   order's refunds come to 295.95; either change alone stays green, as the other refuses it;
 * - in RefundService::claim(), take the agent's lock row after the intent's: B's first read, of its
 *   intent under the lock, fixes what B's transaction sees before B waits for the row, so B adds
 *   up nothing of A's claim and both refunds are made;
 * - in RefundService::claim(), drop the agent's lock row: B never waits, and both are made.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class RefundRoleCapConcurrencyTest extends RefundTestCase {

	/**
	 * The process-list state of an insert waiting for a row lock.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const INSERT_WAIT = 'update';

	/**
	 * The second refund, in a process of its own, once started.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probeB = null;

	/**
	 * Whether the server showed the second refund waiting for a lock before it ended.
	 *
	 * @since 0.2.0
	 *
	 * @var bool
	 */
	private bool $bWaited = false;

	/**
	 * Tests that two refunds of one agent on two orders at once keep the cap of a day: the second waits for the agent's lock row, counts the first's claim, and is refused.
	 *
	 * @since 0.2.0
	 */
	public function test_two_refunds_of_one_agent_at_once_keep_the_cap_of_a_day(): void {
		$this->capOrderAgents( '', '1000.00' );

		list( $first )  = $this->placePaid( self::order() );
		list( $second ) = $this->placePaid( self::order() );
		list( $tvA )    = $this->lineUuids( $first->id );
		list( $tvB )    = $this->lineUuids( $second->id );
		$claims         = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$a              = $this->refundsOver( $this->db, $this->ids, $this->gateway, null, $claims );
		$lock           = $this->rawRefund( MysqlRefundRepository::LOCK_ACTOR, $this->agent()->userId() );
		$this->probeB   = null;

		// The barrier: A has added up what the agent asked, and holds the agent's row; B, the same
		// agent refunding the other order, runs until the server shows it waiting for the row, or ends.
		$claims->afterAskedToday(
			function () use ( $second, $tvB, $lock ): void {
				if ( null === $this->probeB ) {
					$this->probeB = $this->startRefundProbe( $second->uuid, array( $tvB => 1 ) );

					$this->bWaited = $this->awaitProbeWaitingOrEnd( $this->probeB, $lock, self::INSERT_WAIT );
				}
			}
		);

		$refund = $this->refund( $first->uuid, array( $tvA => 1 ), false, $a );

		$this->assertNotNull( $this->probeB, 'B never raced A.' );

		$report = $this->probeB->finish();
		$share  = $refund->baseTotal->minorUnits();

		$this->assertTrue( $this->bWaited, 'The server showed B waiting for the agent\'s lock row while A held it.' );
		$this->assertSame( PaymentError::RefundCapExceeded->value, $report['refused'] ?? null, 'B was answered: ' . (string) wp_json_encode( $report ) );
		$this->assertSame(
			array(
				'cap_kind'        => 'per_day',
				'limit_minor'     => 100000,
				'used_minor'      => $share,
				'requested_minor' => $share,
				'currency'        => 'USD',
			),
			$report['context'] ?? null,
			'B counted A\'s claim.'
		);
		$this->assertSame( array(), $this->claimRows( $second->id ), 'B claimed nothing, so it asked the gateway for nothing.' );
		$this->assertSame( array(), $this->refundRows( $second->id ) );
		$this->assertCount( 1, $this->refundRows( $first->id ) );
	}

	/**
	 * Tests that a second agent whose claim waits for the first's under the intent's lock is refused on the first's open claim, and, asked again, by the cap of one order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_second_agent_waiting_for_the_intent_is_refused_then_capped(): void {
		$this->capOrderAgents( '250.00', '' );

		list( $order, $intent ) = $this->placePaid( self::twoLines() );
		list( $big, $small )    = $this->lineUuids( $order->id );
		$other                  = $this->userWithRole();
		$claims                 = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$a                      = $this->refundsOver( $this->db, $this->ids, $this->gateway, null, $claims );
		$lock                   = $this->rawRefund( MysqlRefundRepository::LOCK_FOR_CLAIM, (int) $this->intentRow( $intent->uuid )['id'] );
		$this->probeB           = null;

		// The barrier: A holds the intent's lock inside its claim's transaction; B, the other agent, waits for it.
		$claims->afterLock(
			function () use ( $order, $small, $lock, $other ): void {
				if ( null === $this->probeB ) {
					$this->probeB  = $this->startRefundProbe( $order->uuid, array( $small => 1 ), null, $other );
					$this->bWaited = $this->awaitProbeWaitingOrEnd( $this->probeB, $lock, 'statistics' );
				}
			}
		);

		// A's claim is committed by the time its gateway call is made: B is let finish first.
		$this->gateway->during(
			function (): void {
				if ( null !== $this->probeB ) {
					$this->awaitProbeEnd( $this->probeB );
				}
			}
		);

		$refund = $this->refund( $order->uuid, array( $big => 1 ), false, $a );

		$this->gateway->during( static function (): void {} );
		$this->assertNotNull( $this->probeB, 'B never raced A.' );

		$report = $this->probeB->finish();

		$this->assertTrue( $this->bWaited, 'The server showed B waiting for the intent\'s lock.' );
		$this->assertSame( PaymentError::RefundUnresolved->value, $report['refused'] ?? null, 'B was answered: ' . (string) wp_json_encode( $report ) );
		$this->assertCappedAfter( $refund->baseTotal->minorUnits(), $order->id, $order->uuid, $small, $other );
	}

	/**
	 * Tests that a second agent who worked the refund out before the first was recorded, and claims after it, is refused, and, asked again, capped by the order's refunds.
	 *
	 * @since 0.2.0
	 */
	public function test_a_second_agent_claiming_after_the_first_was_recorded_is_refused_then_capped(): void {
		$this->capOrderAgents( '250.00', '' );

		list( $order )       = $this->placePaid( self::twoLines() );
		list( $big, $small ) = $this->lineUuids( $order->id );
		$other               = $this->userWithRole();
		list( , $a )         = $this->secondOrders();
		$first               = $this->refundsOver( $a, new SequentialIdGenerator( 800000 ), $this->gateway );
		$tx                  = new BarrierTransactions( $this->db );
		$b                   = $this->refundsOver( $this->db, $this->ids, $this->gateway, tx: $tx );
		$refund              = null;

		// B has read the order and worked its refund out; before B's claim, A's whole refund runs on its own connection.
		$tx->beforeTransaction(
			function () use ( $tx, $first, $order, $big, &$refund ): void {
				$tx->beforeTransaction( static function (): void {} );

				$refund = $first->refund( self::request( $order->uuid, array( $big => 1 ), false ), $this->agent() );
			}
		);

		try {
			$b->refund( self::request( $order->uuid, array( $small => 1 ), false ), $other );
			$this->fail( 'B was refunded past the cap of one order.' );
		} catch ( CodedException $refused ) {
			// The figures B's refund was named by moved; the cap of one order, under the lock, refuses it as well.
			$this->assertContains( $refused->errorCode(), array( PaymentError::RefundRetry, PaymentError::RefundCapExceeded ) );
		}

		$this->assertNotNull( $refund, 'A never ran at the barrier.' );
		$this->assertCappedAfter( $refund->baseTotal->minorUnits(), $order->id, $order->uuid, $small, $other );
	}

	/**
	 * Asserts that the order has the first agent's refund alone, and that the second agent, asking again, is refused by the cap of one order, which counts it.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $used      The first refund's base share, in minor units.
	 * @param int    $orderId   The order.
	 * @param string $orderUuid The order's uuid.
	 * @param string $small     The line the second agent asks for.
	 * @param Actor  $other     The second agent.
	 */
	private function assertCappedAfter( int $used, int $orderId, string $orderUuid, string $small, Actor $other ): void {
		$this->assertCount( 1, $this->claimRows( $orderId ), 'Only A\'s refund was claimed.' );
		$this->assertCount( 1, $this->refundRows( $orderId ) );

		try {
			$this->refunds->refund( self::request( $orderUuid, array( $small => 1 ), false ), $other );
			$this->fail( 'B, asked again, was refunded past the cap of one order.' );
		} catch ( CodedException $capped ) {
			$this->assertSame( array( PaymentError::RefundCapExceeded, 'per_order', 25000, $used ), array( $capped->errorCode(), $capped->context()['cap_kind'] ?? null, $capped->context()['limit_minor'] ?? null, $capped->context()['used_minor'] ?? null ) );
		}

		$this->assertCount( 1, $this->claimRows( $orderId ), 'The capped request claimed nothing.' );
	}

	/**
	 * Returns an order of two lines, 180.00 and 90.00 EUR, untaxed, with no shipping: 197.30 and 98.65 USD in the base currency.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The document.
	 */
	private static function twoLines(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'big', '180.00', 1, 'untaxed' ), RefundOrders::line( 'small', '90.00', 1, 'untaxed', variantId: 502 ) ), null );
	}

	/**
	 * Returns an order of one television of 500.00 EUR, untaxed, with no shipping: 548.07 USD in the base currency.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tv', '500.00', 1, 'untaxed' ) ), null );
	}
}
