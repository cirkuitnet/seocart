<?php
/**
 * Tests two refunds of one payment asked on two connections at once: the same refund while the first request is with the gateway, and different refunds racing for the intent's lock
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\BarrierRefundRepository;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * Two requests of one payment on two real connections: the first one's claim decides who asks the gateway for a refund, and the second writes nothing.
 *
 * The same refund: connection A is the refund service over wpdb. While the gateway gives A's
 * refund back, after A's claim committed and before A's transaction, the barrier runs B's whole
 * request on a second connection. B finds A's claim, so B asks the gateway what became of the
 * refund. B's gateway has not seen it yet, and says it made no such refund; or it cannot say at all.
 * Either way B is refused `payment.refund_unresolved`, naming the refund, and writes nothing: a
 * not-found is never taken for a decline. Then A's answer arrives and is recorded, once.
 *
 * The same decline, heard twice: a gateway may decline a refund without naming a provider object,
 * so the ledger's unique key cannot tell A's decline from B's; the claim does. B records the
 * decline A heard first, and A's own is taken back.
 *
 * Two different refunds: A's claim takes the intent's lock; the barrier, inside A's claim's
 * transaction, starts B's refund of other units in a process of its own, and returns once the
 * server shows B's claim waiting for the lock. A's claim commits; B's then reads, under the lock,
 * the claim A left open, and B is refused `payment.refund_unresolved`, naming it, having written
 * nothing. Barriers, never pauses.
 *
 * Planted violations, each shown red and removed:
 *
 * - in RefundService::askedBefore(), ask the gateway for the refund again instead of asking what
 *   became of it: B's gateway gives the money back too;
 * - in RefundService::askWhatBecameOf(), record a not-found as a decline: B declines the refund
 *   A's gateway is making, and A's money is left for a person;
 * - in RefundService::plan() and RefundService::claim(), drop the wait for another refund's open
 *   claim: B claims beside A, and the gateway is asked for both;
 * - in RefundService::endWithout(), go on when a decline's claim had ended: the ledger holds two
 *   declines of one claim.
 *
 * @since 0.1.0
 *
 * @group concurrency
 */
final class RefundClaimConcurrencyTest extends RefundTestCase {

	/**
	 * How B's request ended, once the barrier ran it: its refusal, or `recorded`.
	 *
	 * @since 0.1.0
	 *
	 * @var list<CodedException|string>
	 */
	private array $outcomesOfB = array();

	/**
	 * B's refund, running in a process of its own once the barrier started it.
	 *
	 * @since 0.1.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probeB = null;

	/**
	 * Tests that the same refund asked on a second connection while the first request is with the gateway is refused, writes nothing, and leaves the first to be recorded once.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider gatewaysOfB
	 *
	 * @param bool $knows Whether B's gateway can say what became of a refund: when it can, it has not seen this one yet.
	 */
	public function test_the_same_refund_asked_while_the_first_is_with_the_gateway_is_refused( bool $knows ): void {
		list( $order ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 2, 'standard' ) ), null ) );
		list( $gift )  = $this->lineUuids( $order->id );

		list( , $connectionB ) = $this->secondOrders();

		$gatewayB          = new RememberingGateway( new StubGateway() );
		$gatewayB->knows   = $knows;
		$b                 = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), $gatewayB );
		$this->outcomesOfB = array();

		// The barrier: while the gateway gives A's refund back, B asks for the same refund and ends.
		$this->gateway->during(
			function () use ( $order, $gift, $b ): void {
				if ( array() === $this->outcomesOfB ) {
					$this->outcomesOfB[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $gift => 1 ), false, $b ) );
				}
			}
		);

		$refund  = $this->refund( $order->uuid, array( $gift => 1 ) );
		$refused = $this->outcomesOfB[0] ?? null;

		$this->assertInstanceOf( CodedException::class, $refused, 'B was answered: ' . ( is_string( $refused ) ? $refused : 'nothing' ) );
		$this->assertSame( PaymentError::RefundUnresolved, $refused->errorCode() );
		$this->assertSame( array( 'refund_uuid' => $refund->uuid ), $refused->context(), 'B\'s refusal names the refund.' );
		$this->assertSame( 0, $gatewayB->refundsMade(), 'B never asked the gateway for the refund.' );
		$this->assertSame( 1, $this->refundCalls(), 'A asked the gateway for it, once.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'One document, A\'s.' );
		$this->assertSame( array( array( 'stub-re-' . $refund->uuid, 'approved', '1' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['result'], (string) $row['applied'] ), $this->refundLedgerRows( $order->id ) ), 'One ledger row, applied.' );
		$this->assertSame( array( 'recorded' ), array_column( $this->claimRows( $order->id ), 'state' ) );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
	}

	/**
	 * Tests that a claim of another refund, racing A's for the intent's lock, waits for A's claim to commit and is then refused on it, having written nothing.
	 *
	 * A refunds a gift, B, another order agent, a mug. A's claim takes the intent's lock; inside A's claim's transaction,
	 * the barrier starts B's refund in a process of its own and returns once the server shows B's
	 * locking read waiting for the lock. B's reads came before A's claim, so the early check let B
	 * through to its own claim. A's claim then commits, and A's gateway call waits for B to end:
	 * under the lock, B reads A's claim open and is refused `payment.refund_unresolved`, naming it.
	 * Then A's refund is recorded.
	 *
	 * @since 0.1.0
	 */
	public function test_a_claim_racing_another_for_the_intent_waits_and_is_refused_on_its_open_claim(): void {
		list( $order, $intent ) = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 2, 'standard' ), RefundOrders::line( 'mug', '5.00', 2, 'standard', variantId: 502 ) ), null ) );
		list( $gift, $mug )     = $this->lineUuids( $order->id );
		$claims                 = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$a                      = $this->refundsOver( $this->db, $this->ids, $this->gateway, null, $claims );
		$lock                   = $this->rawRefund( MysqlRefundRepository::LOCK_FOR_CLAIM, (int) $this->intentRow( $intent->uuid )['id'] );
		$other                  = $this->userWithRole();
		$this->probeB           = null;

		// The barrier: A holds the intent's lock inside its claim's transaction; B, another order agent, in a process of
		// its own, waits for it. Another agent, whose own lock row for the cap of a day nothing holds.
		$claims->afterLock(
			function () use ( $order, $mug, $lock, $other ): void {
				if ( null === $this->probeB ) {
					$this->probeB = $this->startRefundProbe( $order->uuid, array( $mug => 1 ), null, $other );

					$this->awaitProbeWaiting( $this->probeB, $lock, 'statistics' );
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

		$refund = $this->refund( $order->uuid, array( $gift => 1 ), false, $a );

		$this->gateway->during( static function (): void {} );
		$this->assertNotNull( $this->probeB, 'B never raced A.' );

		$report = $this->probeB->finish();

		$this->assertSame( PaymentError::RefundUnresolved->value, $report['refused'] ?? null, 'B was answered: ' . (string) wp_json_encode( $report ) );
		$this->assertSame( array( 'refund_uuid' => $refund->uuid ), $report['context'] ?? null, 'B\'s refusal names A\'s open claim.' );
		$this->assertSame( array( array( $refund->uuid, 'recorded' ) ), array_map( static fn( array $claim ): array => array( $claim['uuid'], $claim['state'] ), $this->claimRows( $order->id ) ), 'B wrote no claim; A\'s is recorded.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( 1, $this->refundCalls(), 'Only A asked the gateway for a refund.' );
	}

	/**
	 * Tests that a decline naming no provider object, heard by A from the refund and by B from asking what became of it, is recorded once, and that both are answered declined.
	 *
	 * The gateway declines A's refund. After its answer and before A's transaction, the barrier
	 * runs B's request for the same refund on a second connection: B finds A's claim, asks the
	 * gateway what became of the refund, hears the same decline, records it and ends the claim. A's
	 * decline then finds the claim ended: the savepoint takes A's ledger row back, and A is answered
	 * from the claim. Were A's row kept, the claim's one decline would be counted twice, and so
	 * would move every later refund's uuid by two.
	 *
	 * @since 0.1.0
	 */
	public function test_a_decline_naming_no_provider_object_heard_twice_is_recorded_once(): void {
		list( $order )             = $this->placePaid( RefundOrders::priced( array( RefundOrders::line( 'gift', '10.00', 2, 'standard' ) ), null ), StubGateway::REFUND_DECLINE );
		list( $gift )              = $this->lineUuids( $order->id );
		list( , $connectionB )     = $this->secondOrders();
		$provider                  = new RememberingGateway( new StubGateway() );
		$provider->declinesUnnamed = true;
		$transactions              = new BarrierTransactions( $this->db );
		$a                         = $this->refundsOver( $this->db, $this->ids, $provider, tx: $transactions );
		$b                         = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), $provider );
		$this->outcomesOfB         = array();

		// The barrier: A's claim is committed and the gateway declined A's refund; before A's transaction, B asks for the same refund.
		$transactions->beforeTransaction(
			function () use ( $order, $gift, $b ): void {
				if ( array() === $this->outcomesOfB && array() !== $this->claimRows( $order->id ) ) {
					$this->outcomesOfB[] = $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $gift => 1 ), false, $b ) );
				}
			}
		);

		$outcomes = array( $this->outcomeOf( fn() => $this->refund( $order->uuid, array( $gift => 1 ), false, $a ) ), $this->outcomesOfB[0] ?? 'never ran' );

		$this->assertSame(
			array( PaymentError::RefundDeclined->value, PaymentError::RefundDeclined->value ),
			array_map( static fn( CodedException|string $outcome ): string => $outcome instanceof CodedException ? $outcome->errorCode()->value : $outcome, $outcomes ),
			'A and B are both answered declined.'
		);
		$this->assertSame( 1, $provider->refundsMade(), 'The gateway was asked for the refund once.' );

		$declines = $this->refundLedgerRows( $order->id );

		$this->assertSame(
			array( array( 'declined', StubGateway::REFUND_DECLINED, '' ) ),
			array_map( static fn( array $row ): array => array( (string) $row['result'], (string) $row['error_code'], (string) $row['provider_object_id'] ), $declines ),
			'One decline on the ledger for the one claim, naming no provider object.'
		);
		$this->assertSame(
			array( array( 'declined', (string) $declines[0]['id'] ) ),
			array_map( static fn( array $claim ): array => array( $claim['state'], $claim['transaction_id'] ), $this->claimRows( $order->id ) ),
			'The claim ended declined, naming that row.'
		);
		$this->assertClaimsNameOnlyTheirOwnRows( $order->id );
	}

	/**
	 * Runs a refund and says how it ended: `recorded`, or the refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param callable $refund Asks for the refund.
	 * @return CodedException|string The refusal, or `recorded`.
	 */
	private function outcomeOf( callable $refund ): CodedException|string {
		try {
			$refund();

			return 'recorded';
		} catch ( CodedException $refused ) {
			return $refused;
		}
	}

	/**
	 * Returns B's two gateways: one that has not seen the refund yet, and one that cannot say.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{0: bool}> The gateways.
	 */
	public static function gatewaysOfB(): array {
		return array(
			'a gateway that has not seen the refund yet' => array( true ),
			'a gateway that cannot say'                  => array( false ),
		);
	}
}
