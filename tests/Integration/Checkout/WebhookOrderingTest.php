<?php
/**
 * Tests that a delivery before, during, beside or after the answer in the request, or twice at once, settles the payment once
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\MysqlPaymentRepository;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;
use SEOCart\Tests\Support\RunningProbe;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The tests read committed rows on a second connection.

/**
 * Every applier of a result takes the intent's lock first, and the ledger's key claims the result once, so a delivery and the request's own answer serialise there, whichever comes first.
 *
 * Two real connections and barriers, never a pause: the request's side runs in this process, and
 * a barrier just before one of its statements starts the delivery in a process of its own, then
 * waits for it to end, or for the server to show it waiting on the intent's lock. Connection B
 * holds an uncommitted intent for the delivery during the first unit of work.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 *
 * @group concurrency
 */
final class WebhookOrderingTest extends WebhookTestCase {

	/**
	 * The intent's lock, as the money path sends it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LOCK_INTENT = '/^SELECT id, uuid, order_id, gateway_id, mode, status, .+ FOR UPDATE$/s';

	/**
	 * The order's lock, which the money path sends after the intent's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LOCK_ORDER = '/^SELECT id, uuid, order_number, channel, status, .+ FOR UPDATE$/s';

	/**
	 * How the intent's lock begins as the server shows it, while a delivery waits for it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const LOCK_INTENT_SENT = 'SELECT id, uuid, order_id, gateway_id, mode, status, amount_minor';

	/**
	 * The state the server shows a locking read waiting for a row lock in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const WAITING = 'statistics';

	/**
	 * The delivery the barrier started in a process of its own.
	 *
	 * @since 0.2.0
	 *
	 * @var RunningProbe|null
	 */
	private ?RunningProbe $probe = null;

	/**
	 * What the delivery the barrier started, or the test's own, came to.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, mixed>
	 */
	private array $report = array();

	/**
	 * The id of the event the barrier delivered.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $eventId = '';

	/**
	 * Tests that an approval delivered after the gateway answered but before the placement's second unit of work settles the placement, and that unit meets it as a duplicate.
	 *
	 * The barrier runs just before the placement's unit of work locks the intent: the delivery runs
	 * to its end in a process of its own.
	 *
	 * Planted violation: drop the ledger's unique key `provider_object_operation` from PaymentTables:
	 * the placement's own unit of work no longer meets the delivery's row as a duplicate, and fails.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_delivered_before_the_second_unit_of_work_settles_once(): void {
		$variant = $this->sellable();

		$this->readyCart( array( $variant => 1 ) );

		$barrier = $this->beforeStatement(
			self::LOCK_INTENT,
			function (): void {
				$probe = $this->startDelivery( StubWebhooks::of( $this->approvalOf( $this->latestPlaced() ) ) );

				$this->report = $probe->finish();
			}
		);

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$placed = $this->latestPlaced();

		$this->assertTrue( $barrier->fired );
		$this->assertSame( 'applied', $this->report['result'] ?? $this->report, 'The delivery settled the placement.' );
		$this->assertSame( array( 'duplicate', 'processing', 'authorized' ), array( $answer['outcome'], $answer['status'], $answer['payment_status'] ), 'The placement meets the delivery\'s settlement.' );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( array( 5, 1, 0 ), $this->committedStock( $this->secondConnection(), $variant ) );
	}

	/**
	 * Tests that an approval delivered while the second unit of work holds the intent waits for it, and then meets it as a duplicate.
	 *
	 * The barrier runs just before the placement's unit of work locks the order, while it holds the
	 * intent: the delivery is started in a process of its own, and the barrier returns once the
	 * server shows its lock of the intent waiting.
	 *
	 * Planted violation: in MysqlPaymentRepository::LOCK_INTENT, drop `FOR UPDATE`: the delivery no
	 * longer waits, and the barrier fails while it waits for it to.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_delivered_during_the_second_unit_of_work_waits_and_meets_it(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$barrier = $this->beforeStatement(
			self::LOCK_ORDER,
			function (): void {
				$delivery = StubWebhooks::of( $this->approvalOf( $this->latestPlaced() ) );
				$probe    = $this->startDelivery( $delivery );

				$this->probe   = $probe;
				$this->eventId = $delivery->eventId();

				$this->awaitProbeSending( $probe, self::LOCK_INTENT_SENT, self::WAITING );
			}
		);

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$probe  = $this->probe();

		$this->assertTrue( $barrier->fired );
		$this->assertInstanceOf( RunningProbe::class, $probe );
		$this->assertSame( 'approved', $answer['outcome'], 'The placement settled first.' );
		$this->assertSame( 'duplicate', $probe->finish()['result'] ?? null, 'The delivery waited, then met the placement\'s row.' );
		$this->assertSame( 'duplicate', $this->receiptOf( $this->eventId )['result'] ?? null );
		$this->assertCount( 1, $this->ledgerOf( $this->latestPlaced()['order_id'] ) );
	}

	/**
	 * Tests that a decline of the object the request approved, delivered while the request's second unit of work holds the intent, waits, and is then stale: another outcome of the same object, never a duplicate.
	 *
	 * Planted violation: in MysqlPaymentRepository::FIND_TRANSACTION, read the row whatever its
	 * outcome: the decline meets the approval's row and is answered as a duplicate.
	 *
	 * @since 0.2.0
	 */
	public function test_a_decline_of_the_approved_object_delivered_during_the_second_unit_of_work_is_stale(): void {
		$this->readyCart( array( $this->sellable() => 1 ) );

		$this->beforeStatement(
			self::LOCK_ORDER,
			function (): void {
				$placed      = $this->latestPlaced();
				$this->probe = $this->startDelivery( StubWebhooks::of( $this->resultAbout( $placed, Operation::Authorize, Outcome::Declined, 'stub-ch-' . $placed['intent_uuid'], StubGateway::CARD_DECLINED ) ) );

				$this->awaitProbeSending( $this->probe(), self::LOCK_INTENT_SENT, self::WAITING );
			}
		);

		$answer = $this->placement->place( $this->placeInput(), self::guest() );
		$placed = $this->latestPlaced();

		$this->assertSame( 'approved', $answer['outcome'], 'The placement settled first.' );
		$this->assertSame( 'stale', $this->probe()->finish()['result'] ?? null, 'The decline waited, then found the payment past it.' );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
		$this->assertSame( '0', $this->orderState( $placed['order_id'] )['has_unreconciled_money'] );
	}

	/**
	 * Tests that an approval delivered after the placement settled is a duplicate, and its receipt says so.
	 *
	 * @since 0.2.0
	 */
	public function test_an_approval_delivered_after_the_placement_is_a_duplicate(): void {
		$placed   = $this->placed( StubGateway::APPROVE );
		$delivery = StubWebhooks::of( $this->approvalOf( $placed ) );

		$this->assertSame( ReceiptResult::Duplicate, $this->deliver( $delivery ) );
		$this->assertNotNull( $this->receiptOf( $delivery->eventId() )['transaction_id'] ?? null, 'The receipt names the row the placement wrote.' );
		$this->assertCount( 1, $this->ledgerOf( $placed['order_id'] ) );
	}

	/**
	 * Tests that two deliveries of one event at once settle it once: the second finds the receipt undecided, waits on the intent's lock, meets the first's row, and leaves the first's decision on the receipt.
	 *
	 * Planted violation: drop the ledger's unique key `provider_object_operation` from PaymentTables:
	 * the second delivery no longer meets the first's row as a duplicate.
	 *
	 * @since 0.2.0
	 */
	public function test_two_deliveries_of_one_event_at_once_settle_it_once(): void {
		$placed   = $this->placed( StubGateway::THROW );
		$delivery = StubWebhooks::of( $this->approvalOf( $placed ) );

		$this->beforeStatement(
			self::LOCK_ORDER,
			function () use ( $delivery ): void {
				$this->probe = $this->startDelivery( $delivery );

				$this->awaitProbeSending( $this->probe(), self::LOCK_INTENT_SENT, self::WAITING );
			}
		);

		$this->assertSame( ReceiptResult::Applied, $this->deliver( $delivery ) );
		$this->assertSame( 'duplicate', $this->probe()->finish()['result'] ?? null, 'The second delivery met the first\'s row.' );
		$this->assertSame( array( 'applied', 1 ), array( $this->receiptOf( $delivery->eventId() )['result'] ?? null, $this->receiptCount() ), 'One receipt, with the first decision.' );
		$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ) );
	}

	/**
	 * Tests that a delivery naming an intent another connection is inserting, uncommitted, is ignored at once, without waiting for it, whether the insert then commits or rolls back.
	 *
	 * A provider learns a server-made intent only once the store asked it, after the first unit of
	 * work committed, so this delivery cannot come from it; the store's answer to the request then
	 * settles the intent as usual. The delivery reads the intent without a lock, which does not
	 * wait for the uncommitted row.
	 *
	 * Planted violation: in ReceiveWebhook::decide(), go to the money path without reading the
	 * intent first (`$intent = new IntentRef( … )` from the result): the delivery's lock of the intent
	 * then waits for connection B, which this process holds, until the server gives up.
	 *
	 * @since 0.2.0
	 */
	public function test_a_delivery_during_the_first_unit_of_work_is_ignored_at_once(): void {
		foreach ( array( 'COMMIT', 'ROLLBACK' ) as $end ) {
			$placed = $this->placed( StubGateway::THROW, 'during-' . strtolower( $end ) );
			$intent = $this->intentRow( $placed['intent_uuid'] );
			$uuid   = wp_generate_uuid4();
			$b      = $this->secondConnection();

			$b->query( 'START TRANSACTION' );
			$b->query( $this->rawPaymentStatement( MysqlPaymentRepository::INSERT_INTENT, $uuid, $placed['order_id'], StubGateway::ID, 'test', (int) $intent['amount_minor'], (string) $intent['currency'], (int) $intent['conversion_context_id'], (string) $intent['base_currency'], (int) $intent['base_amount_minor'] ) );

			$delivery = StubWebhooks::of( self::stubResult( $uuid, Operation::Authorize, Outcome::Approved, (int) $intent['amount_minor'], (string) $intent['currency'], 'stub-ch-' . $uuid ) );

			$this->assertSame( ReceiptResult::Ignored, $this->deliver( $delivery ), $end );
			$this->assertSame( 'unknown_intent', $this->receiptOf( $delivery->eventId() )['result_code'] ?? null, $end );

			$b->query( $end );

			$this->assertSame( 'COMMIT' === $end ? 1 : 0, (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i WHERE uuid = %s', $this->table( PaymentTables::INTENTS ), $uuid ), $end );
			$this->assertSame( array(), $this->ledgerOf( $placed['order_id'] ), $end . ': no money moved.' );
		}
	}

	/**
	 * Returns the delivery the barrier started, failing the test when it never did.
	 *
	 * @since 0.2.0
	 *
	 * @return RunningProbe The running delivery.
	 */
	private function probe(): RunningProbe {
		$this->assertNotNull( $this->probe, 'The barrier never started the delivery.' );

		return $this->probe;
	}

	/**
	 * Returns a statement of the payment module prepared for connection B, from its own constant.
	 *
	 * @since 0.2.0
	 *
	 * @param string $statement A constant of MysqlPaymentRepository.
	 * @param mixed  ...$values Its values.
	 * @return string The statement, ready to send.
	 */
	private function rawPaymentStatement( string $statement, mixed ...$values ): string {
		global $wpdb;

		list( $sql, $arguments ) = MysqlPaymentRepository::expand( $statement, $values, fn( string $name ): string => $this->table( $name ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The statement is the module's constant, expanded; this is its prepare step.
		return (string) $wpdb->prepare( $sql, ...$arguments );
	}

	/**
	 * Tests that a capture delivered before, beside or after the capture operation's own answer moves the money once: the one first under the intent's lock applies it, the other meets its row.
	 *
	 * Planted violation (beside): in MysqlPaymentRepository::LOCK_INTENT, drop `FOR UPDATE`: the
	 * delivery no longer waits, and the barrier fails while it waits for it to.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_delivered_around_the_operation_captures_once(): void {
		foreach ( array(
			'before' => self::LOCK_INTENT,
			'beside' => self::LOCK_ORDER,
			'after'  => null,
		) as $when => $statement ) {
			$placed   = $this->placed( StubGateway::APPROVE, 'capture-' . $when );
			$delivery = StubWebhooks::of( $this->captureOf( $placed ) );

			if ( null !== $statement ) {
				$this->beforeStatement(
					$statement,
					function () use ( $delivery, $when ): void {
						$this->probe = $this->startDelivery( $delivery );

						if ( 'before' === $when ) {
							$this->report = $this->probe()->finish();

							return;
						}

						$this->awaitProbeSending( $this->probe(), self::LOCK_INTENT_SENT, self::WAITING );
					}
				);
			}

			$applied = $this->kernel->get( PaymentService::class )->capture( $placed['intent_uuid'], $this->capturer() );

			if ( 'after' === $when ) {
				$this->report = array( 'result' => $this->deliver( $delivery )->value );
			} elseif ( 'beside' === $when ) {
				$this->report = $this->probe()->finish();
			}

			$this->assertSame( 'before' === $when ? ApplicationKind::Duplicate : ApplicationKind::Applied, $applied->kind, $when );
			$this->assertSame( 'before' === $when ? 'applied' : 'duplicate', $this->report['result'] ?? null, $when );
			$this->assertSame( array( 'authorize:approved:1:stub-ch-' . $placed['intent_uuid'], 'capture:approved:1:stub-cap-' . $placed['intent_uuid'] ), $this->ledgerOf( $placed['order_id'] ), $when );
			$this->assertSame( 'captured', $this->intentRow( $placed['intent_uuid'] )['status'], $when );
		}
	}

	/**
	 * Tests a void delivered around a person's void of an accepted order: it voids once, and the order is parked for a person only when the delivery came first, since the store cannot tell the provider's word of the person's own void from a void made in its dashboard.
	 *
	 * The known limit is pinned: the person who voided finds the order parked, with nothing moved
	 * wrongly, and clears it.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_delivered_around_a_persons_void_voids_once(): void {
		foreach ( array(
			'before' => self::LOCK_INTENT,
			'beside' => self::LOCK_ORDER,
		) as $when => $statement ) {
			$placed   = $this->placed( StubGateway::APPROVE, 'void-' . $when );
			$delivery = StubWebhooks::of( $this->voidOf( $placed ) );

			$this->beforeStatement(
				$statement,
				function () use ( $delivery, $when ): void {
					$this->probe = $this->startDelivery( $delivery );

					if ( 'before' === $when ) {
						$this->report = $this->probe()->finish();

						return;
					}

					$this->awaitProbeSending( $this->probe(), self::LOCK_INTENT_SENT, self::WAITING );
				}
			);

			$applied = $this->kernel->get( PaymentService::class )->void( $placed['intent_uuid'], $this->userGranted( PaymentService::VOID_CAPABILITY ), VoidReason::CustomerRequest );

			if ( 'beside' === $when ) {
				$this->report = $this->probe()->finish();
			}

			$this->assertSame( 'before' === $when ? ApplicationKind::Duplicate : ApplicationKind::Applied, $applied->kind, $when );
			$this->assertSame( 'before' === $when ? 'applied' : 'duplicate', $this->report['result'] ?? null, $when );
			$this->assertCount( 2, $this->ledgerOf( $placed['order_id'] ), $when . ': one authorization, one void.' );
			$this->assertSame( 'before' === $when ? array( 'on_hold', 'voided', '1' ) : array( 'processing', 'voided', '0' ), array_values( $this->orderState( $placed['order_id'] ) ), $when );
			$this->assertSame( 'before' === $when ? VoidReason::VoidedExternally->value : VoidReason::CustomerRequest->value, $this->intentRow( $placed['intent_uuid'] )['voided_reason'], $when . ': the first void names its reason.' );
		}
	}
}
