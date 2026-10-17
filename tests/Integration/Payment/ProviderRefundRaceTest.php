<?php
/**
 * Tests a person's settlement of a refund's claim racing the provider's own word of the same refund: whichever takes the intent's lock first ends the claim, and the other meets it ended
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\ClaimStatement;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\RefundService;
use SEOCart\Payment\Domain\Event\RefundRecorded;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlRefundRepository;
use SEOCart\Payment\Infrastructure\RefundClaimTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\ServerKind;
use SEOCart\Tests\Support\Doubles\RememberingGateway;
use SEOCart\Tests\Support\Doubles\WebhookFirstGateway;
use SEOCart\Tests\Support\Payment\RefundOrders;
use SEOCart\Tests\Support\Payment\RefundTestCase;
use SEOCart\Tests\Support\RunningProbe;

/**
 * Two real connections, never a pause: one side runs here, and a barrier just before its ledger row starts the other in a process of its own, then returns once the server shows that process waiting for the intent's lock under which a claim ends.
 *
 * The claim is left open as a real one is: the refund was asked while the gateway could not be
 * reached. The person states the refund was made, as `re_stated`; the provider's word names that
 * refund, or the one it made under the claim's uuid.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class ProviderRefundRaceTest extends RefundTestCase {

	/**
	 * Why the person says so.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const NOTE = 'The provider\'s dashboard shows the refund, made the day the request timed out.';

	/**
	 * The ledger row's insert, before which the barrier starts the other side.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LEDGER_ROW = '/^INSERT INTO \S*payment_transactions\b/';

	/**
	 * The state the server shows a locking read waiting for a row lock in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING = 'statistics';

	/**
	 * The gateway the claims were asked of, which cannot say what became of them.
	 *
	 * @since 0.2.0
	 *
	 * @var RememberingGateway
	 */
	private RememberingGateway $provider;

	/**
	 * The refund service over that gateway.
	 *
	 * @since 0.2.0
	 *
	 * @var RefundService
	 */
	private RefundService $service;

	/**
	 * The other side, once the barrier started it.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probe = null;

	/**
	 * Builds the gateway and the service over it.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->provider        = new RememberingGateway( new StubGateway() );
		$this->provider->knows = false;
		$this->service         = $this->refundsOver( $this->db, $this->ids, $this->provider );
	}

	/**
	 * Tests that the provider's word arriving while a person settles the claim waits for the intent's lock, then meets the claim the settlement ended: the refund the person named is a duplicate, and one they did not name is money kept for a person, never a second refund recorded.
	 *
	 * Planted violation: in RefundService::recordClaimed(), drop lockOpenClaim(): the delivery no
	 * longer waits for the claim's lock, nor reads the claim under it.
	 *
	 * @since 0.2.0
	 */
	public function test_the_providers_word_waiting_for_a_settlement_meets_the_claim_it_ended(): void {
		foreach ( array(
			'the refund the person named' => array( true, ProviderRefundKind::Duplicate ),
			'another refund'              => array( false, ProviderRefundKind::Unexpected ),
		) as $case => list( $theStated, $expected ) ) {
			list( $order, $intent, $uuid, $amount ) = $this->claimed();
			$this->probe                            = null;
			$stated                                 = 're_stated_' . substr( $uuid, -12 );
			$named                                  = $theStated ? $stated : 'stub-re-' . $uuid;
			$currency                               = (string) $this->claimRow( $uuid )['currency'];

			$this->beforeStatement(
				self::LEDGER_ROW,
				function () use ( $intent, $uuid, $amount, $currency, $named ): void {
					$this->probe = $this->startDeliveryProbe( $intent, $uuid, $amount, $currency, $named );

					$this->awaitProbeWaiting( $this->probe, $this->claimLock( $intent->uuid ), self::WAITING );
				}
			);

			$settled = $this->service->settleClaim( $uuid, new ClaimStatement( true, self::NOTE, $stated, $amount ), $this->manager() );

			$this->assertNotNull( $this->probe, $case . ': the provider\'s word never raced the settlement.' );
			$this->assertSame( 'recorded', $settled->state->value, $case );
			$this->assertSame( $expected->value, $this->probe->finish()['delivered'] ?? null, $case );
			$this->assertSame( array( $stated ), $this->appliedRefunds( $order->id ), $case . ': never a second refund recorded.' );
			$this->assertCount( 1, $this->refundRows( $order->id ), $case );
			$this->assertSame( 'recorded', (string) $this->claimRow( $uuid )['state'], $case );
		}
	}

	/**
	 * Tests that a person's settlement arriving while the provider's word is recorded waits for the intent's lock, then is refused as ended, recorded, with nothing written.
	 *
	 * Planted violation: in RefundService::settleLocked(), drop lockOpenClaim(): the settlement no
	 * longer waits for the claim's lock, nor reads the claim under it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_settlement_waiting_for_the_providers_word_is_refused_as_ended(): void {
		list( $order, $intent, $uuid, $amount ) = $this->claimed();
		$this->probe                            = null;
		$manager                                = $this->manager();

		$this->beforeStatement(
			self::LEDGER_ROW,
			function () use ( $intent, $uuid, $amount, $manager ): void {
				$this->probe = $this->startSettleProbe( $uuid, new ClaimStatement( true, self::NOTE, 're_stated', $amount ), $manager );

				$this->awaitProbeWaiting( $this->probe, $this->claimLock( $intent->uuid ), self::WAITING );
			}
		);

		$delivered = $this->service->recordProviderRefund( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ), Actor::system( 'webhook', 0 ) );

		$this->assertNotNull( $this->probe, 'The settlement never raced the provider\'s word.' );
		$this->assertSame( ProviderRefundKind::Recorded->value, $delivered->kind->value, 'What the provider\'s word came to: ' . (string) wp_json_encode( $delivered ) );

		$report = $this->probe->finish();

		$this->assertSame( array( PaymentError::RefundClaimEnded->value, array( 'state' => 'recorded' ) ), array( $report['refused'] ?? $report, $report['context'] ?? null ) );
		$this->assertSame( array( 'stub-re-' . $uuid ), $this->appliedRefunds( $order->id ) );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertNull( $this->claimRow( $uuid )['statement'], 'The settlement was not noted.' );
	}

	/**
	 * Tests that the provider's word of a refund arriving before the refund's own answer is recorded is recorded through the open claim, and the answer then meets it: one row, and the refund answers with the document the delivery wrote.
	 *
	 * The provider's webhook reaches the store while the refund call is still on its way back: the
	 * gateway delivers its answer, in a process of its own, before returning it, outside any
	 * transaction. The claim's uuid is the request's: nothing is read on the refund's connection
	 * while its answer is on the way.
	 *
	 * Planted violation: in RefundService::documentOf(), answer no document: the refund then meets
	 * the delivery's row as a duplicate with no document to answer with.
	 *
	 * @since 0.2.0
	 */
	public function test_the_providers_word_before_the_refunds_own_answer_is_recorded_once(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$report                 = array();
		$service                = $this->refundsOver(
			$this->db,
			$this->ids,
			new WebhookFirstGateway(
				new StubGateway(),
				static fn( GatewayResult $result ): GatewayResult => $result,
				function ( GatewayRefund $request, GatewayResult $answer ) use ( $intent, &$report ): void {
					$report = $this->startDeliveryProbe( $intent, $request->refundUuid, $answer->amount->minorUnits(), $answer->amount->currency()->code(), (string) $answer->providerObjectId )->finish();
				}
			)
		);

		$refund = $this->refund( $order->uuid, array( $tee => 1 ), false, $service );

		$this->assertSame( ProviderRefundKind::Recorded->value, $report['delivered'] ?? $report, 'The provider\'s word was recorded through the open claim.' );
		$this->assertSame( array( 'stub-re-' . $refund->uuid ), $this->appliedRefunds( $order->id ), 'One row.' );
		$this->assertSame( array( $refund->uuid ), array_column( $this->refundRows( $order->id ), 'uuid' ), 'The refund answered with the document the delivery wrote.' );
		$this->assertSame( 'recorded', (string) $this->claimRow( $refund->uuid )['state'] );
	}

	/**
	 * Tests that the provider's word of a refund arriving while the refund's own answer is recorded waits for the intent's lock under which the claim ends, then meets the claim the answer ended: a repeat, one row.
	 *
	 * The claim's uuid is the gateway request's, remembered when the call was made, and the claim's
	 * lock is read before the refund's transaction: nothing is read on the refund's connection inside
	 * it, whose snapshot would then predate the delivery.
	 *
	 * Planted violation: in RefundService::recordClaimed(), drop lockOpenClaim(): the delivery no
	 * longer waits for the claim's lock.
	 *
	 * @since 0.2.0
	 */
	public function test_the_providers_word_during_the_refunds_own_answer_waits_and_is_a_repeat(): void {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$lock                   = $this->claimLock( $intent->uuid );
		$asked                  = array();
		$this->probe            = null;
		$service                = $this->refundsOver(
			$this->db,
			$this->ids,
			new WebhookFirstGateway(
				new StubGateway(),
				static fn( GatewayResult $result ): GatewayResult => $result,
				static function ( GatewayRefund $request, GatewayResult $answer ) use ( &$asked ): void {
					$asked = array( $request->refundUuid, $answer );
				}
			)
		);

		$this->beforeStatement(
			self::LEDGER_ROW,
			function () use ( $intent, $lock, &$asked ): void {
				list( $uuid, $answer ) = $asked;
				$this->probe           = $this->startDeliveryProbe( $intent, $uuid, $answer->amount->minorUnits(), $answer->amount->currency()->code(), (string) $answer->providerObjectId );

				$this->awaitProbeWaiting( $this->probe, $lock, self::WAITING );
			}
		);

		$refund = $this->refund( $order->uuid, array( $tee => 1 ), false, $service );

		$this->assertNotNull( $this->probe, 'The provider\'s word never raced the refund\'s answer.' );
		$this->assertSame( ProviderRefundKind::Duplicate->value, $this->probe->finish()['delivered'] ?? null, 'The provider\'s word met the row the answer wrote: a repeat.' );
		$this->assertSame( array( 'stub-re-' . $refund->uuid ), $this->appliedRefunds( $order->id ), 'One row.' );
		$this->assertCount( 1, $this->refundRows( $order->id ) );
		$this->assertSame( 'recorded', (string) $this->claimRow( $refund->uuid )['state'] );
	}

	/**
	 * Tests that the provider's word for an open claim, meeting another request's change of the order, records the refund once on both servers.
	 *
	 * The claim's lock reads the ledger and the claims in subqueries, which no lock covers, so it
	 * takes the transaction's snapshot. Another request commits a change of the order's row from a
	 * second connection just before the delivery locks the order. MySQL's lock reads the newest row
	 * and the delivery goes on. MariaDB refuses the lock of a row changed since the snapshot (error
	 * 1020) and rolls the delivery's transaction back; its deadlock policy runs it again, once. Both
	 * end alike: one refund on the ledger, the claim recorded once, one `refund_recorded` event.
	 *
	 * Planted violation: in RefundService::recordClaimed(), run the transaction with no retry
	 * policy: on MariaDB the delivery fails with `database.transaction_retryable`.
	 *
	 * @since 0.2.0
	 */
	public function test_the_providers_word_meeting_a_change_of_the_order_records_the_refund_once(): void {
		list( $order, $intent, $uuid ) = $this->claimed();

		$b      = $this->secondConnection();
		$orders = $this->table( OrderTables::ORDERS );
		$raced  = $this->beforeStatement(
			'/^SELECT id, uuid, order_number, .* FROM `' . preg_quote( $orders, '/' ) . '` WHERE id = ' . $order->id . ' FOR UPDATE/s',
			static function () use ( $b, $orders, $order ): void {
				$b->query( "UPDATE `$orders` SET updated_at = UTC_TIMESTAMP(6) WHERE id = {$order->id}" );
			}
		);

		$outcome = $this->service->recordProviderRefund( $uuid, $this->claimedResult( $intent, $uuid, Outcome::Approved ), Actor::system( 'webhook', 0 ) );

		$this->assertTrue( $raced->fired, 'The other request never committed, so nothing raced the delivery.' );
		$this->assertSame( array( ProviderRefundKind::Recorded, $uuid ), array( $outcome->kind, $outcome->claimUuid ) );
		$this->assertSame( array( 'stub-re-' . $uuid ), $this->appliedRefunds( $order->id ), 'One refund on the ledger.' );
		$this->assertCount( 1, $this->refundLedgerRows( $order->id ), 'No other refund row.' );
		$this->assertSame( 'recorded', (string) $this->claimRow( $uuid )['state'], 'The claim ended once, recorded.' );
		$this->assertCount( 1, $this->refundRows( $order->id ), 'One refund document.' );
		$this->assertSame( 1, $this->outboxRows( RefundRecorded::eventName() ), 'One refund_recorded event.' );
		$this->assertCount( ServerKind::MariaDB === $this->db->server()->kind() ? 1 : 0, $this->sleeps, 'MySQL locks the newest row at once; MariaDB runs the delivery again, once.' );
	}

	/**
	 * Returns the order every test places: three units of one line.
	 *
	 * @since 0.2.0
	 *
	 * @return NewOrder The order.
	 */
	private static function order(): NewOrder {
		return RefundOrders::priced( array( RefundOrders::line( 'tee', '12.34', 3, 'standard' ) ) );
	}

	/**
	 * Places a paid order and leaves a refund of one unit claimed and open, as the gateway could not be reached.
	 *
	 * @since 0.2.0
	 *
	 * @return array{0: \SEOCart\Order\Domain\InsertedOrder, 1: \SEOCart\Payment\Domain\IntentRef, 2: string, 3: int} The order, its intent, the claim's uuid and its amount.
	 */
	private function claimed(): array {
		list( $order, $intent ) = $this->placePaid( self::order() );
		list( $tee )            = $this->lineUuids( $order->id );
		$uuid                   = $this->openClaim( $order->uuid, $tee, $this->provider, $this->service );

		return array( $order, $intent, $uuid, (int) $this->claimRow( $uuid )['amount_minor'] );
	}

	/**
	 * Returns the intent's lock under which a claim ends, as the server receives it.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent.
	 * @return string The statement.
	 */
	private function claimLock( string $intentUuid ): string {
		return $this->rawRefund( MysqlRefundRepository::LOCK_FOR_CLAIM, MysqlRefundRepository::NEVER_RECONCILED, (int) $this->intentRow( $intentUuid )['id'] );
	}

	/**
	 * Reads a claim's row.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The claim.
	 * @return array<string, mixed> The row.
	 */
	private function claimRow( string $uuid ): array {
		return (array) $this->db->fetchRow( 'SELECT * FROM %i WHERE uuid = %s', $this->table( RefundClaimTables::CLAIMS ), $uuid );
	}

	/**
	 * Lists the provider objects of an order's refund rows applied to the ledger, in order.
	 *
	 * @since 0.2.0
	 *
	 * @param int $orderId The order.
	 * @return list<string> The objects.
	 */
	private function appliedRefunds( int $orderId ): array {
		return array_values( array_map( static fn( array $row ): string => (string) $row['provider_object_id'], array_filter( $this->refundLedgerRows( $orderId ), static fn( array $row ): bool => '1' === (string) $row['applied'] ) ) );
	}
}
