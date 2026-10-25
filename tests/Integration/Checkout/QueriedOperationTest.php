<?php
/**
 * Tests that a provider asked where a payment stands may answer with a capture or a refund, and the placement settles what the payment path made of it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Checkout;

use SEOCart\Checkout\Application\ResumePayment;
use SEOCart\Checkout\Application\SettlePlacement;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Infrastructure\Jobs\ReconcileStalePlacements;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Events\OutboxTable;
use SEOCart\Tests\Support\Checkout\PlacementSnapshots;
use SEOCart\Tests\Support\Checkout\ScriptedQueries;
use SEOCart\Tests\Support\Checkout\WebhookTestCase;
use SEOCart\Tests\Support\Payment\StubWebhooks;

/**
 * A provider asked where a payment stands may answer with an operation the placement never asked for, such as a capture made in its dashboard while the shopper was asked to act: the payment path decides it, and the placement settles what it did, for the reconciliation run and for the shopper's resume alike.
 *
 * - A capture or a refund the payment's state cannot take is kept for a person: its ledger row
 *   unapplied, the order flagged and on hold, the intent where it was. Nothing is released: the
 *   units are allocated to the order while a person looks, as for an amount the order does not
 *   have. The run goes on to the next payment in the same pass.
 * - The capture of a payment the gateway was still deciding is a sale: the intent captured, the
 *   order accepted and paid, its units allocated and its cart converted.
 * - A declined refund of a payment that holds no capture is recorded, and moves nothing else.
 * - A capture of a payment whose authorization landed, and settled the placement, while the run
 *   was asking moves the money, and nothing of the placement a second time.
 * - A second authorization, a capture or a refund, of a provider object of its own, reported for a
 *   payment its authorization settled and that was captured, partly refunded or refunded since is
 *   kept for a person by the payment path, and moves nothing of the placement: no unit is allocated twice,
 *   and the order's stock, promotion use, cart and kept answer stay as they were.
 *
 * Each test compares every row a settlement writes before and after (PlacementSnapshots).
 *
 * Planted violations, each shown red and removed, one at a time:
 * - in SettlePlacement::apply(), refuse an answer that is neither an authorization's nor a void's
 *   with an \InvalidArgumentException: the run stops at the first payment and never asks the
 *   second, and the resume fails;
 * - in SettlePlacement::settlesNothing(), settle a refund the payment path did not keep: the
 *   declined refund releases the hold of a payment that still waits, and reopens its cart;
 * - in SettlePlacement::settlesNothing(), settle a capture of a payment settled before: its units
 *   are allocated a second time, which fails, and the capture is lost;
 * - in SettlePlacement::settlesNothing(), leave out `captured`, `partially_refunded` or
 *   `refunded`, one at a time: for the first two the units are allocated a second time, which
 *   fails and loses the kept capture; for the last the placement's kept answer is rewritten to a
 *   late approval;
 * - in SettlePlacement::settlesNothing(), exempt an authorization again, as it once was: a second
 *   authorization of an authorized, captured or partly refunded payment allocates its units a
 *   second time, which fails, and the kept authorization is lost.
 *
 * @since 0.2.0
 */
final class QueriedOperationTest extends WebhookTestCase {

	use PlacementSnapshots;
	use ScriptedQueries;

	/**
	 * Tests that two payments waiting on the shopper, the first of which the provider reports captured, are both asked about in one pass: the capture is kept for a person, and the second is settled as the stand-in answers.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_waiting_on_the_shopper_is_kept_and_the_run_goes_on(): void {
		$first  = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-first' );
		$second = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-second' );

		$this->answerQueries( array( $first['intent_uuid'] => $this->resultAbout( $first, Operation::Capture, Outcome::Approved, 'stub-cap-' . $first['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();

		$this->assertNull( $this->kernel->get( ReconcileStalePlacements::class )->handle( array() ) );

		$this->assertSame( array( $first['intent_uuid'], $second['intent_uuid'] ), $this->asked, 'Both payments were asked about, in one pass.' );
		$this->assertSame( array( 'query', 'query' ), array_column( $this->gateway->calls, 'method' ) );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges(
			self::merged(
				self::keptForAPerson( 'first', 'capture', $this->amountOf( $first ) ),
				array( PaymentTables::INTENTS => self::change( array( 'second requires_action 0' ), array( 'second authorized 0' ) ) ),
				self::authorized( 'second', $this->amountOf( $second ) )
			),
			$before,
			array(
				'first'  => $first,
				'second' => $second,
			)
		);
		$this->assertSame( array( 'amount_mismatch', 'approved' ), array( $this->keptOutcome( $first['order_id'] ), $this->keptOutcome( $second['order_id'] ) ) );
	}

	/**
	 * Tests that a payment the gateway was still deciding, which the provider reports captured for its whole amount, is settled as a sale.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_the_gateway_was_deciding_is_a_sale(): void {
		$deciding = $this->placed( StubGateway::PENDING, 'attempt-deciding' );

		$this->assertSame( 'processing', $deciding['status'] );

		$this->answerQueries( array( $deciding['intent_uuid'] => $this->resultAbout( $deciding, Operation::Capture, Outcome::Approved, 'stub-cap-' . $deciding['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ) );
		$this->assertChanges( self::sold( 'deciding', $this->amountOf( $deciding ) ), $before, array( 'deciding' => $deciding ) );
		$this->assertSame( 'approved', $this->keptOutcome( $deciding['order_id'] ), 'A retry of the placement is told it was accepted.' );
	}

	/**
	 * Tests that a refund the provider reports for a payment waiting on the shopper, which holds no capture, is kept for a person; and that a declined one is recorded and moves nothing else.
	 *
	 * @since 0.2.0
	 */
	public function test_a_refund_of_a_payment_waiting_on_the_shopper_is_kept_and_a_declined_one_moves_nothing(): void {
		$refunded = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-refunded' );
		$declined = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-declined' );

		$this->answerQueries(
			array(
				$refunded['intent_uuid'] => $this->resultAbout( $refunded, Operation::Refund, Outcome::Approved, 'stub-re-' . $refunded['intent_uuid'] ),
				$declined['intent_uuid'] => $this->resultAbout( $declined, Operation::Refund, Outcome::Declined, 'stub-re-' . $declined['intent_uuid'], StubGateway::CARD_DECLINED ),
			)
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array( $refunded['intent_uuid'], $declined['intent_uuid'] ), $this->asked );
		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ) );
		$this->assertChanges(
			self::merged(
				self::keptForAPerson( 'refunded', 'refund', $this->amountOf( $refunded ) ),
				array( PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'declined', 'refund:declined:1', $this->amountOf( $declined ) ) ) ) )
			),
			$before,
			array(
				'refunded' => $refunded,
				'declined' => $declined,
			)
		);
		$this->assertSame( array( 'amount_mismatch', 'requires_action' ), array( $this->keptOutcome( $refunded['order_id'] ), $this->keptOutcome( $declined['order_id'] ) ), 'The declined refund leaves the placement waiting for its shopper.' );
	}

	/**
	 * Tests that the shopper's resume of a payment the provider reports captured keeps the capture for a person, and tells the shopper so.
	 *
	 * @since 0.2.0
	 */
	public function test_a_resume_of_a_payment_the_provider_reports_captured_keeps_it_for_a_person(): void {
		$resumed = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-resumed' );

		$this->answerQueries( array( $resumed['intent_uuid'] => $this->resultAbout( $resumed, Operation::Capture, Outcome::Approved, 'stub-cap-' . $resumed['intent_uuid'] ) ) );

		$before = $this->takeSnapshot();
		$answer = $this->kernel->get( ResumePayment::class )->resume( array( 'intent_uuid' => $resumed['intent_uuid'] ), self::guest() );

		$this->assertSame(
			array(
				'intent_uuid'    => $resumed['intent_uuid'],
				'outcome'        => 'amount_mismatch',
				'status'         => 'on_hold',
				'payment_status' => 'pending',
			),
			$answer
		);
		$this->assertChanges( self::keptForAPerson( 'resumed', 'capture', $this->amountOf( $resumed ) ), $before, array( 'resumed' => $resumed ) );
	}

	/**
	 * Tests that a capture reported for a payment whose authorization landed while the run was asking, and settled the placement, moves the money and nothing of the placement a second time.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_a_payment_its_authorization_settled_meanwhile_moves_only_the_money(): void {
		$raced = $this->placed( StubGateway::REQUIRES_ACTION, 'attempt-raced' );

		$this->answerQueries(
			array(
				$raced['intent_uuid'] => function () use ( $raced ): GatewayResult {
					// The shopper's approval is delivered as the run asks, and the provider captured it at once.
					$this->deliver( StubWebhooks::of( $this->resultAbout( $raced, Operation::Authorize, Outcome::Approved, 'stub-ch-' . $raced['intent_uuid'] ) ) );

					return $this->resultAbout( $raced, Operation::Capture, Outcome::Approved, 'stub-cap-' . $raced['intent_uuid'] );
				},
			)
		);

		$before = $this->takeSnapshot();

		$this->kernel->get( ReconcileStalePlacements::class )->handle( array() );

		$this->assertSame( array(), $this->logged( ReconcileStalePlacements::DEFERRED ), 'Nothing was deferred.' );
		$this->assertChanges(
			array(
				PaymentTables::INTENTS      => self::change( array( 'raced requires_action 0' ), array( 'raced captured ' . $this->amountOf( $raced ) ) ),
				PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'raced', 'authorize:approved:1', $this->amountOf( $raced ) ), self::ledgerRow( 'raced', 'capture:approved:1', $this->amountOf( $raced ) ) ) ),
				OrderTables::ORDERS         => self::change( array( 'raced pending_payment/pending/0' ), array( 'raced processing/paid/0' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'raced order:pending_payment>processing:payment_approved', 'raced payment:authorized>paid:payment_captured', 'raced payment:pending>authorized:payment_authorized' ) ),
				OutboxTable::NAME           => self::change( array(), array( 'raced order_placed', 'raced order_status_changed', 'raced payment_authorized', 'raced payment_captured', 'raced payment_status_changed', 'raced payment_status_changed', 'raced stock_allocated' ) ),
			) + self::allocated( 'raced' ),
			$before,
			array( 'raced' => $raced )
		);
		$this->assertSame( 'approved', $this->keptOutcome( $raced['order_id'] ) );
	}

	/**
	 * Tests that a second authorization, a capture or a refund the provider reports, of an object of its own, for a payment its authorization settled before and that was captured, partly refunded or refunded since, is kept for a person by the payment path and settles nothing of the placement.
	 *
	 * An answer the run asked for while the payment waited may land so, after the payment moved
	 * on; it is applied here as the run applies its answers. No unit is allocated a second time,
	 * and the order's stock, promotion use, cart and kept answer stay as they were: only the money
	 * path's rows change.
	 *
	 * @since 0.2.0
	 *
	 * @dataProvider data_settled_before
	 *
	 * @param string    $state         The intent's state when the answer lands.
	 * @param Operation $operation     What the provider reports.
	 * @param string    $paymentStatus The order's payment status when the answer lands, which keeping it leaves.
	 */
	public function test_an_answer_about_a_payment_settled_before_moves_only_the_money( string $state, Operation $operation, string $paymentStatus ): void {
		$settled = $this->placedAndSettledAs( $state );

		$this->assertSame( array( 'processing', $paymentStatus ), array( $this->orderState( $settled['order_id'] )['status'], $this->orderState( $settled['order_id'] )['payment_status'] ) );

		$kept   = $this->keptOutcome( $settled['order_id'] );
		$before = $this->takeSnapshot();
		$again  = $this->kernel->get( SettlePlacement::class )->apply( $this->resultAbout( $settled, $operation, Outcome::Approved, 'stub-' . $operation->value . '-again-' . $settled['intent_uuid'] ), SettlePlacement::store() );

		$this->assertSame( array( PlacementOutcome::Duplicate, ApplicationKind::Mismatch ), array( $again->outcome, $again->application?->kind ), 'The payment path kept the answer for a person, and the placement settled nothing.' );
		$this->assertChanges(
			array(
				PaymentTables::TRANSACTIONS => self::change( array(), array( self::ledgerRow( 'settled', $operation->value . ':approved:0', $this->amountOf( $settled ), 'stub-' . $operation->value . '-again-{settled}' ) ) ),
				OrderTables::ORDERS         => self::change( array( 'settled processing/' . $paymentStatus . '/0' ), array( 'settled on_hold/' . $paymentStatus . '/1' ) ),
				OrderTables::EVENTS         => self::change( array(), array( 'settled order:processing>on_hold:' . PaymentService::UNEXPECTED_RESULT ) ),
				OutboxTable::NAME           => self::change( array(), array( 'settled order_status_changed' ) ),
			),
			$before,
			array( 'settled' => $settled )
		);
		$this->assertSame( $kept, $this->keptOutcome( $settled['order_id'] ), 'A retry of the placement is told what it was told before.' );
	}

	/**
	 * Returns the states of a payment its authorization settled before, each with what the provider then reports and the order's payment status then.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, array{0: string, 1: Operation, 2: string}> The cases.
	 */
	public static function data_settled_before(): array {
		return array(
			'authorized, authorized again'      => array( 'authorized', Operation::Authorize, 'authorized' ),
			'captured, authorized again'        => array( 'captured', Operation::Authorize, 'paid' ),
			'partly refunded, authorized again' => array( 'partially_refunded', Operation::Authorize, 'partially_refunded' ),
			'captured, captured again'          => array( 'captured', Operation::Capture, 'paid' ),
			'partly refunded, captured again'   => array( 'partially_refunded', Operation::Capture, 'partially_refunded' ),
			'refunded, refunded again'          => array( 'refunded', Operation::Refund, 'refunded' ),
		);
	}
}
