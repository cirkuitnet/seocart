<?php
/**
 * Tests two refunds of the same units at once: the gateway gives money back for both, and only one is recorded as a refund
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
use SEOCart\Tests\Support\Doubles\RecordingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * Two refunds of the last units of an order, on two real connections: both pass their reads and both are made by the gateway, and the caps let one be recorded.
 *
 * The two are different refunds, which ask the gateway with different keys. Connection A is the refund service over wpdb. While the gateway makes A's refund, after A's
 * reads and before A's transaction, the barrier runs B's whole refund, the refund service over a
 * second connection: B read the order as A did, the gateway gave B's money back, and B committed
 * its document. Then A goes on: the gateway gave A's money back too, but A's caps find what B
 * wrote. The money is never left unrecorded: A's ledger row is kept with `applied = 0`, the order
 * is flagged and put on hold, no document of A's is written, and A is answered
 * `payment.unreconciled`. The same refund asked twice at once asks with one key, and is made and
 * recorded once. Barriers, never pauses.
 *
 * Planted violations, each shown red and removed:
 * - in MysqlOrderRepository::ADD_REFUNDED_QUANTITIES, drop both conditions on
 *   `order_line.refunded_quantity`: the last unit of the untaxed line, which no component caps,
 *   is refunded twice;
 * - in MysqlOrderRepository::ADD_REFUNDED_QUANTITIES, drop only
 *   `order_line.refunded_quantity = asked.refunded_before`: three refunds worked out from one
 *   read all land, and the line ends refunded in full with a minor unit never returned;
 * - in MysqlRefundRepository::INSERT_REFUND, neutralise the shipping cap, `OR 1 = 1`: the untaxed
 *   shipping, which no component caps, is refunded twice;
 * - in RefundService::plan(), mint the refund's uuid at random, `wp_generate_uuid4()`: the same
 *   refund asked twice at once asks with two keys, the gateway makes two refunds, and one is kept
 *   for a person.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class RefundConcurrencyTest extends RefundTestCase {

	/**
	 * Tests that two refunds of a line's last unit at once leave one recorded and one for a person.
	 *
	 * The two are different refunds, with different keys: B returns a mug too. The order has four
	 * mugs, so the intent has room for A's refund too, and only the line's own caps refuse it.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider lineTaxClasses
	 *
	 * @param string $taxClass The tax class of the line refunded.
	 */
	public function test_two_refunds_of_the_last_unit_record_one_and_keep_the_other_for_a_person( string $taxClass ): void {
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
	 * Tests that refunds of a line's units all worked out from one read record only the first to commit, so the line never returns more than its stored figures.
	 *
	 * The line's 2.89, after its discount, is three units whose first share by largest remainder is
	 * 0.96: three refunds each worked out from the untouched line return 0.96 each, 2.88 of 2.89,
	 * and would leave the line refunded in full with a minor unit never returned. A, B and C each
	 * refund one unit of it, on three connections, each run while the gateway makes the one
	 * before's: all three read the line before any commits. They are different refunds, with
	 * different keys: B returns a mug too, and C two. C commits; B and A find the line's refunded
	 * quantity moved since their read, and are kept for a person, so the line has returned one
	 * unit's share, and two units' are left. The money kept for A and B then refuses the next
	 * refund, before the gateway, until a person has reconciled it.
	 *
	 * @since 0.1.0
	 */
	public function test_refunds_worked_out_from_one_read_record_only_the_first_to_commit(): void {
		list( $order )      = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '1.07', 3, 'untaxed' ), RefundOrders::line( 'mug', '5.00', 3, 'standard', variantId: 502 ) ), null, tenPercentOff: true ) );
		list( $gift, $mug ) = $this->lineUuids( $order->id );
		$stored             = self::figures( $this->lineRow( $gift ), 'line_' );

		$this->assertSame( 289, $stored['gross_minor'], 'Three units at 1.07, less ten percent: 2.89.' );

		list( , $connectionB ) = $this->secondOrders();
		list( , $connectionC ) = $this->secondOrders();

		$gatewayB = new RecordingGateway( new StubGateway(), $connectionB );
		$b        = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), $gatewayB );
		$c        = $this->refundsOver( $connectionC, new SequentialIdGenerator( 820000 ), new StubGateway() );
		$outcomes = array(
			'a' => 'never ran',
			'b' => 'never ran',
			'c' => 'never ran',
		);

		$gatewayB->during(
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
		$this->gateway->during(
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
				'a' => PaymentError::Unreconciled->value,
				'b' => PaymentError::Unreconciled->value,
				'c' => 'recorded',
			),
			$outcomes,
			'Only the first refund to commit is recorded; the two worked out from the same read are kept for a person.'
		);
		$this->assertSame( '1', (string) $this->lineRow( $gift )['refunded_quantity'] );
		$this->assertSame( array( 1, 96 ), array( $this->returnedOfLine( $gift )['quantity'], $this->returnedOfLine( $gift )['gross_minor'] ), 'The line returned C\'s unit, and only it.' );

		$this->gateway->during( static function (): void {} );
		$this->gateway->calls = array();

		try {
			$this->refund( $order->uuid, array( $gift => 2 ) );
			$this->fail( 'An order with money kept for a person was refunded again.' );
		} catch ( CodedException $kept ) {
			$this->assertSame( PaymentError::Unreconciled, $kept->errorCode() );
		}

		$this->assertSame( 0, $this->refundCalls(), 'The refund was refused before the gateway.' );
	}

	/**
	 * Tests that two refunds of the shipping at once leave one recorded and one for a person.
	 *
	 * The two are different refunds, with different keys: B returns a mug too.
	 *
	 * @since 0.1.0
	 */
	public function test_two_refunds_of_the_shipping_record_one_and_keep_the_other_for_a_person(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'mug', '5.00', 2, 'standard' ) ), 'untaxed' ) );
		list( $mug )   = $this->lineUuids( $order->id );
		$b             = $this->secondRefunds();

		$this->race( $order, fn(): Refund => $this->refund( $order->uuid, array(), true ), fn(): Refund => $this->refund( $order->uuid, array( $mug => 1 ), true, $b ) );

		$this->assertSame( array( '499' ), array_map( static fn( array $refund ): string => (string) $refund['shipping_minor'], $this->refundRows( $order->id ) ), 'The shipping was recorded as returned once.' );
	}

	/**
	 * Tests that the same refund asked twice at once is made once by the gateway and recorded once, and both are answered with its document.
	 *
	 * B asks for the same unit as A, from the same reads, while the gateway makes A's refund, and
	 * commits. Both asked with the same key, so the gateway answers A with the refund it made for
	 * B: the ledger has it, and its document is A's answer too.
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
		$this->assertSame( $gatewayB->refunds, $this->gateway->refunds, 'Both asked with one key, and the gateway answered both with one refund.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( '1', (string) $this->lineRow( $gift )['refunded_quantity'] );
		$this->assertSame( array( '1' ), array_map( 'strval', array_column( $this->db->fetchAll( "SELECT applied FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $order->id ), 'applied' ) ), 'One ledger row, applied.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
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
	 * Runs A's refund with B's whole refund run while the gateway makes A's, and checks what the race must leave.
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

		// The barrier: while the gateway makes A's refund, after A's reads and before A's transaction, B refunds and commits.
		$this->gateway->during(
			static function () use ( $b, &$won, $raced ): void {
				if ( count( $raced ) > 0 ) {
					return;
				}

				$raced->append( true );

				try {
					$won = $b();
				} catch ( \Throwable $failed ) {
					// Kept for the assertion below: thrown here, it would end A's gateway call instead.
					$won = $failed;
				}
			}
		);

		$this->gateway->calls = array();

		try {
			$a();
			$this->fail( 'A\'s refund was recorded; B\'s ' . ( $won instanceof \Throwable ? 'failed: ' . get_class( $won ) . ': ' . $won->getMessage() : 'was too.' ) );
		} catch ( CodedException $lost ) {
			$this->assertSame( PaymentError::Unreconciled, $lost->errorCode(), 'The refund that lost is answered for a person to reconcile.' );
		}

		$this->assertCount( 1, $raced, 'B never raced A.' );
		$this->assertInstanceOf( Refund::class, $won, 'B\'s refund was recorded: ' . ( $won instanceof \Throwable ? get_class( $won ) . ': ' . $won->getMessage() : '' ) );
		$this->assertCount( $documents + 1, $this->refundRows( $order->id ), 'Exactly one of the two refunds has a document.' );
		$this->assertSame( 1, $this->refundCalls(), 'The gateway made A\'s refund too: that is the money to reconcile.' );

		$kept = $this->db->fetchAll( "SELECT result, applied FROM %i WHERE order_id = %d AND operation = 'refund' AND applied = 0", $this->table( PaymentTables::TRANSACTIONS ), $order->id );

		$this->assertSame(
			array(
				array(
					'result'  => 'approved',
					'applied' => '0',
				),
			),
			$kept,
			'The money the gateway gave back for A is on the ledger, applied to nothing.'
		);

		$flagged = $this->orderRow( $order->id );

		$this->assertSame( array( '1', 'on_hold' ), array( (string) $flagged['has_unreconciled_money'], (string) $this->db->fetchValue( 'SELECT status FROM %i WHERE id = %d', $this->table( 'orders' ), $order->id ) ), 'The order is flagged and on hold.' );
	}
}
