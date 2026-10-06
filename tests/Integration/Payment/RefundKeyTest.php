<?php
/**
 * Tests the idempotency key a refund is asked with: the same key names the same refund for good
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\AnswerLosingGateway;
use SEOCart\Tests\Support\Doubles\BarrierRefundRepository;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;

/**
 * A refund asked with an idempotency key is named by it for good.
 *
 * - Asked again with the same key and the same request after it was recorded, as when its answer
 *   was lost, it is answered with the same document from two reads: no plan, nothing asked of the
 *   gateway.
 * - Asked with a different key after it was recorded, the same request is a new refund.
 * - The same key with another request is refused `payment.refund_key_reused` from one read, before
 *   any read of the order, and nothing is written.
 * - The claim keeps the whole request: the base share, the shipping asked, the reason, the note,
 *   the key's hash, the request's fingerprint, and each line asked.
 * - The same key and request sent again on another connection while the first is with the gateway
 *   are one refund: the second finds the first's claim by the key, asks the gateway what became of
 *   the refund and records it; the first's answer is then the ledger's duplicate, and both name it.
 * - A request whose key another request, on another connection, claimed and recorded meanwhile,
 *   between this one's key lookup and its plan, or between its plan and its claim, is answered with
 *   that refund's document; the gateway gives the money back once. So it is when that refund took
 *   the line's last unit, which this request's plan then refuses, when it used the agent's cap of
 *   one order, which this request's check before its claim then refuses, and when it used the
 *   agent's cap of a day, which this request's claim then refuses: whatever refuses a request with
 *   a key, the key's claim, once ended, is its answer.
 * - A request with another key, sent on another connection while the first is with the gateway for
 *   the same units, is refused `payment.refund_retry`, and does not ask the gateway what became of
 *   the first's refund: a claim's refund is answered only to the key that made it. Asked again once
 *   the first was recorded, it is a refund of its own, and each key names exactly one refund.
 * - A claim found open by its key that is not the claim the same request finds by its identity is
 *   a programming error, naming both.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::refund(), skip the key lookup before plan(): the same document is still the
 *   answer, read by the key after the claim's duplicate key, but after the plan's reads and the
 *   claim's transaction, and the count of two fails;
 * - in RefundService::claimNamedBy(), skip the fingerprint comparison: another request sent with
 *   the key is answered with the first one's document;
 * - in RefundService::askedBefore(), ask the gateway for the refund again instead of asking what
 *   became of it: the same key sent while the first is with the gateway is a second refund call;
 * - in RefundService::askedBefore(), find the claim by the refund's uuid only: a request whose key
 *   another request recorded meanwhile is refused `payment.refund_retry`, and another key meeting
 *   the first with the gateway is answered with the first's refund;
 * - in RefundClaimTables::claims(), make `key_hash` a plain key: the same request, recorded
 *   meanwhile, is claimed again under a new identity, and the gateway gives the money back twice;
 * - in RefundService::answerRefusedByKey(), raise the refusal as it came: a request whose key
 *   another request recorded between its plan and its claim is refused `payment.refund_retry`;
 * - in RefundService::answerRefusedByKey(), read the key again only after `payment.refund_retry`
 *   and `payment.refund_unresolved`: a request whose key took the line's last unit after its lookup
 *   is refused `payment.refund_line_exhausted`, and one whose key used the agent's cap of a day is
 *   refused `payment.refund_cap_exceeded`;
 * - in RefundService::refund(), check the cap of one order before the `try` whose refusals are
 *   answered by the key: a request whose key used the agent's cap of one order after its lookup is
 *   refused `payment.refund_cap_exceeded`;
 * - in RefundService::askedBefore(), answer a request with a key from the claim the refund's uuid
 *   names when its key names none: another key meeting the first with the gateway is answered with
 *   the first's refund, and its retry is then a second refund, which it names too;
 * - in RefundService::plan(), drop the check of the claim the key found open: the request is
 *   refused `payment.refund_unresolved`, naming the edited claim, and the edit goes unnoticed.
 *
 * @since 0.2.0
 */
final class RefundKeyTest extends RefundTestCase {

	/**
	 * Tests that the same key and the same request, after the refund was recorded, are answered with the same document from the key's claim and the document, without a plan or the gateway.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_key_after_recording_answers_the_same_document_from_two_reads(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$first         = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );

		$this->gateway->calls = array();

		$again = null;
		$log   = $this->captureQueries(
			function () use ( $order, $tee, &$again ): void {
				$again = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );
			}
		)->matching( self::STATEMENTS );

		$this->assertInstanceOf( Refund::class, $again );
		$this->assertSame( array( $first->id, $first->uuid, $first->transactionId ), array( $again->id, $again->uuid, $again->transactionId ), 'The same refund.' );
		$this->assertQueryCount( 2, $log, 'a refund asked again by its key after it was recorded' );
		$this->assertSame( array(), $this->gateway->calls, 'Nothing was asked of the gateway.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertCount( 1, $this->claimRows( $order->id ) );
		$this->assertSame( 1, (int) $this->lineRow( $tee )['refunded_quantity'], 'The unit was refunded once.' );
	}

	/**
	 * Tests that the same request with a different key, after the first was recorded, is a new refund of one more unit.
	 *
	 * @since 0.2.0
	 */
	public function test_a_different_key_after_recording_is_a_new_refund(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$first  = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );
		$second = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-2' );

		$this->assertNotSame( $first->uuid, $second->uuid );
		$this->assertSame( 2, $this->refundCalls() );
		$this->assertCount( 2, $this->refundRows( $order->id ) );
		$this->assertCount( 2, $this->claimRows( $order->id ) );
		$this->assertSame( 2, (int) $this->lineRow( $tee )['refunded_quantity'] );
	}

	/**
	 * Tests that the same key with another request, more units or another note, is refused from the key's one read, before any read of the order, and writes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_key_with_another_request_is_refused_before_any_read_of_the_order(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );

		$this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );

		$this->gateway->calls = array();
		$written              = array( $this->claimRows( $order->id ), $this->refundRows( $order->id ) );
		$others               = array(
			'more units' => array( 2, null ),
			'a note'     => array( 1, 'Another request.' ),
		);

		foreach ( $others as $what => list( $units, $note ) ) {
			$refused = null;
			$log     = $this->captureQueries(
				function () use ( $order, $tee, $units, $note, &$refused ): void {
					try {
						$this->refundWithKey( $order->uuid, array( $tee => $units ), 'attempt-1', $note );
					} catch ( CodedException $error ) {
						$refused = $error;
					}
				}
			)->matching( self::STATEMENTS );

			$this->assertInstanceOf( CodedException::class, $refused, 'The key was taken with ' . $what . '.' );
			$this->assertSame( PaymentError::RefundKeyReused, $refused->errorCode(), $what );
			$this->assertQueryCount( 1, $log, 'the key reused with ' . $what );
		}

		$this->assertSame( array(), $this->gateway->calls, 'Nothing was asked of the gateway.' );
		$this->assertSame( $written, array( $this->claimRows( $order->id ), $this->refundRows( $order->id ) ), 'Nothing was written.' );
	}

	/**
	 * Tests that the claim keeps the whole request it was made for, with the key and the fingerprint, and each line asked; and that a claim made without a key keeps neither.
	 *
	 * @since 0.2.0
	 */
	public function test_the_claim_keeps_the_request_it_was_made_for(): void {
		list( $order )     = $this->placePaid( self::order() );
		list( $tee, $mug ) = $this->lineUuids( $order->id );
		$request           = new RefundRequest( $order->uuid, array( new RefundLineRequest( $tee, 2 ), new RefundLineRequest( $mug, 1, true ) ), true, self::REASON, 'The box arrived crushed.' );
		$key               = $this->requestKey( $request, 'attempt-1' );
		$refund            = $this->refunds->refund( $request, $this->agent(), $key );

		$this->assertSame(
			array(
				'base_amount_minor'   => (string) $refund->baseTotal->minorUnits(),
				'base_currency'       => $refund->baseTotal->currency()->code(),
				'shipping'            => '1',
				'reason_code'         => self::REASON,
				'note'                => 'The box arrived crushed.',
				'key_hash'            => $key->keyHash,
				'request_fingerprint' => $key->fingerprint,
			),
			$this->claimRequest( $refund->uuid )
		);

		$asked = array(
			$tee => array( '2', '0' ),
			$mug => array( '1', '1' ),
		);

		ksort( $asked );

		$this->assertSame( $asked, $this->claimLines( $refund->uuid ), 'Each line asked, its units and whether they go back into stock.' );

		$plain = $this->claimRequest( $this->refund( $order->uuid, array( $tee => 1 ) )->uuid );

		$this->assertSame( array( null, null, null ), array( $plain['note'], $plain['key_hash'], $plain['request_fingerprint'] ), 'A claim made without a key keeps no key, no fingerprint and no note.' );
	}

	/**
	 * Tests that the same key and request, sent on another connection while the first is with the gateway, are one refund, which both answers name.
	 *
	 * @since 0.2.0
	 */
	public function test_the_same_key_sent_while_the_first_is_with_the_gateway_is_one_refund(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$other         = $this->otherConnection();
		$theirs        = null;

		// While the gateway gives A's refund back, B sends the same request with the same key, whole; once.
		$this->gateway->during(
			function () use ( $order, $tee, $other, &$theirs ): void {
				if ( null === $theirs ) {
					$theirs = false;
					$theirs = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $other );
				}
			}
		);

		$ours = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );

		$this->gateway->during( static function (): void {} );

		$this->assertInstanceOf( Refund::class, $theirs, 'B ran while A was with the gateway.' );
		$this->assertSame( $ours->uuid, $theirs->uuid, 'Both answers name the one refund.' );
		$this->assertSame( 1, $this->refundQueries(), 'B asked the gateway what became of the refund.' );
		$this->assertOneRefund( $order->id, $tee );
	}

	/**
	 * Tests that a request whose key another request, on another connection, claims and records between this one's key lookup and its plan is answered with that refund: this request's own claim meets the key's unique key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_whose_key_was_recorded_after_its_lookup_is_answered_with_that_refund(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$other         = $this->otherConnection();
		$barrier       = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$ours          = $this->refundsOver( $this->db, $this->ids, $this->gateway, refunds: $barrier );
		$theirs        = null;

		$barrier->onceAfterKeyLookup(
			function () use ( $order, $tee, $other, &$theirs ): void {
				$theirs = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $other );
			}
		);

		$answer = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $ours );

		$this->assertInstanceOf( Refund::class, $theirs, 'The other request ran at the barrier.' );
		$this->assertSame( $theirs->uuid, $answer->uuid, 'Both answers name the one refund.' );
		$this->assertOneRefund( $order->id, $tee );
	}

	/**
	 * Tests that a request whose key another request, on another connection, claims and records between this one's plan and its claim is answered with that refund, not refused `payment.refund_retry`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_whose_key_was_recorded_before_its_claim_is_answered_with_that_refund(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$other         = $this->otherConnection();
		$tx            = new BarrierTransactions( $this->db );
		$ours          = $this->refundsOver( $this->db, $this->ids, $this->gateway, tx: $tx );
		$theirs        = null;

		// Just before this request's first transaction, which is its claim's; the barrier runs once.
		$tx->beforeTransaction(
			function () use ( $tx, $order, $tee, $other, &$theirs ): void {
				$tx->beforeTransaction( static function (): void {} );

				$theirs = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $other );
			}
		);

		$answer = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $ours );

		$this->assertInstanceOf( Refund::class, $theirs, 'The other request ran at the barrier.' );
		$this->assertSame( $theirs->uuid, $answer->uuid, 'Both answers name the one refund.' );
		$this->assertOneRefund( $order->id, $tee );
	}

	/**
	 * Tests that a request whose key another request, on another connection, claims and records between this one's key lookup and its plan, taking the line's last unit, is answered with that refund, not refused `payment.refund_line_exhausted`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_whose_key_took_the_last_unit_after_its_lookup_is_answered_with_that_refund(): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 1, 'standard' ) ) ) );
		list( $tee )   = $this->lineUuids( $order->id );

		list( $theirs, $answer ) = $this->recordedAtTheKeyLookup( $order->uuid, $tee );

		$this->assertInstanceOf( Refund::class, $theirs, 'The other request ran at the barrier.' );
		$this->assertSame( $theirs->uuid, $answer->uuid, 'Both answers name the one refund.' );
		$this->assertOneRefund( $order->id, $tee );
	}

	/**
	 * Tests that a capped agent's request whose key another request, on another connection, claims and records between this one's key lookup and its plan is answered with that refund, not refused `payment.refund_cap_exceeded` by the cap of a day under the claim's locks.
	 *
	 * Each television is 657.68 of the base currency, and the agent may give back 1000.00 a day: the
	 * key's refund leaves this request's claim past the cap.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_whose_key_used_the_cap_of_a_day_after_its_lookup_is_answered_with_that_refund(): void {
		$this->capOrderAgents( '', '1000.00' );

		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tv', '600.00', 2, 'untaxed' ) ), null ) );
		list( $tv )    = $this->lineUuids( $order->id );

		list( $theirs, $answer ) = $this->recordedAtTheKeyLookup( $order->uuid, $tv );

		$this->assertInstanceOf( Refund::class, $theirs, 'The other request ran at the barrier.' );
		$this->assertSame( $theirs->uuid, $answer->uuid, 'Both answers name the one refund.' );
		$this->assertOneRefund( $order->id, $tv );
	}

	/**
	 * Tests that a capped agent's request whose key another request, on another connection, claims and records between this one's key lookup and its plan is answered with that refund, not refused `payment.refund_cap_exceeded` by the cap of one order checked before its claim.
	 *
	 * Each television is 164.42 of the base currency, and the agent may give back 250.00 of one
	 * order: the key's refund leaves this request past the cap before its claim.
	 *
	 * @since 0.2.0
	 */
	public function test_a_request_whose_key_used_the_cap_of_an_order_after_its_lookup_is_answered_with_that_refund(): void {
		$this->capOrderAgents( '250.00', '' );

		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'tv', '150.00', 2, 'untaxed' ) ), null ) );
		list( $tv )    = $this->lineUuids( $order->id );

		list( $theirs, $answer ) = $this->recordedAtTheKeyLookup( $order->uuid, $tv );

		$this->assertInstanceOf( Refund::class, $theirs, 'The other request ran at the barrier.' );
		$this->assertSame( $theirs->uuid, $answer->uuid, 'Both answers name the one refund.' );
		$this->assertOneRefund( $order->id, $tv );
	}

	/**
	 * Tests that a request with another key, sent on another connection while the first is with the gateway for the same unit, is refused `payment.refund_retry`, never answered with the first's refund; that asked again once the first was recorded it is a refund of its own; and that each key then names exactly one refund.
	 *
	 * @since 0.2.0
	 */
	public function test_another_key_meeting_the_first_with_the_gateway_is_refused_then_a_refund_of_its_own(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$other         = $this->otherConnection();
		$ran           = false;
		$refused       = null;

		// While the gateway gives the first key's refund back, the second key asks for the same unit; once.
		$this->gateway->during(
			function () use ( $order, $tee, $other, &$ran, &$refused ): void {
				if ( $ran ) {
					return;
				}

				$ran = true;

				try {
					$this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-2', null, $other );
				} catch ( CodedException $error ) {
					$refused = $error;
				}
			}
		);

		$first = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );

		$this->gateway->during( static function (): void {} );

		$this->assertTrue( $ran, 'The second key ran while the first was with the gateway.' );
		$this->assertSame( PaymentError::RefundRetry, $refused?->errorCode(), 'The second key is refused, not answered with the first key\'s refund.' );
		$this->assertSame( 0, $this->refundQueries(), 'The second key did not ask the gateway what became of the first key\'s refund.' );

		$second = $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-2' );

		$this->assertNotSame( $first->uuid, $second->uuid, 'Asked again, the second key is a refund of its own.' );
		$this->assertSame( $first->uuid, $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' )->uuid, 'The first key names its refund.' );
		$this->assertSame( $second->uuid, $this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-2' )->uuid, 'The second key names its refund.' );
		$this->assertSame( 2, $this->refundCalls(), 'The gateway gave the money back once for each key.' );
		$this->assertCount( 2, $this->refundRows( $order->id ) );
		$this->assertCount( 2, $this->claimRows( $order->id ) );
		$this->assertSame( 2, (int) $this->lineRow( $tee )['refunded_quantity'] );
	}

	/**
	 * Tests that a claim found open by its key, which is not the claim the same request finds by its identity, as when its uuid was edited outside the service, is a programming error naming both.
	 *
	 * @since 0.2.0
	 */
	public function test_a_claim_found_open_by_its_key_that_its_identity_does_not_find_is_named(): void {
		list( $order ) = $this->placePaid( self::order() );
		list( $tee )   = $this->lineUuids( $order->id );
		$losing        = $this->refundsOver( $this->db, $this->ids, new AnswerLosingGateway( $this->gateway ) );

		try {
			$this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1', null, $losing );
			$this->fail( 'The answer of the gateway was not lost.' );
		} catch ( GatewayUnavailable $lost ) {
			unset( $lost );
		}

		$claimed = (string) $this->claimRows( $order->id )[0]['uuid'];
		$edited  = '0199a0b1-c2d3-7e4f-8a5b-6c7d8e9f0a1b';

		$this->db->execute( 'UPDATE %i SET uuid = %s WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $edited, $claimed );

		try {
			$this->refundWithKey( $order->uuid, array( $tee => 1 ), 'attempt-1' );
			$this->fail( 'A claim edited outside the service went unnoticed.' );
		} catch ( \LogicException $named ) {
			$this->assertStringContainsString( $edited, $named->getMessage() );
			$this->assertStringContainsString( $claimed, $named->getMessage() );
		}
	}

	/**
	 * Builds the refund service over a connection of its own, calling the test's gateway, so its calls are counted with this connection's.
	 *
	 * @since 0.2.0
	 *
	 * @return RefundService The service.
	 */
	private function otherConnection(): RefundService {
		list( , $b ) = $this->secondOrders();

		return $this->refundsOver( $b, new SequentialIdGenerator( 800000 ), $this->gateway );
	}

	/**
	 * Refunds one unit of a line with a key, while another request with the same key, on another connection, claims and records the same unit just after this one's key lookup.
	 *
	 * @since 0.2.0
	 *
	 * @param string $orderUuid The order.
	 * @param string $lineUuid  The line.
	 * @return array{0: Refund|null, 1: Refund} The other request's refund, and this one's answer.
	 */
	private function recordedAtTheKeyLookup( string $orderUuid, string $lineUuid ): array {
		$other   = $this->otherConnection();
		$barrier = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$ours    = $this->refundsOver( $this->db, $this->ids, $this->gateway, refunds: $barrier );
		$theirs  = null;

		$barrier->onceAfterKeyLookup(
			function () use ( $orderUuid, $lineUuid, $other, &$theirs ): void {
				$theirs = $this->refundWithKey( $orderUuid, array( $lineUuid => 1 ), 'attempt-1', null, $other );
			}
		);

		$answer = $this->refundWithKey( $orderUuid, array( $lineUuid => 1 ), 'attempt-1', null, $ours );

		return array( $theirs, $answer );
	}

	/**
	 * Asserts that an order has one refund, of one unit of a line: one gateway refund, one document, and one claim, which carries a key.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $orderId  The order.
	 * @param string $lineUuid The line.
	 */
	private function assertOneRefund( int $orderId, string $lineUuid ): void {
		$this->assertSame( 1, $this->refundCalls(), 'The gateway gave the money back once.' );
		$this->assertCount( 1, $this->refundRows( $orderId ) );
		$this->assertCount( 1, $this->claimRows( $orderId ) );
		$this->assertSame( 1, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE order_id = %d AND key_hash IS NOT NULL', $this->table( RefundClaimTables::CLAIMS ), $orderId ), 'The one claim carries the key.' );
		$this->assertSame( 1, (int) $this->lineRow( $lineUuid )['refunded_quantity'] );
	}

	/**
	 * Reads what a claim keeps of its request.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return array<string, mixed> The columns.
	 */
	private function claimRequest( string $uuid ): array {
		return (array) $this->db->fetchRow( 'SELECT base_amount_minor, base_currency, shipping, reason_code, note, key_hash, request_fingerprint FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
	}

	/**
	 * Reads the lines a claim asked for: the units and the restock flag, by line uuid, sorted.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The refund's uuid.
	 * @return array<string, list<string>> The units and the flag, by line uuid.
	 */
	private function claimLines( string $uuid ): array {
		$lines = array();
		$rows  = $this->db->fetchAll(
			'SELECT line.line_uuid, line.quantity, line.restock FROM %i line JOIN %i claim ON claim.id = line.claim_id WHERE claim.uuid = %s ORDER BY line.line_uuid',
			$this->table( RefundClaimTables::CLAIM_LINES ),
			$this->table( RefundClaimTables::CLAIMS ),
			$uuid
		);

		foreach ( $rows as $row ) {
			$lines[ (string) $row['line_uuid'] ] = array( (string) $row['quantity'], (string) $row['restock'] );
		}

		return $lines;
	}

	/**
	 * Returns an order of two lines, three tees and two mugs, with shipping.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The document.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ) );
	}
}
