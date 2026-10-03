<?php
/**
 * Tests the refund service: a share of what was paid given back, at the order's own rate, and recorded once
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\Event\RefundRecorded;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Payment\Infrastructure\RefundTables;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Error\ErrorCode;
use SEOCart\Support\Events\DomainEvent;
use SEOCart\Support\Money;
use SEOCart\Tests\Support\Doubles\AnswerLosingGateway;
use SEOCart\Tests\Support\Doubles\OvergivingGateway;
use SEOCart\Tests\Support\Doubles\ReplayingGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A merchant refunds units of a paid order's lines, and its shipping, through the refund service; the stub gateway gives the money back.
 *
 * The order is priced by the engine in EUR, a presentment currency of a USD store, at a
 * version-1 rate: a tee at 12.34 gross, three of them, and a mug at 5.00 gross, two, both taxed
 * at 20 %, shipped at 4.99 net, taxed at 20 %.
 *
 * Planted violations, each shown red and removed:
 * - in RefundAllocation::line() and components(), allocate each refund from the stored figures and
 *   the line's whole quantity, not from what remains: refunded a unit at a time, the tee's shares
 *   no longer add up to what it stored, and the last refund is refused;
 * - in RefundService::plan(), leave out the call to checkCaps(): a refund past what the intent has
 *   left reaches the gateway, which the refusal owed it never to;
 * - in RefundService::refund(), ask the gateway inside the transaction that records the refund:
 *   the gateway records the call at depth 1;
 * - in RefundService::plan(), leave out the refusal of an intent with a result applied to nothing:
 *   a refund of a unit whose money is kept for a person reaches the gateway, and is made again;
 * - in RefundService::record(), catch only CodedException, as before: an error writing the
 *   document takes the ledger row of money the gateway gave back with it, and is thrown as it came;
 * - in RefundService::plan(), mint the refund's uuid at random, `wp_generate_uuid4()`: the refund
 *   asked again after its answer was lost asks the gateway with another key, and the gateway makes
 *   a second refund;
 * - in RefundService::documentOf(), leave out the comparison of uuids: another refund the gateway
 *   answers with one it made before is answered with that refund's document;
 * - in MysqlRefundRepository::INSERT_REFUND, make the no-shipping branches never true,
 *   `%d = -1 OR`: a refund of a unit after a refund of the shipping is refused, and its money kept
 *   for a person.
 *
 * @since 0.1.0
 */
final class RefundServiceTest extends RefundTestCase {

	/**
	 * Tests that a refund of one tee returns its share, at the order's own rate, and records the document, the ledger and the order once each.
	 *
	 * @since 0.1.0
	 */
	public function test_a_partial_refund_returns_its_share_and_records_it_once(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$refund = $this->refund( $order->uuid, array( $tee => 1 ) );

		$this->assertSame(
			array(
				array(
					'method' => 'refund',
					'depth'  => 0,
				),
			),
			$this->gateway->calls,
			'The gateway was asked once, outside any transaction.'
		);

		$rows = $this->refundRows( $order->id );

		$this->assertCount( 1, $rows );
		$this->assertSame( $refund->uuid, $rows[0]['uuid'] );
		$this->assertSame( (string) $order->conversionContextId, (string) $rows[0]['conversion_context_id'], 'The refund is at the order\'s own rate.' );
		$this->assertSame( array( 'EUR', 'USD', '0', '0', 'customer_return' ), array( $rows[0]['currency'], $rows[0]['base_currency'], (string) $rows[0]['shipping_minor'], (string) $rows[0]['fee_minor'], $rows[0]['reason_code'] ) );

		$line = $this->returnedOfLine( $tee );

		$this->assertSame( 1, $line['quantity'] );
		$this->assertSame( $line['gross_minor'], $refund->total->minorUnits(), 'A refund of one line returns that line\'s gross.' );
		$this->assertSame( $line['net_minor'] + $line['tax_minor'], $line['gross_minor'] );
		$this->assertSame( $line['base_net_minor'] + $line['base_tax_minor'], $line['base_gross_minor'] );
		$this->assertSame( '1', (string) $this->lineRow( $tee )['refunded_quantity'] );

		$intentRow = $this->intentRow( $intent->uuid );
		$orderRow  = $this->orderRow( $order->id );

		$this->assertSame( array( 'partially_refunded', (string) $refund->total->minorUnits(), (string) $refund->baseTotal->minorUnits() ), array( $intentRow['status'], (string) $intentRow['refunded_minor'], (string) $intentRow['base_refunded_minor'] ) );
		$this->assertSame( array( 'partially_refunded', (string) $refund->total->minorUnits(), (string) $refund->baseTotal->minorUnits() ), array( $orderRow['payment_status'], (string) $orderRow['refunded_minor'], (string) $orderRow['base_refunded_minor'] ) );

		$ledger = $this->db->fetchRow( "SELECT * FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $order->id );

		$this->assertNotNull( $ledger );
		$this->assertSame( array( (string) $refund->transactionId, '1', 'approved', (string) $refund->baseTotal->minorUnits(), 'stub-re-' . $refund->uuid ), array( (string) $ledger['id'], (string) $ledger['applied'], $ledger['result'], (string) $ledger['base_amount_minor'], $ledger['provider_object_id'] ) );

		$this->assertSame( 1, $this->outboxRows( RefundRecorded::eventName() ) );
		$this->assertSame(
			array(
				'refund_id'        => $refund->id,
				'refund_uuid'      => $refund->uuid,
				'order_id'         => $order->id,
				'transaction_id'   => $refund->transactionId,
				'total_minor'      => $refund->total->minorUnits(),
				'tax_minor'        => $refund->tax->minorUnits(),
				'currency'         => 'EUR',
				'base_total_minor' => $refund->baseTotal->minorUnits(),
				'base_currency'    => 'USD',
				'reason_code'      => 'customer_return',
			),
			$this->latestPayload( RefundRecorded::eventName() )
		);
	}

	/**
	 * Tests that refunding the three tees a unit at a time, or two and then one, returns exactly their stored figures, in both currencies, and leaves the intent refunded only once everything captured is.
	 *
	 * The shipping and the mugs go back with the last refund, so that refund returns everything left.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider splits
	 *
	 * @param int[] $split The units of each refund.
	 *
	 * @phpstan-param list<int> $split
	 */
	public function test_refunding_a_line_in_parts_returns_exactly_its_stored_figures( array $split ): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee, $mug )      = $this->lineUuids( $order->id );
		$last                   = count( $split ) - 1;

		foreach ( $split as $index => $units ) {
			$this->refund(
				$order->uuid,
				$index === $last ? array(
					$tee => $units,
					$mug => 2,
				) : array( $tee => $units ),
				$index === $last
			);

			$this->assertSame( $index === $last ? 'refunded' : 'partially_refunded', $this->intentRow( $intent->uuid )['status'], 'The intent is refunded only once everything captured is.' );
		}

		$stored = $this->lineRow( $tee );

		$this->assertSame( self::figures( $stored, 'line_' ), array_diff_key( $this->returnedOfLine( $tee ), array( 'quantity' => 0 ) ), 'The tee\'s refunds add up to its stored figures, to the minor unit, in both currencies.' );

		foreach ( $this->componentRows( $order->id, $tee ) as $component ) {
			$this->assertSame( self::figures( $component ), $this->returnedOfComponent( (int) $component['id'] ), 'Each of the tee\'s components is returned exactly.' );
		}

		$intentRow = $this->intentRow( $intent->uuid );

		$this->assertSame( array( (string) $intentRow['captured_minor'], (string) $intentRow['base_captured_minor'] ), array( (string) $intentRow['refunded_minor'], (string) $intentRow['base_refunded_minor'] ), 'Everything captured was given back, in both currencies.' );
	}

	/**
	 * Returns the ways the three tees are refunded.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: list<int>}> The splits.
	 */
	public static function splits(): array {
		return array(
			'one at a time'    => array( array( 1, 1, 1 ) ),
			'two and then one' => array( array( 2, 1 ) ),
		);
	}

	/**
	 * Tests that each refusal the reads can tell is answered before the gateway is asked, and writes nothing.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refusal_the_reads_can_tell_never_reaches_the_gateway(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );

		$this->assertRefusedBeforeTheGateway(
			PaymentError::RefundLineExhausted,
			fn() => $this->refund( $order->uuid, array( $tee => 4 ) ),
			array(
				'line_uuid'  => $tee,
				'returnable' => 3,
			)
		);
		$this->assertRefusedBeforeTheGateway( PaymentError::RefundLineNotFound, fn() => $this->refund( $order->uuid, array( '01928c3e-0000-7000-8000-00000000dead' => 1 ) ), array( 'line_uuid' => '01928c3e-0000-7000-8000-00000000dead' ) );
		$this->assertRefusedBeforeTheGateway( OrderError::NotFound, fn() => $this->refund( '01928c3e-0000-7000-8000-00000000beef', array( $tee => 1 ) ) );

		// By amount: a refund applied to the ledger outside any document leaves the intent less than a tee.
		$captured = (int) $this->intentRow( $intent->uuid )['captured_minor'];

		$this->db->transaction( fn() => $this->payments->applyGatewayResult( self::stubResult( $intent, Operation::Refund, Outcome::Approved, $captured - 100, 'EUR', 'stub-re-outside' ), self::system(), Money::of( (int) $this->intentRow( $intent->uuid )['base_captured_minor'] - 100, Currency::of( 'USD' ) ) ) );

		$this->assertRefusedBeforeTheGateway( PaymentError::RefundExceedsCaptured, fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) );
	}

	/**
	 * Tests that a component already returned past what it stored, as only a write outside the refund could leave it, refuses the next refund of its line before the gateway is asked.
	 *
	 * @since 0.1.0
	 */
	public function test_a_component_with_nothing_left_refuses_before_the_gateway(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$component     = $this->componentRows( $order->id, $tee )[0];

		$this->db->execute(
			"INSERT INTO %i ( refund_id, refund_line_id, order_tax_component_id, net_minor, tax_minor, gross_minor, currency, base_net_minor, base_tax_minor, base_gross_minor, created_at ) VALUES ( 999, NULL, %d, %d, %d, %d, 'EUR', %d, %d, %d, UTC_TIMESTAMP(6) )",
			$this->table( RefundTables::COMPONENTS ),
			(int) $component['id'],
			(int) $component['net_minor'] + 1,
			(int) $component['tax_minor'] + 1,
			(int) $component['gross_minor'] + 2,
			(int) $component['base_net_minor'] + 1,
			(int) $component['base_tax_minor'] + 1,
			(int) $component['base_gross_minor'] + 2
		);

		$this->assertRefusedBeforeTheGateway( PaymentError::RefundExceedsCaptured, fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) );
	}

	/**
	 * Tests that an order whose payment was authorized and never captured has nothing to refund, and that shipping already given back is nothing left.
	 *
	 * @since 0.1.0
	 */
	public function test_nothing_captured_or_nothing_left_is_refused_before_the_gateway(): void {
		list( $order, $intent ) = $this->placeWithIntent( self::order() );

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$this->assertRefusedBeforeTheGateway( PaymentError::RefundNotRefundable, fn() => $this->refund( $order->uuid, array(), true ), array( 'order_uuid' => $order->uuid ) );

		list( $paid ) = $this->placePaid( self::order() );

		$this->refund( $paid->uuid, array(), true );
		$this->gateway->calls = array();

		$this->assertRefusedBeforeTheGateway( PaymentError::RefundNothingLeft, fn() => $this->refund( $paid->uuid, array(), true ) );
	}

	/**
	 * Tests that a declined refund is recorded on the ledger and changes nothing else: no document, no units, no money.
	 *
	 * @since 0.1.0
	 */
	public function test_a_declined_refund_records_the_decline_and_nothing_else(): void {
		list( $order, $intent ) = $this->placePaid( self::order(), StubGateway::REFUND_DECLINE );
		list( $tee )            = $this->lineUuids( $order->id );
		$before                 = array( $this->intentRow( $intent->uuid ), $this->orderRow( $order->id ), $this->lineRow( $tee ), $this->refundTableCounts() );

		try {
			$this->refund( $order->uuid, array( $tee => 1 ) );
			$this->fail( 'A declined refund was answered as made.' );
		} catch ( CodedException $declined ) {
			$this->assertSame( PaymentError::RefundDeclined, $declined->errorCode() );
		}

		$this->assertSame( $before, array( $this->intentRow( $intent->uuid ), $this->orderRow( $order->id ), $this->lineRow( $tee ), $this->refundTableCounts() ), 'A decline changes no intent, order, line or refund row.' );

		$declined = $this->db->fetchAll( "SELECT result, applied, error_code FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $order->id );

		$this->assertSame(
			array(
				array(
					'result'     => 'declined',
					'applied'    => '1',
					'error_code' => StubGateway::REFUND_DECLINED,
				),
			),
			$declined,
			'The decline is on the ledger.'
		);
		$this->assertSame( 0, $this->outboxRows( RefundRecorded::eventName() ) );
	}

	/**
	 * Tests that another refund the gateway answers with a refund it already made is refused, `payment.unreconciled`, and moves nothing: that refund's document is not this one's answer.
	 *
	 * Once the first refund of a tee was recorded, the intent's refunded amount moved, so the same
	 * units asked again are another refund, with another key. The gateway answering it with the
	 * first refund did not make it.
	 *
	 * @since 0.1.0
	 */
	public function test_another_refund_answered_with_one_made_before_is_refused_and_moves_nothing(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$replaying     = $this->refundsOver( $this->db, $this->ids, new ReplayingGateway( $this->gateway ) );
		$first         = $this->refund( $order->uuid, array( $tee => 1 ), false, $replaying );
		$before        = array( $this->snapshot(), $this->refundTableCounts(), $this->lineRow( $tee ) );

		// The same amount, which is applied and found a duplicate; and another amount, which is kept and found a duplicate.
		foreach ( array( 1, 2 ) as $units ) {
			try {
				$this->refund( $order->uuid, array( $tee => $units ), false, $replaying );
				$this->fail( "A refund of {$units} tee was answered with the first refund's document." );
			} catch ( CodedException $refused ) {
				$this->assertSame( PaymentError::Unreconciled, $refused->errorCode() );
			}

			$this->assertSame( $before, array( $this->snapshot(), $this->refundTableCounts(), $this->lineRow( $tee ) ), 'A duplicate of another refund changes nothing.' );
		}

		$this->assertSame( array( $first->uuid ), array_column( $this->refundRows( $order->id ), 'uuid' ) );
	}

	/**
	 * Tests that the same refund asked again after the gateway's answer was lost finds its claim, asks the gateway what became of it, never for it again, and is recorded once, as the refund the gateway made the first time; other refunds have other keys.
	 *
	 * @since 0.1.0
	 */
	public function test_the_same_refund_asked_again_after_its_answer_was_lost_is_recorded_once(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );
		$losing            = $this->refundsOver( $this->db, $this->ids, new AnswerLosingGateway( $this->gateway ) );

		try {
			$this->refund( $order->uuid, array( $tee => 1 ), true, $losing );
			$this->fail( 'A refund whose answer was lost was answered as made.' );
		} catch ( GatewayUnavailable $lost ) {
			$this->assertSame( array( 0, 0, 0 ), array_values( $this->refundTableCounts() ), 'Nothing was recorded.' );
		}

		$refund = $this->refund( $order->uuid, array( $tee => 1 ), true, $losing );
		$made   = array(
			'key'    => $refund->uuid,
			'object' => 'stub-re-' . $refund->uuid,
		);

		$this->assertSame( array( $made ), $this->gateway->refunds, 'The gateway was asked for the refund once.' );
		$this->assertSame( array( 'refund', 'queryRefund' ), array_column( $this->gateway->calls, 'method' ), 'Asked again, the gateway was asked what became of the refund.' );
		$this->assertSame( array( 1, 1, 1 ), array_map( 'count', array( $this->refundRows( $order->id ), $this->refundLedger( $order->id ), $this->db->fetchAll( 'SELECT id FROM %i WHERE refund_id = %d', $this->table( RefundTables::LINES ), $refund->id ) ) ), 'One document, one ledger row, one line.' );
		$this->assertSame( array( $made['object'], '1' ), array_values( $this->refundLedger( $order->id )[0] ) );

		// The same units again now that the first was recorded, and other units: other refunds, with other keys.
		$again = $this->refund( $order->uuid, array( $tee => 1 ) );
		$mugs  = $this->refund( $order->uuid, array( $mug => 1 ) );

		$this->assertSame( array( $refund->uuid, $again->uuid, $mugs->uuid ), array_column( $this->gateway->refunds, 'key' ) );
		$this->assertCount( 3, array_unique( array( $refund->uuid, $again->uuid, $mugs->uuid ) ), 'Each refund has its own key.' );
	}

	/**
	 * Tests that a refund that asks for no shipping is recorded after a refund of the shipping: the shipping cap holds only a refund that returns some.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_without_the_shipping_is_recorded_after_the_shipping_was_returned(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$this->assertSame( 499, $this->refund( $order->uuid, array(), true )->shipping->minorUnits() );

		$refund = $this->refund( $order->uuid, array( $tee => 1 ) );

		$this->assertSame( array( 1234, 0 ), array( $refund->total->minorUnits(), $refund->shipping->minorUnits() ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that money kept for a person refuses another refund before the gateway, until a person has reconciled it.
	 *
	 * An approval of another amount than was asked is kept on the ledger, applied to nothing. A
	 * refund of the same tee asked again is not known to be another refund: had it reached the
	 * gateway, the money would have been given back twice.
	 *
	 * @since 0.1.0
	 */
	public function test_money_kept_for_a_person_refuses_another_refund_before_the_gateway(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$overgiving    = $this->refundsOver( $this->db, $this->ids, new OvergivingGateway( new StubGateway() ) );

		try {
			$this->refund( $order->uuid, array( $tee => 1 ), false, $overgiving );
			$this->fail( 'A refund of another amount than asked was recorded.' );
		} catch ( CodedException $kept ) {
			$this->assertSame( PaymentError::Unreconciled, $kept->errorCode() );
		}

		$this->assertRefusedBeforeTheGateway( PaymentError::Unreconciled, fn() => $this->refund( $order->uuid, array( $tee => 1 ) ) );
		$this->assertRefusedBeforeTheGateway( PaymentError::Unreconciled, fn() => $this->refund( $order->uuid, array(), true ) );
	}

	/**
	 * Tests that an error writing the document, not a refusal, keeps the money the gateway gave back on the ledger for a person, and is the previous exception of the answer.
	 *
	 * The event the document publishes fails with an \Error, after the ledger row, the document and
	 * its lines were written: the savepoint takes them back, and the money stays on the ledger.
	 *
	 * @since 0.1.0
	 */
	public function test_an_error_writing_the_document_keeps_the_money_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$failing                = $this->refundsOver(
			$this->db,
			$this->ids,
			$this->gateway,
			new class() implements EventPublisher {

				/**
				 * Fails, as code that is not a refusal can.
				 *
				 * @since 0.1.0
				 *
				 * @throws \Error Always.
				 *
				 * @param DomainEvent ...$events The events.
				 */
				public function publish( DomainEvent ...$events ): void {
					throw new \Error( 'The refund could not be published.' );
				}
			}
		);
		$moved                  = fn(): array => array( array_intersect_key( $this->intentRow( $intent->uuid ), array_flip( array( 'status', 'refunded_minor', 'base_refunded_minor' ) ) ), $this->lineRow( $tee ), $this->refundTableCounts() );
		$before                 = $moved();

		try {
			$this->refund( $order->uuid, array( $tee => 1 ), false, $failing );
			$this->fail( 'A refund whose document could not be written was answered as made.' );
		} catch ( CodedException $kept ) {
			$this->assertSame( PaymentError::Unreconciled, $kept->errorCode() );
			$this->assertInstanceOf( \Error::class, $kept->getPrevious(), 'What failed is the previous exception.' );
		}

		$this->assertSame( 1, $this->refundCalls(), 'The gateway gave the money back.' );
		$this->assertSame( $before, $moved(), 'No intent amount, unit or document records the refund.' );
		$this->assertSame( array( 'stub-re-' . $this->gateway->refunds[0]['key'], '0' ), array_values( $this->refundLedger( $order->id )[0] ), 'The money is on the ledger, applied to nothing.' );
		$this->assertSame( array( '1', 'on_hold' ), array( (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['status'] ), 'The order is flagged and on hold.' );
	}

	/**
	 * Tests that a gateway giving back another amount than was asked leaves the money on the ledger for a person, and records no refund.
	 *
	 * @since 0.1.0
	 */
	public function test_another_amount_than_asked_is_kept_for_a_person(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$overgiving             = $this->refundsOver( $this->db, $this->ids, new OvergivingGateway( new StubGateway() ) );
		$refundedBefore         = $this->intentRow( $intent->uuid )['refunded_minor'];

		try {
			$this->refund( $order->uuid, array( $tee => 1 ), false, $overgiving );
			$this->fail( 'A refund of another amount than asked was recorded.' );
		} catch ( CodedException $kept ) {
			$this->assertSame( PaymentError::Unreconciled, $kept->errorCode() );
		}

		$this->assertSame( array(), $this->refundRows( $order->id ), 'No document states money the refund did not ask for.' );
		$this->assertSame( $refundedBefore, $this->intentRow( $intent->uuid )['refunded_minor'], 'The intent moved nothing.' );
		$this->assertSame( array( 'approved', '0' ), array_values( (array) $this->db->fetchRow( "SELECT result, applied FROM %i WHERE order_id = %d AND operation = 'refund'", $this->table( PaymentTables::TRANSACTIONS ), $order->id ) ), 'The money is on the ledger, applied to nothing.' );
		$this->assertSame( '1', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a refund refuses to run inside a transaction, before any statement, because it asks the gateway.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_inside_a_transaction_is_refused_before_any_statement(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$agent         = $this->agent();

		$log = $this->captureQueries(
			function () use ( $order, $tee, $agent ): void {
				try {
					$this->db->transaction( fn() => $this->refunds->refund( self::request( $order->uuid, array( $tee => 1 ), false ), $agent ) );
					$this->fail( 'A refund ran inside a transaction.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'never inside a transaction', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->matching( '/seocart_/' ), 'plugin statements of a refund refused inside a transaction' );
		$this->assertSame( 0, $this->refundCalls() );
	}

	/**
	 * Tests that a user without the refund capability is refused before any read.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_without_the_capability_is_refused_before_any_read(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$reporter      = $this->userWithRole( 'seocart_reporter' );

		$log = $this->captureQueries(
			function () use ( $order, $tee, $reporter ): void {
				try {
					$this->refunds->refund( self::request( $order->uuid, array( $tee => 1 ), false ), $reporter );
					$this->fail( 'A reporter refunded an order.' );
				} catch ( CodedException $denied ) {
					$this->assertSame( AuthorizationError::Denied, $denied->errorCode() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->matching( '/seocart_/' ), 'plugin statements before the capability check' );
		$this->assertSame( 0, $this->refundCalls() );
	}

	/**
	 * Tests that no statement of a refund names a catalog, customer or pricing table: a refund reads the order's snapshots only.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refund_reads_only_the_order_and_payment_tables(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$log = $this->captureQueries( fn() => $this->refund( $order->uuid, array( $tee => 1 ), true ) );

		$this->assertGreaterThan( 0, $log->matching( '/' . preg_quote( $this->table( RefundTables::REFUNDS ), '/' ) . '/' )->count(), 'The log saw the refund.' );
		$this->assertSame( array(), self::foreignTables( $log->matching( '/seocart_/' )->describe(), $this->table( '' ) ), 'A refund names no table outside the order and payment modules.' );
	}

	/**
	 * Fails unless a refund is refused with an error before any refund is asked of the gateway, and writes no refund row.
	 *
	 * @since 0.1.0
	 *
	 * @param ErrorCode            $expected The error.
	 * @param callable             $refund   Asks for the refund.
	 * @param array<string, mixed> $context  Optional. The error's context, when it is checked. Default null, unchecked.
	 */
	private function assertRefusedBeforeTheGateway( ErrorCode $expected, callable $refund, ?array $context = null ): void {
		$counts = $this->refundTableCounts();
		$calls  = $this->refundCalls();

		try {
			$refund();
			$this->fail( sprintf( 'A refund owed %s was made.', $expected->value ) );
		} catch ( CodedException $refused ) {
			$this->assertSame( $expected, $refused->errorCode() );

			if ( null !== $context ) {
				$this->assertSame( $context, $refused->context() );
			}
		}

		$this->assertSame( $calls, $this->refundCalls(), sprintf( 'The gateway was asked for a refund %s refuses.', $expected->value ) );
		$this->assertSame( $counts, $this->refundTableCounts() );
	}

	/**
	 * Reads an order's refund rows on the ledger: the refund object, and whether the row was applied.
	 *
	 * @since 0.1.0
	 *
	 * @param int $orderId The order.
	 * @return list<array<string, mixed>> The rows, in id order.
	 */
	private function refundLedger( int $orderId ): array {
		return $this->db->fetchAll( "SELECT provider_object_id, applied FROM %i WHERE order_id = %d AND operation = 'refund' ORDER BY id", $this->table( PaymentTables::TRANSACTIONS ), $orderId );
	}

	/**
	 * Lists the plugin tables a log names outside the order and payment modules.
	 *
	 * @since 0.1.0
	 *
	 * @param string $described The log, described.
	 * @param string $prefix    The plugin tables' prefix.
	 * @return list<string> The tables named, each once.
	 */
	private static function foreignTables( string $described, string $prefix ): array {
		preg_match_all( '/' . preg_quote( $prefix, '/' ) . '([a-z_]+)/', $described, $found );

		$ours = array_merge( OrderTables::names(), PaymentTables::moduleNames(), array( 'outbox' ) );

		return array_values( array_unique( array_diff( $found[1], $ours ) ) );
	}

	/**
	 * Builds the fixture order: three tees at 12.34 and two mugs at 5.00, gross, taxed at 20 %, and 4.99 shipping, net, taxed at 20 %.
	 *
	 * @since 0.1.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
