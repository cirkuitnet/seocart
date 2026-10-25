<?php
/**
 * Tests that a capture the provider delivers for a payment still waiting for its answer settles the placement, as the reconciliation run's query of the same capture does
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Cart\Infrastructure\CartTables;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\WebhookEnvelope;
use SEOCart\Contracts\Payment\WebhookReading;
use SEOCart\Inventory\Infrastructure\InventoryTables;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Domain\Webhook\ReceiptResult;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\ScriptedQueries;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A provider may capture a payment and say so by webhook while the store still waits for the answer to its authorization: the gateway was still deciding, the shopper was asked to act, or the gateway never answered. The delivery is settled with the placement, as the reconciliation run settles the same capture when it asks, so the order ends the same whichever reaches the store first.
 *
 * - The capture of a payment the gateway was still deciding is a sale: the intent captured, the
 *   order accepted and paid, its units allocated from its hold, its promotion use committed and
 *   its cart converted.
 * - A capture of a payment waiting on its shopper, or never answered, is one the payment's state
 *   cannot take: kept for a person, the order flagged and on hold, nothing captured; its units
 *   are allocated while a person looks, its use committed and its cart converted.
 * - A declined capture of a payment the gateway was still deciding fails it, and the placement
 *   gives back what it held: the hold and the use released and the cart opened again. One of a
 *   payment waiting on its shopper is about an attempt the payment has left, and changes nothing.
 * - The delivery and the run's query of one capture end in the same rows whichever lands first,
 *   and the delivery is acknowledged either way.
 * - A capture delivered for a payment already authorized moves only the money, as it always did.
 *
 * Each case settles two payments alike, one by the delivery and one by the run's query, and
 * compares every row a settlement writes before and after (PlacementSnapshots).
 *
 * Planted violation, shown red and removed: in ReceiveWebhook::apply(), apply every delivered
 * capture with PaymentService::applyDelivered(), whatever the intent's state: the delivered sale
 * and the delivered decline leave the hold, the use and the cart as they were, the kept captures
 * leave them too, and of two payments captured alike the one the delivery reached first keeps its
 * hold while the one the run asked first is allocated.
 *
 * @since 0.2.0
 */
final class WebhookCaptureTest extends WebhookTestCase {

	use PlacementSnapshots;
	use ScriptedQueries;

	/**
	 * Tests that the capture of a payment the gateway was still deciding, delivered, is the sale the run's query of it makes.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_the_gateway_was_deciding_is_a_sale(): void {
		list( $receipt, $payments, $before ) = $this->settledBothWays( StubGateway::PENDING, Outcome::Approved );

		$this->assertSame( 'processing', $payments['delivered']['status'] );
		$this->assertSame( ReceiptResult::Applied, $receipt );
		$this->assertChanges( $this->alike( $payments, static fn( string $name, int $amount ): array => self::merged( self::sold( $name, $amount ), self::used( $name, 'committed' ) ) ), $before, $payments );
		$this->assertSame( array( 'approved', 'approved' ), $this->keptOutcomes( $payments ), 'A retry of either placement is told it was accepted.' );
	}

	/**
	 * Tests that the capture of a payment waiting on its shopper, or never answered, delivered, is kept for a person as the run's query of it keeps it.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_waiting_payments
	 *
	 * @param string $token         The payment token the placement is paid with.
	 * @param string $status        The intent's state the placement leaves.
	 * @param string $paymentStatus The order's payment status the placement leaves.
	 */
	public function test_a_capture_of_a_payment_waiting_on_its_shopper_or_never_answered_is_kept_for_a_person( string $token, string $status, string $paymentStatus ): void {
		list( $receipt, $payments, $before ) = $this->settledBothWays( $token, Outcome::Approved );

		$this->assertSame( $status, $payments['delivered']['status'] );
		$this->assertSame( ReceiptResult::Unapplied, $receipt );
		$this->assertChanges( $this->alike( $payments, static fn( string $name, int $amount ): array => self::merged( self::keptForAPerson( $name, 'capture', $amount, $paymentStatus ), self::used( $name, 'committed' ) ) ), $before, $payments );
		$this->assertSame( array( 'amount_mismatch', 'amount_mismatch' ), $this->keptOutcomes( $payments ) );
		$this->assertSame( array( 'capture:approved:0:stub-cap-' . $payments['delivered']['intent_uuid'] ), $this->ledgerOf( $payments['delivered']['order_id'] ), 'Nothing was captured.' );
	}

	/**
	 * Returns the placements whose payment waits for an answer the gateway cannot give alone.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> The payment token, and the intent's state and the order's
	 *                                                               payment status it leaves.
	 */
	public static function data_waiting_payments(): array {
		return array(
			'the shopper asked to act'   => array( StubGateway::REQUIRES_ACTION, 'requires_action', 'pending' ),
			'the gateway never answered' => array( StubGateway::THROW, 'created', 'unpaid' ),
		);
	}

	/**
	 * Tests that a declined capture of a payment the gateway was still deciding, delivered, fails it and gives back what its placement held, as the run's query of it does.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declined_capture_of_a_payment_the_gateway_was_deciding_fails_it(): void {
		list( $receipt, $payments, $before ) = $this->settledBothWays( StubGateway::PENDING, Outcome::Declined );

		$this->assertSame( ReceiptResult::Applied, $receipt );
		$this->assertChanges( $this->alike( $payments, static fn( string $name, int $amount ): array => self::declined( $name, $amount ) ), $before, $payments );
		$this->assertSame( array( 'declined', 'declined' ), $this->keptOutcomes( $payments ) );
	}

	/**
	 * Tests that a declined capture of a payment waiting on its shopper, delivered, is stale and changes nothing, as the run's query of it changes nothing.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declined_capture_of_a_payment_waiting_on_its_shopper_is_stale(): void {
		list( $receipt, $payments, $before ) = $this->settledBothWays( StubGateway::REQUIRES_ACTION, Outcome::Declined );

		$this->assertSame( ReceiptResult::Stale, $receipt );
		$this->assertChanges( array(), $before, $payments );
		$this->assertSame( array( 'requires_action', 'requires_action' ), $this->keptOutcomes( $payments ), 'Both placements still wait for their shopper.' );
	}

	/**
	 * Tests that the delivery of a capture and the run's query answering the same capture end in the same rows whichever lands first, and that the delivery is acknowledged either way.
	 *
	 * For the first payment the provider's delivery lands while the run is asking, so the run's
	 * answer comes after it and meets its ledger row; for the second the run's answer is settled
	 * first, and the delivery meets its row.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_ends_alike_whether_its_delivery_or_the_runs_query_lands_first(): void {
		$payments = array(
			'webhook_first' => $this->placed( StubGateway::PENDING, 'attempt-webhook-first', 'SAVE-WEBHOOK-FIRST' ),
			'query_first'   => $this->placed( StubGateway::PENDING, 'attempt-query-first', 'SAVE-QUERY-FIRST' ),
		);
		$receipts = array();

		$this->answerQueries(
			array(
				$payments['webhook_first']['intent_uuid'] => function () use ( $payments, &$receipts ): GatewayResult {
					$receipts['webhook_first'] = $this->deliver( StubWebhooks::of( $this->captureOf( $payments['webhook_first'] ) ) );

					return $this->captureOf( $payments['webhook_first'] );
				},
				$payments['query_first']['intent_uuid']   => $this->captureOf( $payments['query_first'] ),
			)
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$receipts['query_first'] = $this->deliver( StubWebhooks::of( $this->captureOf( $payments['query_first'] ) ) );

		$this->assertSame(
			array(
				'webhook_first' => ReceiptResult::Applied,
				'query_first'   => ReceiptResult::Duplicate,
			),
			$receipts,
			'Both deliveries were acknowledged.'
		);
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges( $this->alike( $payments, static fn( string $name, int $amount ): array => self::merged( self::sold( $name, $amount ), self::used( $name, 'committed' ) ) ), $before, $payments );
		$this->assertSame( array( 'approved', 'approved' ), $this->keptOutcomes( $payments ) );
	}

	/**
	 * Tests that a capture delivered for a payment already authorized, whose placement was settled, moves the money alone.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_an_authorized_payment_moves_only_the_money(): void {
		$authorized = $this->placed( StubGateway::APPROVE, 'attempt-authorized', 'SAVE-AUTHORIZED' );

		$this->assertSame( 'authorized', $authorized['status'] );

		$before = $this->takeSnapshot();

		$this->assertSame( ReceiptResult::Applied, $this->deliver( StubWebhooks::of( $this->captureOf( $authorized ) ) ) );
		$this->assertChanges(
			array(
				PaymentTables::INTENTS      => self::change( array( 'authorized authorized 0' ), array( 'authorized captured ' . $this->amountOf( $authorized ) ) ),
				PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'authorized', 'capture:approved:1', $this->amountOf( $authorized ) ) ) ),
				OrderTables::ORDERS         => self::change( array( 'authorized processing/authorized/0' ), array( 'authorized processing/paid/0' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'authorized payment:authorized>paid:payment_captured' ) ),
				OutboxTable::NAME           => self::change( array(), array( 'authorized payment_captured', 'authorized payment_status_changed' ) ),
			),
			$before,
			array( 'authorized' => $authorized )
		);
		$this->assertSame( 'approved', $this->keptOutcome( $authorized['order_id'] ) );
	}

	/**
	 * Places two payments paid with one token, each with a promotion code, and settles a capture of each with one outcome: the first by its delivery, the second by the reconciliation run's query, which finds that payment alone waiting long enough to ask about.
	 *
	 * The snapshot is taken once both are placed, before either is settled.
	 *
	 * @since 0.2.0
	 *
	 * @param string  $token   The payment token both placements are paid with.
	 * @param Outcome $outcome The capture's outcome.
	 * @return array{0: ReceiptResult, 1: array<string, array<string, mixed>>, 2: array<string, list<array<string, mixed>>>} What the delivery came to, the two payments by name, and the snapshot.
	 *
	 * @phpstan-return array{0: ReceiptResult, 1: array<string, array{order_uuid: string, order_id: int, intent_uuid: string, status: string, provider_intent_id: string|null, variant: int}>, 2: array<string, list<array<string, mixed>>>}
	 */
	private function settledBothWays( string $token, Outcome $outcome ): array {
		$payments = array(
			'delivered' => $this->placed( $token, 'attempt-delivered', 'SAVE-DELIVERED' ),
			'queried'   => $this->placed( $token, 'attempt-queried', 'SAVE-QUERIED' ),
		);
		$capture  = fn( array $placed ): GatewayResult => $this->resultAbout( $placed, Operation::Capture, $outcome, 'stub-cap-' . $placed['intent_uuid'], Outcome::Declined === $outcome ? StubGateway::CARD_DECLINED : null );

		$this->answerQueries( array( $payments['queried']['intent_uuid'] => $capture( $payments['queried'] ) ), true );

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( $payments['queried']['intent_uuid'] ), $this->asked, 'The run asked about the payment it found waiting long enough, alone.' );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );

		return array( $this->deliverCapture( $capture( $payments['delivered'] ) ), $payments, $before );
	}

	/**
	 * Delivers a capture: an approved one as the stand-in reports it; a declined one, which the stand-in never reports, as an adapter that reports one would read it.
	 *
	 * For a decline the stand-in still verifies the delivery and reads its event, of a type it does
	 * not act on, and the reading carries the decline in place of what the stand-in made of it.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $capture The capture.
	 * @return ReceiptResult What the delivery came to.
	 */
	private function deliverCapture( GatewayResult $capture ): ReceiptResult {
		if ( Outcome::Declined !== $capture->outcome ) {
			return $this->deliver( StubWebhooks::of( $capture ) );
		}

		$this->gateway->readings = static function ( WebhookEnvelope $envelope ) use ( $capture ): WebhookReading {
			$read = ( new StubGateway() )->readWebhook( $envelope );

			return WebhookReading::result( (string) $read->eventId, (string) $read->eventType, $read->occurredAt, $capture );
		};

		return $this->deliver( StubWebhooks::event( 'evt_capture_declined_' . $capture->intentUuid, 'capture.declined', array() ) );
	}

	/**
	 * Builds the changes the same settlement makes to each of several payments, each with its intent's amount.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $payments The payments, by name.
	 * @param \Closure                            $changes  The changes for one payment, given its name and amount.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes, merged.
	 *
	 * @phpstan-param array<string, array{intent_uuid: string}> $payments
	 * @phpstan-param \Closure(string, int): array<string, array{gone: list<string>, new: list<string>}> $changes
	 */
	private function alike( array $payments, \Closure $changes ): array {
		$each = array();

		foreach ( $payments as $name => $placed ) {
			$each[] = $changes( $name, $this->amountOf( $placed ) );
		}

		return self::merged( ...$each );
	}

	/**
	 * Reads the outcome each payment's placement key keeps, in the payments' order.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<string, mixed>> $payments The payments, by name.
	 * @return list<string|null> The outcomes.
	 */
	private function keptOutcomes( array $payments ): array {
		return array_values( array_map( fn( array $placed ): ?string => $this->keptOutcome( (int) $placed['order_id'] ), $payments ) );
	}

	/**
	 * Returns what a declined capture changes for a payment the gateway was still deciding: the intent and the order failed, the decline's row, the hold and the promotion use released and the cart opened again.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name   The payment.
	 * @param int    $amount The capture's amount, in minor units.
	 * @return array<string, array{gone: list<string>, new: list<string>}> The changes.
	 */
	private static function declined( string $name, int $amount ): array {
		return array(
			PaymentTables::INTENTS      => self::change( array( $name . ' processing 0' ), array( $name . ' failed 0' ) ),
			PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( $name, 'capture:declined:1', $amount ) ) ),
			OrderTables::ORDERS         => self::change( array( $name . ' pending_payment/pending/0' ), array( $name . ' failed/failed/0' ) ),
			OrderTables::EVENTS         => self::change( array(), array( $name . ' order:pending_payment>failed:payment_declined', $name . ' payment:pending>failed:payment_declined' ) ),
			OutboxTable::NAME           => self::change( array(), array( $name . ' order_status_changed', $name . ' payment_failed', $name . ' payment_status_changed' ) ),
			InventoryTables::ITEMS      => self::change( array( $name . ' 5/0/1' ), array( $name . ' 5/0/0' ) ),
			InventoryTables::HOLDS      => self::change( array( $name . ' held 1' ), array() ),
			CartTables::CARTS           => self::change( array( $name . ' placing' ), array( $name . ' open' ) ),
		) + self::used( $name, 'released' );
	}
}
