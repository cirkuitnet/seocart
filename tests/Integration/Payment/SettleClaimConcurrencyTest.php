<?php
/**
 * Tests a settlement of a refund claim racing what else ends a claim, on two connections: the first request's late answer, and a second settlement
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Application\SettledClaim;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\BarrierRefundRepository;
use SEOCart\Tests\Support\Doubles\BarrierTransactions;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * Every ending of a claim takes the intent's lock and ends it only while it is `claimed`, so one ending wins; a settlement that loses is told how the claim ended, and the money a late answer brings is left for a person.
 *
 * Two real connections and barriers, never a pause:
 *
 * - the first request's gateway call is still out when B settles the claim on a person's word
 *   that no refund was made; then A's approval lands, finds the claim ended, and is kept on the
 *   ledger applied to nothing, the order flagged and parked;
 * - A's settlement holds the intent's lock when B's, a process of its own, asks for it; B waits,
 *   and then finds the claim A ended;
 * - B's settlement runs whole between A's reads and A's transaction; A's lock then finds the
 *   claim ended.
 *
 * A provider's delivery that ends the same claim takes the same lock and the same conditional
 * update; its race with a settlement is proved with the delivery's own tests.
 *
 * Planted violations, each shown red and removed:
 *
 * - in MysqlRefundRepository::SETTLE_CLAIM, drop `AND state = 'claimed'`: A's late approval
 *   records the refund over B's settlement;
 * - in RefundService::settleLocked(), drop the check that the claim is still open under the lock:
 *   the losing settlement is not told the claim ended (in the second race it fails as a
 *   programming error, its statement colliding with the winner's under NOTE_SETTLEMENT).
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class SettleClaimConcurrencyTest extends RefundTestCase {

	/**
	 * Why the person says so, in every statement of the test.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'Checked with the provider.';

	/**
	 * B's settlement, once a barrier ran it.
	 *
	 * @since 0.2.0
	 *
	 * @var SettledClaim|null
	 */
	private ?SettledClaim $settledByB = null;

	/**
	 * B's settlement, running in a process of its own once the barrier started it.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probeB = null;

	/**
	 * Tests that B's settlement, made while A's gateway call is still out, wins, and that A's approval landing after it is kept for a person: the ledger row applied to nothing, the order flagged and parked, A answered `payment.unreconciled`.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_while_the_first_request_is_out_wins_and_the_late_answer_is_left_for_a_person(): void {
		list( $order )         = $this->placePaid( self::order() );
		list( $tee )           = $this->lineUuids( $order->id );
		list( , $connectionB ) = $this->secondOrders();
		$manager               = $this->manager();
		$b                     = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), new RememberingGateway( new StubGateway() ) );
		$this->settledByB      = null;

		// The barrier: while the gateway gives A's refund back, B settles the claim on the word that no refund was made.
		$this->gateway->during(
			function () use ( $order, $b, $manager ): void {
				if ( null === $this->settledByB ) {
					$this->settledByB = $b->settleClaim( (string) $this->claimRows( $order->id )[0]['uuid'], new ClaimStatement( false, self::NOTE ), $manager );
				}
			}
		);

		try {
			$this->refund( $order->uuid, array( $tee => 1 ) );
			$this->fail( 'A\'s late approval was recorded over B\'s settlement.' );
		} catch ( CodedException $late ) {
			$this->assertSame( PaymentError::Unreconciled, $late->errorCode(), 'A is told its money was left for a person.' );
		}

		$claim = $this->claimRows( $order->id )[0];

		$this->assertInstanceOf( SettledClaim::class, $this->settledByB );
		$this->assertSame( array( 'declined', SettledClaim::BY_STATEMENT, SettledClaim::NOT_FOUND ), array( $this->settledByB->state->value, $this->settledByB->decidedBy, $this->settledByB->gatewayReading ) );
		$this->assertSame( array( 'declined', null ), array( $claim['state'], $claim['transaction_id'] ), 'B\'s settlement is how the claim ended.' );
		$this->assertSame( array( array( 'stub-re-' . $claim['uuid'], '0' ) ), array_map( static fn( array $row ): array => array( (string) $row['provider_object_id'], (string) $row['applied'] ), $this->refundLedgerRows( $order->id ) ), 'A\'s money is on the ledger, applied to nothing.' );
		$this->assertSame( array(), $this->refundRows( $order->id ), 'No document.' );
		$this->assertSame( array( '1', 'on_hold' ), array( (string) $this->orderRow( $order->id )['has_unreconciled_money'], (string) $this->orderRow( $order->id )['status'] ), 'The order is flagged and parked for a person.' );
	}

	/**
	 * Tests that a settlement asking for the intent's lock while another settlement holds it waits, and is then told how the claim ended, having written nothing.
	 *
	 * A settles the claim on the word that no refund was made. Inside A's transaction, just after A
	 * took the intent's lock, the barrier starts B's settlement in a process of its own and returns
	 * once the server shows B's lock waiting. A commits; B then finds the claim A ended.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_waiting_for_another_on_the_intents_lock_is_told_how_the_claim_ended(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$provider               = new RememberingGateway( new StubGateway() );
		$claims                 = new BarrierRefundRepository( new MysqlRefundRepository( $this->db ) );
		$a                      = $this->refundsOver( $this->db, $this->ids, $provider, null, $claims );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $provider, $a );
		$lock                   = $this->rawRefund( MysqlRefundRepository::LOCK_FOR_CLAIM, MysqlRefundRepository::NEVER_RECONCILED, (int) $this->intentRow( $intent->uuid )['id'] );
		$manager                = $this->manager();
		$this->probeB           = null;

		// The barrier: A holds the intent's lock inside its settlement's transaction; B, in a process of its own, waits for it.
		$claims->afterLock(
			function () use ( $uuid, $manager, $lock ): void {
				if ( null === $this->probeB ) {
					$this->probeB = $this->startSettleProbe( $uuid, new ClaimStatement( true, self::NOTE, 'manual-re-1', 1234 ), $manager );

					$this->awaitProbeWaiting( $this->probeB, $lock, 'statistics' );
				}
			}
		);

		$settled = $a->settleClaim( $uuid, new ClaimStatement( false, self::NOTE ), $manager );

		$this->assertNotNull( $this->probeB, 'B never raced A.' );

		$report = $this->probeB->finish();

		$this->assertSame( 'declined', $settled->state->value );
		$this->assertSame( array( PaymentError::RefundClaimEnded->value, array( 'state' => 'declined' ) ), array( $report['refused'] ?? null, $report['context'] ?? null ), 'B is told how A ended the claim: ' . wp_json_encode( $report ) );
		$this->assertSettledOnceBy( $order->id, $uuid, ClaimStatement::NOT_REFUNDED );
	}

	/**
	 * Tests that a settlement whose transaction comes after another settlement ran whole is told how the claim ended, having written nothing, though it states a refund was made.
	 *
	 * The barrier runs just before A's transaction, once A asked the gateway: B, on a second
	 * connection, settles the claim on the word that no refund was made, whole. A's lock then finds
	 * the claim ended.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_after_another_ran_whole_is_told_how_the_claim_ended(): void {
		list( $order )         = $this->placePaid( self::order() );
		list( $tee )           = $this->lineUuids( $order->id );
		$provider              = new RememberingGateway( new StubGateway() );
		$transactions          = new BarrierTransactions( $this->db );
		$a                     = $this->refundsOver( $this->db, $this->ids, $provider, tx: $transactions );
		$uuid                  = $this->openClaim( $order->uuid, $tee, $provider, $a );
		list( , $connectionB ) = $this->secondOrders();
		$b                     = $this->refundsOver( $connectionB, new SequentialIdGenerator( 810000 ), new RememberingGateway( new StubGateway() ) );
		$manager               = $this->manager();
		$claimed               = (int) $this->claimRows( $order->id )[0]['amount_minor'];
		$this->settledByB      = null;

		// The barrier: A has asked the gateway; before A's transaction, B settles the claim, whole.
		$transactions->beforeTransaction(
			function () use ( $uuid, $b, $manager ): void {
				if ( null === $this->settledByB ) {
					$this->settledByB = $b->settleClaim( $uuid, new ClaimStatement( false, self::NOTE ), $manager );
				}
			}
		);

		try {
			$a->settleClaim( $uuid, new ClaimStatement( true, self::NOTE, 'manual-re-1', $claimed ), $manager );
			$this->fail( 'A settled a claim B had ended.' );
		} catch ( CodedException $ended ) {
			$this->assertSame( array( PaymentError::RefundClaimEnded, array( 'state' => 'declined' ) ), array( $ended->errorCode(), $ended->context() ), 'A is told how B ended the claim.' );
		}

		$this->assertInstanceOf( SettledClaim::class, $this->settledByB );
		$this->assertSame( array(), $this->refundLedgerRows( $order->id ), 'Nothing reached the ledger.' );
		$this->assertSame( '0', (string) $this->orderRow( $order->id )['has_unreconciled_money'] );
		$this->assertSettledOnceBy( $order->id, $uuid, ClaimStatement::NOT_REFUNDED );
	}

	/**
	 * Asserts that a claim was settled once, with the statement given, and that the order has one event of its settlement.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $orderId   The order.
	 * @param string $uuid      The claim.
	 * @param string $statement The statement the settlement kept.
	 */
	private function assertSettledOnceBy( int $orderId, string $uuid, string $statement ): void {
		$this->assertSame( $statement, $this->db->fetchValue( 'SELECT statement FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid ), 'The winner\'s statement is the one kept.' );
		$this->assertCount( 1, array_filter( $this->eventsOf( $orderId ), static fn( string $event ): bool => str_ends_with( $event, ':' . RefundService::SETTLED_REASON ) ), 'One settlement event.' );
	}

	/**
	 * Builds the fixture order: three tees, taxed, shipped.
	 *
	 * @since 0.2.0
	 *
	 * @return \SEOCart\Order\Domain\NewOrder The document.
	 */
	private static function order(): \SEOCart\Order\Domain\NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}
}
