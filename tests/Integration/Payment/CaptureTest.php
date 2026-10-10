<?php
/**
 * Tests explicit capture, and that no gateway is ever called inside a transaction
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentCaptured;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Doubles\DeclaredGateway;

use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * A payment is captured only when someone with the capability asks, and the gateway is called only outside any transaction.
 *
 * Capture checks the capability before it reads anything, reads the intent to spare the gateway a
 * pointless call, calls the gateway at depth 0, and applies the answer in a transaction of its
 * own through the one money path. The RecordingGateway records each call's depth.
 *
 * Planted violations, each shown red and removed:
 * - in Orders::park(), transition whatever the order's status, as before: the capture on the order
 *   held for stock is refused with `order.transition_illegal`, its row goes, and the second
 *   capture asks the gateway again;
 * - in PaymentService::capture(), skip the capability check: the subscriber captures;
 * - in PaymentService::capture(), call the gateway inside the transaction that applies its answer:
 *   the gateway records the call at depth 1;
 * - in PaymentService::capture(), skip the intent's status check: the gateway is called for an
 *   intent never authorized;
 * - in PaymentService::requireCapturable(), ask the matrix for `capture` whatever the amount: the
 *   capture of part reaches a gateway that does not declare it;
 * - in AmountCheck::capturable(), take only the intent's whole amount, as before: the capture of
 *   part is parked as a mismatch;
 * - in PaymentService::baseAmount(), take a capture's base amount as its amount in the order's
 *   currency: the base figures of the capture of part are wrong.
 *
 * @since 0.1.0
 */
final class CaptureTest extends PaymentTestCase {

	/**
	 * Tests that capturing an authorized intent calls the gateway at depth 0 and moves the captured money to the intent and the order.
	 *
	 * @since 0.1.0
	 */
	public function test_capture_takes_the_authorized_amount_and_calls_the_gateway_outside_any_transaction(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$application = $this->payments->capture( $intent->uuid, $this->userWithRole() );

		$this->assertSame( ApplicationKind::Applied, $application->kind );
		$this->assertSame( array( IntentStatus::Authorized, IntentStatus::Captured ), array( $application->intentFrom, $application->intentTo ) );
		$this->assertSame( array( PaymentStatus::Authorized, PaymentStatus::Paid ), array( $application->paymentFrom, $application->paymentTo ) );
		$this->assertNull( $application->orderStatusTo, 'Capturing an accepted order changes no order status.' );
		$this->assertSame(
			array(
				array(
					'method' => 'authorize',
					'depth'  => 0,
				),
				array(
					'method' => 'capture',
					'depth'  => 0,
				),
			),
			$this->gateway->calls
		);

		$intentRow = $this->intentRow( $intent->uuid );
		$orderRow  = $this->orderRow( $order->id );

		$this->assertSame( array( 'captured', '3080', '2464' ), array( $intentRow['status'], (string) $intentRow['captured_minor'], (string) $intentRow['base_captured_minor'] ) );
		$this->assertSame( array( 'processing', 'paid', '3080', '0', '2464' ), array( $orderRow['status'], $orderRow['payment_status'], (string) $orderRow['paid_minor'], (string) $orderRow['due_minor'], (string) $orderRow['base_paid_minor'] ) );
		$this->assertSame( 1, $this->outboxRows( PaymentCaptured::eventName() ) );
		$this->assertContains( 'payment:authorized>paid:payment_captured', $this->eventsOf( $order->id ) );
	}

	/**
	 * Tests that a user without the capability is refused before the intent is read, and the gateway is not called.
	 *
	 * @since 0.1.0
	 */
	public function test_a_user_without_the_capability_is_refused_before_any_read(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$subscriber = $this->userWithRole( 'subscriber' );
		$log        = $this->captureQueries(
			function () use ( $intent, $subscriber ): void {
				try {
					$this->payments->capture( $intent->uuid, $subscriber );
					$this->fail( 'A subscriber captured a payment.' );
				} catch ( CodedException $refused ) {
					$this->assertSame( AuthorizationError::Denied, $refused->errorCode() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->forTable( $this->table( PaymentTables::INTENTS ) ), 'reads of the intent before the refusal' );
		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ) );
		$this->assertSame( 'authorized', $this->intentRow( $intent->uuid )['status'] );
	}

	/**
	 * Tests that an intent a mismatch left unauthorized is not captured, and the gateway is not asked.
	 *
	 * @since 0.1.0
	 */
	public function test_an_intent_a_mismatch_left_unauthorized_is_never_captured(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::WRONG_AMOUNT ) );

		try {
			$this->payments->capture( $intent->uuid, $this->userWithRole() );
			$this->fail( 'An intent never authorized was captured.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::NotCapturable, $refused->errorCode() );
			$this->assertSame( array( 'status' => 'created' ), $refused->context() );
		}

		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was never asked to capture.' );
	}

	/**
	 * Tests that a capture of the wrong amount parks the order like an authorization's, and that the intent is then not captured again.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capture_of_the_wrong_amount_parks_the_order_and_is_not_repeated(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::CAPTURE_WRONG_AMOUNT ) );

		$capturer    = $this->userWithRole();
		$application = $this->payments->capture( $intent->uuid, $capturer );

		$this->assertSame( ApplicationKind::Mismatch, $application->kind );
		$this->assertSame( OrderStatus::OnHold, $application->orderStatusTo );

		$capture = $this->ledgerOf( $order->id )[1];

		$this->assertSame( array( 'capture', '3081', '0' ), array( $capture['operation'], (string) $capture['amount_minor'], (string) $capture['applied'] ) );
		$this->assertSame( array( 'authorized', '0' ), array( $this->intentRow( $intent->uuid )['status'], (string) $this->intentRow( $intent->uuid )['captured_minor'] ) );
		$this->assertSame( array( 'on_hold', 'authorized', '0', '1' ), array_values( self::pick( $this->orderRow( $order->id ), 'status', 'payment_status', 'paid_minor', 'has_unreconciled_money' ) ) );

		try {
			$this->payments->capture( $intent->uuid, $capturer );
			$this->fail( 'An intent with a result to reconcile was captured again.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::Unreconciled, $refused->errorCode() );
		}

		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked to capture once.' );
	}

	/**
	 * Tests that a capture of the wrong amount on an order already on hold, for stock, keeps its row and flags the order, so a second capture is refused before the gateway is asked.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capture_mismatch_on_an_order_held_for_stock_keeps_its_row_and_is_not_repeated(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::CAPTURE_WRONG_AMOUNT ) );
		$this->orders->transition( $order->id, OrderStatus::OnHold, 'stock_unavailable', self::system() );

		$capturer    = $this->userWithRole();
		$application = $this->payments->capture( $intent->uuid, $capturer );

		$this->assertSame( array( ApplicationKind::Mismatch, null ), array( $application->kind, $application->orderStatusTo ), 'The order is already on hold; it does not move.' );

		$capture = $this->ledgerOf( $order->id )[1];

		$this->assertSame( array( 'capture', '3081', '0' ), array( $capture['operation'], (string) $capture['amount_minor'], (string) $capture['applied'] ), 'The wrong capture is recorded, though the order could not be parked again.' );
		$this->assertSame( array( 'on_hold', '1' ), array_values( self::pick( $this->orderRow( $order->id ), 'status', 'has_unreconciled_money' ) ) );

		try {
			$this->payments->capture( $intent->uuid, $capturer );
			$this->fail( 'An intent with a result to reconcile was captured again.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( PaymentError::Unreconciled, $refused->errorCode() );
		}

		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ), 'The gateway was asked to capture once.' );
	}

	/**
	 * Tests that every call that reaches the gateway is refused inside a transaction, before the gateway is called.
	 *
	 * @since 0.1.0
	 */
	public function test_no_gateway_call_is_made_inside_a_transaction(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$capturer = $this->userWithRole();
		$calls    = array(
			'authorize'    => fn() => $this->payments->authorize( $intent->uuid, array(), self::ORDER_UUID, self::ORDER_NUMBER ),
			'capture'      => fn() => $this->payments->capture( $intent->uuid, $capturer ),
			'queryGateway' => fn() => $this->payments->queryGateway( $intent ),
		);

		foreach ( $calls as $method => $call ) {
			try {
				$this->db->transaction( $call );
				$this->fail( "{$method}() called the gateway inside a transaction." );
			} catch ( \Throwable $refused ) {
				$this->assertInstanceOf( \LogicException::class, $refused, $method );
				$this->assertStringContainsString( 'never inside a transaction', $refused->getMessage(), $method );
			}
		}

		$this->assertSame( array( 'authorize' ), array_column( $this->gateway->calls, 'method' ), 'Only the authorization made outside a transaction reached the gateway.' );
	}

	/**
	 * Tests that a gateway making a real request inside a transaction is stopped by the transaction guard, whatever called it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_gateway_request_inside_a_transaction_is_stopped_by_the_guard(): void {
		list( , $intent ) = $this->placeWithIntent();

		add_filter(
			'pre_http_request',
			static fn(): array => array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			)
		);

		$this->gateway->during(
			static function (): void {
				wp_remote_post( 'https://gateway.example.test/v1/charges' );
			}
		);

		try {
			$this->db->transaction( fn() => $this->gateway->authorize( new PaymentRequest( $intent->uuid, $intent->amount, StubGateway::APPROVE, $intent->mode, self::ORDER_UUID, self::ORDER_NUMBER ) ) );
			$this->fail( 'A gateway request was sent inside a transaction.' );
		} catch ( ForbiddenInsideTransaction $refused ) {
			$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $refused->kind() );
			$this->assertSame( 'gateway.example.test', $refused->detail() );
		}
	}

	/**
	 * Tests that a capture of part of what was authorized, where the gateway declares it, captures that part, with its share of the frozen base amount, and leaves the order partly paid.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_of_part_takes_its_share_where_the_gateway_declares_it(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$application = $this->payments->capture( $intent->uuid, $this->userWithRole(), 1000 );

		$this->assertSame( array( ApplicationKind::Applied, PaymentStatus::PartiallyPaid ), array( $application->kind, $application->paymentTo ) );
		$this->assertSame( array( 'captured', '3080', '1000', '800' ), array_map( 'strval', array_values( self::pick( $this->intentRow( $intent->uuid ), 'status', 'authorized_minor', 'captured_minor', 'base_captured_minor' ) ) ), 'A third of 3080 EUR takes its share of the 2464 USD frozen with it: 800.' );
		$this->assertSame( array( 'capture', '1000', 'EUR', '800', 'USD' ), array_values( self::pick( $this->ledgerOf( $order->id )[1], 'operation', 'amount_minor', 'currency', 'base_amount_minor', 'base_currency' ) ) );
		$this->assertSame( array( 'processing', 'partially_paid', '1000', '2080', '800' ), array_map( 'strval', array_values( self::pick( $this->orderRow( $order->id ), 'status', 'payment_status', 'paid_minor', 'due_minor', 'base_paid_minor' ) ) ) );
		$this->assertRefused( PaymentError::NotCapturable, fn() => $this->payments->capture( $intent->uuid, $this->userWithRole(), 2080 ), 'One capture per intent: the rest is released by the provider.' );
	}

	/**
	 * Tests that a capture of part is refused where the gateway does not declare it, and a capture of more than was authorized everywhere, each before the gateway is asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_the_gateway_or_the_authorization_cannot_take_is_refused_before_the_call(): void {
		$whole    = array_values( array_diff( Operations::ALL, array( Operations::PARTIAL_CAPTURE, Operations::MULTI_CAPTURE, Operations::OFF_SESSION, Operations::WEBHOOKS ) ) );
		$declared = new DeclaredGateway( DeclaredGateway::descriptor( 'declared', array( Mode::Test ), array(), DeclaredGateway::matrix( array( 'EUR' ), $whole ) ) );
		$payments = $this->paymentsOver( $this->db, $this->ids, $declared );
		$order    = NewOrders::forTwoLines( self::CURRENCY, self::BASE );
		$intent   = $this->db->transaction(
			function () use ( $payments, $order ) {
				$inserted = $this->orders->insert( $order, Actor::user( 0 ) );

				return $payments->createIntent( $inserted->id, 'declared', Mode::Test, $order->totals->grandTotal, $order->totals->baseGrandTotal, $inserted->conversionContextId );
			}
		);

		$approval = $payments->authorize( $intent->uuid, array( PaymentService::PAYMENT_TOKEN => StubGateway::APPROVE ), self::ORDER_UUID, self::ORDER_NUMBER );

		$this->db->transaction( fn() => $payments->applyGatewayResult( $approval, self::system() ) );

		$agent = $this->userWithRole();

		$this->assertRefused(
			PaymentError::OperationUnsupported,
			fn() => $payments->capture( $intent->uuid, $agent, 1000 ),
			'A gateway that does not declare partial captures.',
			array(
				'gateway_id' => 'declared',
				'operation'  => 'partial_capture',
			)
		);
		$this->assertRefused(
			PaymentError::CaptureExceedsAuthorized,
			fn() => $payments->capture( $intent->uuid, $agent, 3081 ),
			'More than was authorized.',
			array(
				'authorized' => 3080,
				'requested'  => 3081,
			)
		);
		$this->assertSame( array( 'authorize' ), array_column( $declared->calls, 'method' ), 'The gateway was never asked to capture.' );
		$this->assertSame( ApplicationKind::Applied, $payments->capture( $intent->uuid, $agent )->kind, 'The whole amount is declared.' );
	}

	/**
	 * Tests that a capture asked again after it was made is refused with what was captured, so a client reads it as done, and the gateway is not asked again.
	 *
	 * @since 0.2.0
	 */
	public function test_a_capture_asked_again_reads_as_done(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );
		$this->payments->capture( $intent->uuid, $this->userWithRole() );

		try {
			$this->payments->capture( $intent->uuid, $this->userWithRole() );
			$this->fail( 'A captured intent was captured again.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( PaymentError::NotCapturable, array( 'status' => 'captured' ) ), array( $refused->errorCode(), $refused->context() ) );
			$this->assertSame(
				array(
					'captured' => 3080,
					'currency' => 'EUR',
				),
				$refused->details()
			);
		}

		$this->assertSame( array( 'authorize', 'capture' ), array_column( $this->gateway->calls, 'method' ) );
	}

	/**
	 * Tests that a gateway that does not answer, or declines, leaves the capture unrecorded: nothing applied for no answer, the intent failed for a decline, and the accepted order left to a person.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable Only from the gateway the test scripts, which the test catches.
	 */
	public function test_a_capture_without_an_answer_records_nothing_and_a_declined_one_fails_the_intent(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$this->gateway->captures = static fn(): GatewayResult => throw new GatewayUnavailable( 'The capture never reached the gateway.' );
		$before                  = $this->snapshot();

		try {
			$this->payments->capture( $intent->uuid, $this->userWithRole() );
			$this->fail( 'A capture with no answer was applied.' );
		} catch ( GatewayUnavailable $unavailable ) {
			$this->assertSame( $before, $this->snapshot(), 'Nothing was recorded.' );
		}

		$this->gateway->captures = static fn( CaptureRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Capture, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-cap-' . $request->intentUuid, $request->providerIntentId, 'card_declined' );

		$this->assertSame( ApplicationKind::Declined, $this->payments->capture( $intent->uuid, $this->userWithRole() )->kind );
		$this->assertSame( array( 'failed', 'processing' ), array( $this->intentRow( $intent->uuid )['status'], $this->orderRow( $order->id )['status'] ), 'A declined capture fails the intent and leaves the accepted order to a person.' );
	}

	/**
	 * Asserts that a call is refused with a code, and a context when one is given.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentError              $code    The code.
	 * @param \Closure                  $call    The call.
	 * @param string                    $what    What is refused.
	 * @param array<string, mixed>|null $context Optional. The context. Default not checked.
	 */
	private function assertRefused( PaymentError $code, \Closure $call, string $what, ?array $context = null ): void {
		try {
			$call();
			$this->fail( $what . ': not refused.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( $code, $refused->errorCode(), $what );

			if ( null !== $context ) {
				$this->assertSame( $context, $refused->context(), $what );
			}
		}
	}
}
