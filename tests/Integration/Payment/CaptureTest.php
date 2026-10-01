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

use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentCaptured;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Platform\Database\Exception\ForbiddenInsideTransaction;
use SEOCart\Support\Error\CodedException;
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
 *   intent never authorized.
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
			'authorize'    => fn() => $this->payments->authorize( $intent->uuid, array() ),
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
			$this->db->transaction( fn() => $this->gateway->authorize( new PaymentRequest( $intent->uuid, $intent->amount, StubGateway::APPROVE ) ) );
			$this->fail( 'A gateway request was sent inside a transaction.' );
		} catch ( ForbiddenInsideTransaction $refused ) {
			$this->assertSame( ForbiddenInsideTransaction::KIND_HTTP, $refused->kind() );
			$this->assertSame( 'gateway.example.test', $refused->detail() );
		}
	}
}
