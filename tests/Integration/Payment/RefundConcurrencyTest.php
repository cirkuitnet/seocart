<?php
/**
 * Tests two refunds of the same units at once: one is recorded, and the other is never asked of the gateway
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Two refunds of the last units of an order, on two real connections, both worked out from the same reads: the first to claim is recorded, and the other is sent back to be asked again, before the gateway.
 *
 * The two are different refunds, which would ask the gateway with different keys. Connection A
 * is the refund service over wpdb. After A's reads and before A's claim's transaction begins,
 * the barrier runs B's whole refund, the refund service over a second connection: B read the order
 * as A did, claimed, the gateway gave B's money back, and B committed its document. Then A goes on:
 * under the intent's lock, A's claim finds the intent's refunded amount moved since A's reads, so
 * A writes nothing, asks nothing of the gateway, and is answered `payment.refund_retry`. Asked
 * again, A is worked out from what B left: refused by the caps before the gateway when nothing is
 * left, recorded when something is. The same refund asked twice at once is claimed once: the
 * second request finds the first one's claim and asks the gateway what became of the refund, never
 * for it again, so it is made and recorded once. Barriers, never pauses.
 *
 * The caps a refund's transaction carries are no longer met by a race here, since a claim made from
 * figures that moved is refused; RefundCapsTest holds each of them on its own.
 *
 * Planted violations, each shown red and removed:
 * - in RefundService::claim(), drop the check of what the refund's uuid is named by: A claims from
 *   figures that moved, the gateway gives its money back, and A is kept for a person;
 * - in RefundService::plan(), mint the refund's uuid at random, `wp_generate_uuid4()`: the same
 *   refund asked twice at once makes two claims, the gateway is asked for two refunds, and one is
 *   kept for a person;
 * - in RefundService::askedBefore(), ask the gateway for the refund again, `$this->gateway->refund()`,
 *   instead of asking what became of it: the second request asks the gateway for the refund too.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class RefundConcurrencyTest extends RefundTestCase {

	/**
	 * A's own unit of work, whose barrier runs just before A's claim's transaction begins.
	 *
	 * @since 0.1.0
	 *
	 * @var BarrierTransactions
	 */
	private BarrierTransactions $transactions;

	/**
	 * Builds A's refund service over a unit of work with a barrier before each of its transactions, which no test has set yet.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->transactions = new BarrierTransactions( $this->db );
		$this->refunds      = $this->refundsOver( $this->db, $this->ids, $this->gateway, tx: $this->transactions );
	}

	/**
	 * Tests that two refunds of a line's last unit at once record one, and send the other back before the gateway, where the caps then refuse it.
	 *
	 * The two are different refunds, with different keys: B returns a mug too. The order has four
	 * mugs, so the intent has room for A's refund too, and only the line has nothing left for it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider lineTaxClasses
	 *
	 * @param string $taxClass The tax class of the line refunded.
	 */
	public function test_two_refunds_of_the_last_unit_record_one_and_send_the_other_back( string $taxClass ): void {
		list( $order )      = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 2, $taxClass ), RefundOrders::line( 'mug', '5.00', 4, 'standard', variantId: 502 ) ), null ) );
		list( $gift, $mug ) = $this->lineUuids( $order->id );
		$b                  = $this->secondRefunds();

		$this->refund( $order->uuid, array( $gift => 1 ) );

		$this->race(
			$order,
			fn(): Refund => $this->refund( $order->uuid, array( $gift => 1 ) ),
			fn(): Refund => $this->refund(
				$order->uuid,
				array(
					$gift => 1,
					$mug  => 1,
				),
				false,
				$b
			)
		);

		$this->assertSame( PaymentError::RefundLineExhausted->value, $this->outcomeOf( fn(): Refund => $this->refund( $order->uuid, array( $gift => 1 ) ) ), 'Asked again, A is refused by the line\'s cap, before the gateway.' );
		$this->assertSame( 0, $this->refundCalls() );
		$this->assertSame( '2', (string) $this->lineRow( $gift )['refunded_quantity'] );
		$this->assertSame( 2, $this->returnedOfLine( $gift )['quantity'], 'The last unit was recorded once.' );
	}

	/**
	 * Returns the tax classes of the line raced for: one no component caps, and one a component caps too.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: string}> The classes.
	 */
	public static function lineTaxClasses(): array {
		return array(
			'an untaxed line' => array( 'untaxed' ),
			'a taxed line'    => array( 'standard' ),
		);
	}

	/**
	 * Tests that refunds of a line's units all worked out from one read record only the first to claim, and that the others, asked again, return exactly the line's stored figures.
	 *
	 * The line's 2.89, after its discount, is three units whose first share by largest remainder is
	 * 0.96: three refunds each worked out from the untouched line would return 0.96 each, 2.88 of
	 * 2.89. A, B and C each refund one unit of it, on three connections, each run just before the
	 * one before's claim's transaction: all three read the line before any claims. They are
	 * different refunds, with different keys: B returns a mug too, and C two. C claims and records;
	 * B and A find the intent's refunded amount moved under the lock, write nothing, and are
	 * answered `payment.refund_retry`. Asked again, each is worked out from what is left, so the
	 * line's three units return exactly its 2.89.
	 *
	 * @since 0.1.0
	 */
	public function test_refunds_worked_out_from_one_read_record_only_the_first_to_claim(): void {
		list( $order )      = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '1.07', 3, 'untaxed' ), RefundOrders::line( 'mug', '5.00', 3, 'standard', variantId: 502 ) ), null, tenPercentOff: true ) );
		list( $gift, $mug ) = $this->lineUuids( $order->id );
		$stored             = self::figures( $this->lineRow( $gift ), 'line_' );

		$this->assertSame( 289, $stored['gross_minor'], 'Three units at 1.07, less ten percent: 2.89.' );

		list( , $connectionB ) = $this->secondOrders();
		list( , $connectionC ) = $this->secondOrders();

		$transactionsB = new BarrierTransactions( $connectionB );
		$b             = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), new StubGateway(), tx: $transactionsB );
		$c             = $this->refundsOver( $connectionC, new SequentialIdGenerator( 820000 ), new StubGateway() );
		$outcomes      = array(
			'a' => 'never ran',
			'b' => 'never ran',
			'c' => 'never ran',
		);

		$transactionsB->beforeTransaction(
			function () use ( &$outcomes, $order, $gift, $mug, $c ): void {
				$outcomes['c'] = $this->outcomeOf(
					fn(): Refund => $this->refund(
						$order->uuid,
						array(
							$gift => 1,
							$mug  => 2,
						),
						false,
						$c
					)
				);
			}
		);
		$this->transactions->beforeTransaction(
			function () use ( &$outcomes, $order, $gift, $mug, $b ): void {
				$outcomes['b'] = $this->outcomeOf(
					fn(): Refund => $this->refund(
						$order->uuid,
						array(
							$gift => 1,
							$mug  => 1,
						),
						false,
						$b
					)
				);
			}
		);

		$outcomes['a'] = $this->outcomeOf( fn(): Refund => $this->refund( $order->uuid, array( $gift => 1 ) ) );

		$this->assertSame(
			array(
				'a' => PaymentError::RefundRetry->value,
				'b' => PaymentError::RefundRetry->value,
				'c' => 'recorded',
			),
			$outcomes,
			'Only the first refund to claim is recorded; the two worked out from the same read are sent back to be asked again.'
		);
		$this->assertSame( 0, $this->refundCalls(), 'A never reached the gateway.' );
		$this->assertSame( array( 1, 96 ), array( $this->returnedOfLine( $gift )['quantity'], $this->returnedOfLine( $gift )['gross_minor'] ), 'The line returned C\'s unit, and only it.' );

		$this->transactions->beforeTransaction( static function (): void {} );

		// Asked again, one unit at a time, from what each finds left.
		$this->refund( $order->uuid, array( $gift => 1 ) );
		$this->refund( $order->uuid, array( $gift => 1 ) );

		$this->assertSame( '3', (string) $this->lineRow( $gift )['refunded_quantity'] );
		$this->assertSame( array( 3, 289 ), array( $this->returnedOfLine( $gift )['quantity'], $this->returnedOfLine( $gift )['gross_minor'] ), 'The line returned exactly its stored 2.89.' );
		$this->assertSame( array(), $this->unappliedRefunds( $order ), 'No money was left for a person.' );
	}

	/**
	 * Tests that two refunds of the shipping at once record one, and send the other back before the gateway, where nothing is then left for it.
	 *
	 * The two are different refunds, with different keys: B returns a mug too.
	 *
	 * @since 0.1.0
	 */
	public function test_two_refunds_of_the_shipping_record_one_and_send_the_other_back(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'mug', '5.00', 2, 'standard' ) ), 'untaxed' ) );
		list( $mug )   = $this->lineUuids( $order->id );
		$b             = $this->secondRefunds();

		$this->race( $order, fn(): Refund => $this->refund( $order->uuid, array(), true ), fn(): Refund => $this->refund( $order->uuid, array( $mug => 1 ), true, $b ) );

		$this->assertSame( PaymentError::RefundNothingLeft->value, $this->outcomeOf( fn(): Refund => $this->refund( $order->uuid, array(), true ) ), 'Asked again, A finds no shipping left, before the gateway.' );
		$this->assertSame( 0, $this->refundCalls() );
		$this->assertSame( array( '499' ), array_map( static fn( array $refund ): string => (string) $refund['shipping_minor'], $this->refundRows( $order->id ) ), 'The shipping was recorded as returned once.' );
	}

	/**
	 * Tests that the same refund asked twice at once is asked of the gateway once and recorded once, and both are answered with its document.
	 *
	 * B asks for the same unit as A, from the same reads, while the gateway makes A's refund: A's
	 * claim is committed, so B's is refused, and B asks the gateway what became of the refund, never
	 * for it again. The gateway made it, so B records it and commits. Then A's answer arrives: the
	 * ledger has it, and its document is A's answer too.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_refund_asked_twice_at_once_is_made_and_recorded_once(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 2, 'standard' ) ), null ) );
		list( $gift )  = $this->lineUuids( $order->id );

		list( , $connectionB ) = $this->secondOrders();

		$gatewayB = new RecordingGateway( new StubGateway(), $connectionB );
		$b        = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), $gatewayB );
		$won      = array();

		$this->gateway->during(
			function () use ( &$won, $order, $gift, $b ): void {
				if ( array() === $won ) {
					$won[] = $this->refund( $order->uuid, array( $gift => 1 ), false, $b );
				}
			}
		);

		$refund = $this->refund( $order->uuid, array( $gift => 1 ) );

		$this->assertEquals( array( $refund ), $won, 'Both are answered with the one document.' );
		$this->assertSame(
			array(
				array(
					'key'    => $refund->uuid,
					'object' => 'stub-re-' . $refund->uuid,
				),
			),
			$this->gateway->refunds,
			'A asked the gateway for the refund, once.'
		);
		$this->assertSame(
			array(
				array(
					'method' => 'queryRefund',
					'depth'  => 0,
				),
			),
			$gatewayB->calls,
			'B asked the gateway what became of the refund, and never for it.'
		);
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( '1', (string) $this->lineRow( $gift )['refunded_quantity'] );
		$this->assertSame( array( '1' ), array_map( 'strval', array_column( $this->db->fetchAll( "SELECT applied FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $order->id ), 'applied' ) ), 'One ledger row, applied.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
		$this->assertSame( array( 'recorded' ), array_column( $this->claimRows( $order->id ), 'state' ), 'One claim, ended by the refund\'s record.' );
	}

	/**
	 * Runs a refund and says how it ended: recorded, or the code of the error it was refused with.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $refund Asks for the refund.
	 * @return string `recorded`, or the error's code.
	 */
	private function outcomeOf( callable $refund ): string {
		try {
			$refund();

			return 'recorded';
		} catch ( CodedException $refused ) {
			return $refused->errorCode()->value;
		}
	}

	/**
	 * Reads an order's refund results the ledger holds applied to nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param InsertedOrder $order The order.
	 * @return list<array<string, mixed>> The rows.
	 */
	private function unappliedRefunds( InsertedOrder $order ): array {
		return $this->db->fetchAll( "SELECT id FROM %i WHERE order_id = %d AND operation = 'refund' AND applied = 0", $this->table( PaymentTables::TRANSACTIONS ), $order->id );
	}

	/**
	 * Runs A's refund with B's whole refund run after A's reads, just before A's claim's transaction, and checks what the race must leave.
	 *
	 * @since 0.1.0
	 *
	 * @param InsertedOrder $order The order.
	 * @param callable      $a     A's refund, over wpdb.
	 * @param callable      $b     B's refund, over the second connection.
	 */
	private function race( InsertedOrder $order, callable $a, callable $b ): void {
		$documents = count( $this->refundRows( $order->id ) );
		$won       = null;
		$raced     = new \ArrayObject();

		// The barrier: after A's reads and before A's claim's transaction, B refunds and commits.
		$this->transactions->beforeTransaction(
			static function () use ( $b, &$won, $raced ): void {
				if ( count( $raced ) > 0 ) {
					return;
				}

				$raced->append( true );

				try {
					$won = $b();
				} catch ( \Throwable $failed ) {
					// Kept for the assertion below: thrown here, it would end A's refund instead.
					$won = $failed;
				}
			}
		);

		$this->gateway->calls = array();

		$this->assertSame( PaymentError::RefundRetry->value, $this->outcomeOf( $a ), 'A\'s claim found what A was worked out from moved, and sent A back to be asked again.' );
		$this->assertCount( 1, $raced, 'B never raced A.' );
		$this->assertInstanceOf( Refund::class, $won, 'B\'s refund was recorded: ' . ( $won instanceof \Throwable ? get_class( $won ) . ': ' . $won->getMessage() : '' ) );
		$this->assertCount( $documents + 1, $this->refundRows( $order->id ), 'B\'s refund, and only it, has a document.' );
		$this->assertSame( array_fill( 0, $documents + 1, 'recorded' ), array_column( $this->claimRows( $order->id ), 'state' ), 'A wrote no claim.' );
		$this->assertSame( 0, $this->refundCalls(), 'A never reached the gateway.' );
		$this->assertSame( array(), $this->unappliedRefunds( $order ), 'No money was left for a person.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}
}
