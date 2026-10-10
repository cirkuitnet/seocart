<?php
/**
 * Tests voiding a payment: who may, what can be voided, and what a void does to the money, the order and the ledger
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Payment;

use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentVoided;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Payment\Infrastructure\Gateway\StubGateway;
use SEOCart\Payment\Infrastructure\PaymentTables;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\AuthorizationError;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Payment\PaymentTestCase;

/**
 * A void cancels an authorization before anything is captured: the gateway is asked at depth 0, its answer is applied once through the one money path, and no money moves.
 *
 * The void's ledger row records the amount the intent authorized, with its frozen base amount,
 * applied; the intent keeps what it authorized and records why it was voided; the order's payment
 * status derives voided. An order still pending payment is cancelled; an order accepted is left to
 * the person who voided it, or parked for one when nobody asked. A captured intent is never
 * voided: what was captured goes back by a refund.
 *
 * Planted violations, each shown red and removed:
 * - in PaymentService::applyGatewayResult(), refuse a void with a \LogicException, as before: no
 *   void is applied;
 * - in Projection::status(), drop the test of a voided intent: the voided order's payment status
 *   reads authorized;
 * - in PaymentService::askVoid(), skip the state check: the captured intent's void reaches the
 *   gateway;
 * - in PaymentService::voided(), park every accepted order: the order a person voided is put on hold.
 *
 * @since 0.2.0
 */
final class VoidTest extends PaymentTestCase {

	/**
	 * Tests that a person's void of an authorized intent releases it at the gateway and moves no money, leaving the accepted order to them.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_after_authorize_moves_no_money_and_leaves_the_order_to_the_person(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$manager     = $this->userWithRole( 'seocart_manager' );
		$application = $this->payments->void( $intent->uuid, $manager, VoidReason::CustomerRequest );

		$this->assertSame( ApplicationKind::Applied, $application->kind );
		$this->assertSame( array( IntentStatus::Authorized, IntentStatus::Voided ), array( $application->intentFrom, $application->intentTo ) );
		$this->assertSame( array( PaymentStatus::Authorized, PaymentStatus::Voided ), array( $application->paymentFrom, $application->paymentTo ) );
		$this->assertNull( $application->orderStatusTo, 'The accepted order is left to the person who voided it.' );
		$this->assertSame( array( array( 'authorize', 0 ), array( 'void', 0 ) ), array_map( static fn( array $call ): array => array( $call['method'], $call['depth'] ), $this->gateway->calls ) );

		$void = $this->ledgerOf( $order->id )[1];

		$this->assertSame(
			array( 'void', 'approved', '1', '3080', 'EUR', '2464', 'USD', 'stub-void-' . $intent->uuid, 'user', (string) $manager->userId() ),
			array( $void['operation'], $void['result'], (string) $void['applied'], (string) $void['amount_minor'], $void['currency'], (string) $void['base_amount_minor'], $void['base_currency'], $void['provider_object_id'], $void['actor_type'], (string) $void['actor_id'] ),
			'The void records what the intent authorized, with its frozen base amount.'
		);
		$this->assertSame( array( 'voided', 'customer_request', '3080', '0' ), array_values( self::pick( $this->intentRow( $intent->uuid ), 'status', 'voided_reason', 'authorized_minor', 'captured_minor' ) ) );
		$this->assertSame( array( 'processing', 'voided', '3080', '0', '3080', '0' ), array_map( 'strval', array_values( self::pick( $this->orderRow( $order->id ), 'status', 'payment_status', 'authorized_minor', 'paid_minor', 'due_minor', 'has_unreconciled_money' ) ) ) );
		$this->assertContains( 'payment:authorized>voided:payment_voided', $this->eventsOf( $order->id ) );
		$this->assertSame( 1, $this->outboxRows( PaymentVoided::eventName() ) );
		$this->assertSame(
			array(
				'amount_minor' => 3080,
				'currency'     => 'EUR',
				'reason'       => 'customer_request',
			),
			array_intersect_key( $this->latestPayload( PaymentVoided::eventName() ), array_flip( array( 'amount_minor', 'currency', 'reason' ) ) )
		);

		$this->assertRefused( PaymentError::NotVoidable, array( 'status' => 'voided' ), fn() => $this->payments->void( $intent->uuid, $manager, VoidReason::CustomerRequest ), 'A voided intent is not voided again.' );
		$this->assertSame( 1, $this->voidCalls(), 'The gateway was asked to void once.' );
	}

	/**
	 * Tests that the store's void of an intent whose shopper never acted cancels the order still pending payment, on no user's authority.
	 *
	 * @since 0.2.0
	 */
	public function test_the_stores_void_of_a_waiting_intent_cancels_the_pending_order(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::REQUIRES_ACTION ) );

		$application = $this->payments->void( $intent->uuid, self::store(), VoidReason::ActionWindowEnded );

		$this->assertSame( array( ApplicationKind::Applied, IntentStatus::RequiresAction, OrderStatus::Cancelled, PaymentStatus::Voided ), array( $application->kind, $application->intentFrom, $application->orderStatusTo, $application->paymentTo ) );
		$this->assertSame( array( 'voided', 'action_window_ended' ), array_values( self::pick( $this->intentRow( $intent->uuid ), 'status', 'voided_reason' ) ) );
		$this->assertSame( array( 'system', null ), array_values( self::pick( $this->ledgerOf( $order->id )[0], 'actor_type', 'actor_id' ) ), 'The store acts on no user\'s authority, and is recorded so.' );
		$this->assertSame( array( 'cancelled', 'voided', '0', '0' ), array_map( 'strval', array_values( self::pick( $this->orderRow( $order->id ), 'status', 'payment_status', 'authorized_minor', 'has_unreconciled_money' ) ) ) );
		$this->assertContains( 'order:pending_payment>cancelled:action_window_ended', $this->eventsOf( $order->id ) );
	}

	/**
	 * Tests that a void nobody asked for, of an order already accepted, parks the order for a person and flags it: its approval landed while the shopper's time ran out.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_nobody_asked_for_parks_an_accepted_order(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$application = $this->payments->void( $intent->uuid, self::store(), VoidReason::ActionWindowEnded );

		$this->assertSame( array( ApplicationKind::Applied, OrderStatus::OnHold ), array( $application->kind, $application->orderStatusTo ) );
		$this->assertSame( array( 'on_hold', 'voided', '1' ), array_map( 'strval', array_values( self::pick( $this->orderRow( $order->id ), 'status', 'payment_status', 'has_unreconciled_money' ) ) ) );
		$this->assertContains( 'order:processing>on_hold:' . PaymentService::VOIDED_AFTER_APPROVAL, $this->eventsOf( $order->id ) );
	}

	/**
	 * Tests that what cannot be voided is refused before the gateway is asked: a captured intent, one not yet authorized, one waiting for its shopper unless the store ends its time, and one the gateway is still deciding.
	 *
	 * @since 0.2.0
	 */
	public function test_what_cannot_be_voided_is_refused_before_the_gateway_is_asked(): void {
		$manager            = $this->userWithRole( 'seocart_manager' );
		list( , $captured ) = $this->placeCaptured();
		list( , $created )  = $this->placeWithIntent();
		list( , $waiting )  = $this->placeWithIntent();
		list( , $deciding ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $waiting, StubGateway::REQUIRES_ACTION ) );
		$this->deliver( $this->authorizeWith( $deciding, StubGateway::PENDING ) );

		$refusals = array(
			'a captured intent'                       => array( $captured->uuid, $manager, VoidReason::CustomerRequest, 'captured' ),
			'an intent not yet authorized'            => array( $created->uuid, $manager, VoidReason::CustomerRequest, 'created' ),
			'a waiting intent, by a person'           => array( $waiting->uuid, $manager, VoidReason::ActionWindowEnded, 'requires_action' ),
			'a waiting intent, for another reason'    => array( $waiting->uuid, self::store(), VoidReason::Other, 'requires_action' ),
			'an intent the gateway is still deciding' => array( $deciding->uuid, self::store(), VoidReason::ActionWindowEnded, 'processing' ),
		);

		foreach ( $refusals as $what => list( $uuid, $actor, $reason, $status ) ) {
			$this->assertRefused( PaymentError::NotVoidable, array( 'status' => $status ), fn() => $this->payments->askVoid( $uuid, $actor, $reason ), $what );
		}

		$this->assertSame( 0, $this->voidCalls(), 'The gateway was never asked to void.' );
		$this->assertSame( 'captured', $this->intentRow( $captured->uuid )['status'] );
	}

	/**
	 * Tests that a user without the void capability is refused before the intent is read, and the gateway is not asked.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_without_the_capability_is_refused_before_any_read(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$agent = $this->userWithRole();
		$log   = $this->captureQueries( fn() => $this->assertRefused( AuthorizationError::Denied, array( 'capability' => PaymentService::VOID_CAPABILITY ), fn() => $this->payments->void( $intent->uuid, $agent, VoidReason::CustomerRequest ), 'An order agent captures, but does not void.' ) );

		$this->assertQueryCount( 0, $log->forTable( $this->table( PaymentTables::INTENTS ) ), 'reads of the intent before the refusal' );
		$this->assertSame( 0, $this->voidCalls() );
	}

	/**
	 * Tests that an intent with a result a person must reconcile is not voided, and the gateway is not asked.
	 *
	 * @since 0.2.0
	 */
	public function test_an_intent_with_money_to_reconcile_is_not_voided(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );
		$this->deliver( self::stubResult( $intent, Operation::Authorize, Outcome::Approved, 3080, 'EUR', 'stub-ch-second-charge' ) );

		$this->assertRefused( PaymentError::Unreconciled, array(), fn() => $this->payments->void( $intent->uuid, $this->userWithRole( 'seocart_manager' ), VoidReason::Fraud ), 'A second charge is kept for a person.' );
		$this->assertSame( 0, $this->voidCalls() );
	}

	/**
	 * Tests that a gateway that refuses to cancel leaves the intent as it was: a declined void is stale, never applied as a decline.
	 *
	 * @since 0.2.0
	 */
	public function test_a_declined_void_changes_nothing(): void {
		list( $order, $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$this->gateway->voids = static fn( VoidRequest $request ): GatewayResult => new GatewayResult( StubGateway::ID, Operation::Void, Outcome::Declined, $request->intentUuid, $request->amount, 'stub-void-' . $request->intentUuid, $request->providerIntentId, 'intent_not_cancelable' );
		$before               = $this->snapshot();

		$this->assertSame( ApplicationKind::Stale, $this->payments->void( $intent->uuid, $this->userWithRole( 'seocart_manager' ), VoidReason::CustomerRequest )->kind );
		$this->assertSame( $before, $this->snapshot(), 'The intent stays authorized, the order accepted, and the ledger has no row of the refusal.' );
		$this->assertSame( 'authorized', $this->intentRow( $intent->uuid )['status'] );
		$this->assertCount( 1, $this->ledgerOf( $order->id ) );
	}

	/**
	 * Tests that the same void delivered again is a duplicate, and a void for an intent that failed is stale: neither writes anything.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_delivered_again_or_for_a_failed_intent_changes_nothing(): void {
		list( , $voided ) = $this->placeWithIntent();
		list( , $failed ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $voided, StubGateway::APPROVE ) );
		$this->deliver( $this->authorizeWith( $failed, StubGateway::DECLINE ) );

		$void = $this->payments->askVoid( $voided->uuid, $this->userWithRole( 'seocart_manager' ), VoidReason::Other );

		$this->assertSame( ApplicationKind::Applied, $this->deliverVoid( $void )->kind );

		$before = $this->snapshot();

		$this->assertSame( ApplicationKind::Duplicate, $this->deliverVoid( $void )->kind, 'The same void again.' );
		$this->assertSame( ApplicationKind::Stale, $this->deliverVoid( self::stubResult( $failed, Operation::Void, Outcome::Approved, 3080, 'EUR', 'stub-void-' . $failed->uuid ) )->kind, 'A failed intent has nothing to release.' );
		$this->assertSame( $before, $this->snapshot() );
	}

	/**
	 * Tests that an approved void is applied only with the reason it was asked for, which the provider's answer does not carry, before any statement.
	 *
	 * @since 0.2.0
	 */
	public function test_a_void_is_applied_only_with_its_reason(): void {
		list( , $intent ) = $this->placeWithIntent();

		$void = self::stubResult( $intent, Operation::Void, Outcome::Approved, 3080, 'EUR', 'stub-void-' . $intent->uuid );
		$log  = $this->captureQueries(
			function () use ( $void ): void {
				try {
					$this->deliver( $void );
					$this->fail( 'A void was applied without its reason.' );
				} catch ( \LogicException $refused ) {
					$this->assertStringContainsString( 'reason it was asked for', $refused->getMessage() );
				}
			}
		);

		$this->assertQueryCount( 0, $log->ofType( 'SELECT', 'INSERT', 'UPDATE', 'DELETE' ), 'statements before the refusal' );
	}

	/**
	 * Tests that the void operation's service takes only a reason a merchant may give, even when called past the schema that refuses the rest, and never repeats a reason it refuses.
	 *
	 * Planted violation, shown red and removed: in PaymentService::voidPayment(), take the reason with
	 * VoidReason::from() alone: the store's own reason voids the payment, and the card number is
	 * repeated in the enum's error.
	 *
	 * @since 0.2.0
	 */
	public function test_the_operation_takes_only_a_merchants_reason(): void {
		list( , $intent ) = $this->placeWithIntent();

		$this->deliver( $this->authorizeWith( $intent, StubGateway::APPROVE ) );

		$manager = $this->userWithRole( 'seocart_manager' );

		foreach ( array( VoidReason::ActionWindowEnded->value, '4111111111111111' ) as $reason ) {
			try {
				$this->payments->voidPayment(
					array(
						'intent_uuid' => $intent->uuid,
						'reason'      => $reason,
					),
					$manager
				);
				$this->fail( 'A void was asked for with the reason ' . $reason . '.' );
			} catch ( \InvalidArgumentException $refused ) {
				$this->assertStringNotContainsString( $reason, $refused->getMessage() );
			}
		}

		$this->assertSame( 0, $this->voidCalls() );
		$this->assertSame( 'authorized', $this->intentRow( $intent->uuid )['status'] );
	}

	/**
	 * Applies a void's answer in a transaction of its own, with its reason.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The answer.
	 * @return Application What was done.
	 */
	private function deliverVoid( GatewayResult $result ): Application {
		return $this->db->transaction( fn() => $this->payments->applyGatewayResult( $result, self::system(), null, VoidReason::Other ) );
	}

	/**
	 * Returns the store itself as an actor: a process acting on no user's authority.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The actor.
	 */
	private static function store(): Actor {
		return Actor::system( 'reconciliation', 0 );
	}

	/**
	 * Counts the voids the gateway was asked for.
	 *
	 * @since 0.2.0
	 *
	 * @return int The count.
	 */
	private function voidCalls(): int {
		return count( array_filter( $this->gateway->calls, static fn( array $call ): bool => 'void' === $call['method'] ) );
	}

	/**
	 * Asserts that a call is refused with a code and a context.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentError|AuthorizationError $code    The code.
	 * @param array<string, mixed>            $context The context.
	 * @param \Closure                        $call    The call.
	 * @param string                          $what    What is refused.
	 */
	private function assertRefused( PaymentError|AuthorizationError $code, array $context, \Closure $call, string $what ): void {
		try {
			$call();
			$this->fail( $what . ': not refused.' );
		} catch ( CodedException $refused ) {
			$this->assertSame( array( $code, $context ), array( $refused->errorCode(), $refused->context() ), $what );
		}
	}
}
