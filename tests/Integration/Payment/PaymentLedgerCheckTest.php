<?php
/**
 * Tests doctor's payment check: the projections against the ledger, and the money a person must reconcile
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Infrastructure\MysqlOrderRepository;
use SEOCart\Order\Infrastructure\OrderStatements;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Infrastructure\Doctor\PaymentLedgerCheck;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * Every amount the money path writes agrees with the ledger rows it was derived from, and doctor says so; anything else it names, and repairs nothing.
 *
 * The fixtures are made through the money path: approvals, a capture, partial refunds, a decline,
 * a wait and a mismatch. The drift is planted through the database afterwards, as something
 * outside the money path would leave it.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlPaymentRepository::INTENT_LEDGER, drop `AND t.applied = 1`: the mismatch's unapplied
 *   approval is summed, and the intent it moved nothing on is reported as drift;
 * - in PaymentLedgerCheck::walk(), read one page only: the drift on the last page is missed.
 *
 * @since 0.1.0
 */
final class PaymentLedgerCheckTest extends PaymentTestCase {

	/**
	 * Tests that the payments the money path made pass the check.
	 *
	 * @since 0.1.0
	 */
	public function test_the_payments_the_money_path_made_pass(): void {
		list( , $refunded ) = $this->placeCaptured();

		$this->deliver( self::stubResult( $refunded, Operation::Refund, Outcome::Approved, 1000, 'USD', 'stub-re-1' ) );
		$this->deliver( self::stubResult( $refunded, Operation::Refund, Outcome::Approved, 500, 'USD', 'stub-re-2' ) );
		$this->deliver( $this->authorizeWith( $this->placeWithIntent()[1], StubGateway::APPROVE ) );
		$this->deliver( $this->authorizeWith( $this->placeWithIntent()[1], StubGateway::DECLINE ) );
		$this->deliver( $this->authorizeWith( $this->placeWithIntent()[1], StubGateway::REQUIRES_ACTION ) );

		$result = $this->check()->run();

		$this->assertSame( array(), $result->findings );
		$this->assertTrue( $result->passed );
		$this->assertSame( PaymentLedgerCheck::NAME, $result->check );
	}

	/**
	 * Tests that an amount moved outside the money path is reported, for the intent, for the order, and as an over-refund, and that an order pointing at the wrong totals is.
	 *
	 * @since 0.1.0
	 */
	public function test_amounts_moved_outside_the_money_path_are_critical(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		list( $refundedOrder, $refunded ) = $this->placeCaptured();

		$this->db->execute( 'UPDATE %i SET authorized_minor = authorized_minor + 1 WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $intent->uuid );
		$this->db->execute( 'UPDATE %i SET paid_minor = paid_minor + 5 WHERE id = %d', $this->table( OrderTables::ORDERS ), $order->id );
		$this->db->execute( 'UPDATE %i SET refunded_minor = 4000 WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $refunded->uuid );
		$this->db->execute( 'UPDATE %i SET current_totals_id = NULL WHERE id = %d', $this->table( OrderTables::ORDERS ), $refundedOrder->id );

		$current = (int) $this->db->fetchValue( 'SELECT id FROM %i WHERE order_id = %d AND is_current = 1', $this->table( OrderTables::TOTALS ), $refundedOrder->id );
		$result  = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertSame(
			array(
				"Critical: payment {$intent->uuid} records authorized_minor 3081 while its applied approvals add up to 3080.",
				"Critical: payment {$refunded->uuid} records refunded_minor 4000 while its applied approvals add up to 0.",
				"Critical: order {$order->uuid} records authorized_minor 3080 while its payments add up to 3081; paid_minor 5 while its payments add up to 0.",
				"Critical: order {$refundedOrder->uuid} records refunded_minor 0 while its payments add up to 4000.",
				"Critical: payment {$refunded->uuid} refunded 4000 of the 3080 it captured.",
				"Critical: order {$refundedOrder->uuid} points at totals snapshot none, but its current snapshot is {$current}.",
			),
			$result->findings
		);
	}

	/**
	 * Tests that a mismatch is reported as money a person must reconcile, and that its unapplied row leaves every amount in agreement.
	 *
	 * @since 0.1.0
	 */
	public function test_a_mismatch_is_a_warning_and_every_amount_still_agrees(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::WRONG_AMOUNT ) );

		$row    = $this->ledgerOf( $order->id )[0];
		$result = $this->check()->run();

		$this->assertFalse( $result->passed, 'Money waiting for a person fails the check.' );
		$this->assertSame(
			array(
				"Warning: the authorize result {$row['uuid']} of payment {$intent->uuid} did not match its order and moved no money; a person must reconcile it.",
				"Warning: order {$order->uuid} holds money a person must reconcile.",
			),
			$result->findings,
			'Only the two warnings: the intent\'s, the order\'s and the over-refund lines all agree.'
		);
	}

	/**
	 * Tests that an order with something to pay and no intent is critical once it is past the stale threshold, while one whose grand total is zero has none by design.
	 *
	 * Three orders lose their intents through the database: one past the threshold with its total
	 * due, one placed a moment ago, and one past the threshold whose total is zero.
	 *
	 * Planted violation: in PaymentLedgerCheck::orderLines(), leave out the order with no intent:
	 * the check then passes while it can never be paid.
	 *
	 * @since 0.1.0
	 */
	public function test_an_order_with_something_to_pay_and_no_intent_is_critical(): void {
		list( $broken, $brokenIntent ) = $this->placeWithIntent();
		list( $fresh, $freshIntent )   = $this->placeWithIntent();
		list( $free, $freeIntent )     = $this->placeWithIntent();

		$orders = $this->table( OrderTables::ORDERS );

		$this->db->execute( 'DELETE FROM %i WHERE uuid IN ( %s, %s, %s )', $this->table( PaymentTables::INTENTS ), $brokenIntent->uuid, $freshIntent->uuid, $freeIntent->uuid );
		$this->db->execute( 'UPDATE %i SET grand_total_minor = 0, due_minor = 0, base_grand_total_minor = 0 WHERE id = %d', $orders, $free->id );
		$this->db->execute( 'UPDATE %i SET created_at = UTC_TIMESTAMP(6) - INTERVAL 11 MINUTE WHERE id IN ( %d, %d )', $orders, $broken->id, $free->id );

		$result = $this->check()->run();

		$this->assertFalse( $result->passed );
		$this->assertCount( 1, $result->findings, implode( "\n", $result->findings ) );
		$this->assertStringStartsWith( sprintf( 'Critical: order %1$s has a grand total of %2$d and no payment intent, ', $broken->uuid, self::GRAND_TOTAL ), $result->findings[0] );
		$this->assertStringNotContainsString( $fresh->uuid, $result->findings[0] );
	}

	/**
	 * Tests that the intents and the orders are compared a page at a time, every one of them, so a drift on the last page is found.
	 *
	 * @since 0.1.0
	 */
	public function test_the_intents_and_the_orders_are_read_a_page_at_a_time(): void {
		$placed = array();

		for ( $count = 0; $count < 5; ++$count ) {
			$placed[] = $this->placeWithIntent();
		}

		list( $lastOrder, $lastIntent ) = $placed[4];

		$this->db->execute( 'UPDATE %i SET authorized_minor = 7 WHERE id = %d', $this->table( OrderTables::ORDERS ), $lastOrder->id );
		$this->db->execute( 'UPDATE %i SET captured_minor = 9 WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $lastIntent->uuid );

		$result = null;
		$log    = $this->captureQueries(
			function () use ( &$result ): void {
				$result = $this->check( 2 )->run();
			}
		);

		$this->assertNotNull( $result );
		$this->assertSame(
			array(
				"Critical: payment {$lastIntent->uuid} records captured_minor 9 while its applied approvals add up to 0.",
				"Critical: order {$lastOrder->uuid} records authorized_minor 7 while its payments add up to 0; paid_minor 0 while its payments add up to 9.",
			),
			$result->findings
		);
		$this->assertQueryCount( 3, $log->matching( self::shapeOf( MysqlPaymentRepository::INTENT_LEDGER ) ), 'intents in pages of two, two and one' );
		$this->assertQueryCount( 3, $log->matching( self::shapeOf( MysqlOrderRepository::PAYMENT_AMOUNTS ) ), 'orders in pages of two, two and one' );
	}

	/**
	 * Builds the check over the test's connection.
	 *
	 * @since 0.1.0
	 *
	 * @param int $page Optional. How many orders one read compares. Default the production page.
	 * @return PaymentLedgerCheck The check.
	 */
	private function check( int $page = PaymentLedgerCheck::PAGE ): PaymentLedgerCheck {
		return new PaymentLedgerCheck( new MysqlPaymentRepository( $this->db, $this->ids ), new MysqlOrderRepository( new OrderStatements( $this->db ), $this->ids ), $page );
	}
}
