<?php
/**
 * PaymentService: creates payment intents, calls the gateway, and applies what it answered
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\AmountCheck;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentCaptured;
use SEOCart\Payment\Domain\Event\PaymentFailed;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Domain\Event\PaymentStatusChanged;
use SEOCart\Payment\Domain\Gateway\CaptureRequest;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Gateway\PaymentQuery;
use SEOCart\Payment\Domain\Gateway\PaymentRequest;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\Operation;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Payment\Domain\Projection;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;
use SEOCart\Support\Money;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The payment module's application service: every intent is created, and every gateway result applied, through it.
 *
 * Owns one fact: the one path money takes. A gateway is called only outside any transaction,
 * and what it answered is applied afterwards by applyGatewayResult(), inside the caller's
 * transaction: the intent and the order are locked, the result is appended to the ledger, which
 * claims it, and then the intent, the order's payment amounts and the order's status move, each
 * by one conditional statement, with their events. A result is applied once, whichever way it
 * arrives: the redirect, a webhook, or reconciliation asking the gateway.
 *
 * An approval that does not match its intent's frozen amount and currency, or would tender more
 * than the order's grand total, moves no money: its ledger row is kept with `applied = 0`, the
 * intent stays as it was, and the order is flagged for a person and put `on_hold` where its
 * status allows; the row is kept whatever the order's status. A result the intent's
 * state cannot take is refused, and its ledger row goes back with the savepoint it was written
 * in. Stock, promotion usage and the cart are the caller's, settled by the Application's kind in
 * the same transaction.
 *
 * @since 0.1.0
 */
final class PaymentService {

	/**
	 * The capability a capture needs.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPTURE_CAPABILITY = 'seocart_capture_payments';

	/**
	 * How long a customer asked to act, for example to confirm with their bank, has to do it, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ACTION_WINDOW_SECONDS = 900;

	/**
	 * The key of the customer's payment token in the payment data a placement passes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_TOKEN = 'payment_token';

	/**
	 * The reason an order is parked with when a payment does not match it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const AMOUNT_MISMATCH = 'amount_mismatch';

	/**
	 * The reason a pending order fails with, and its payment status changes with, when the gateway declines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_DECLINED = 'payment_declined';

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentRepository
	 */
	private PaymentRepository $payments;

	/**
	 * The gateway.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $gateway;

	/**
	 * The orders the payments are for.
	 *
	 * @since 0.1.0
	 *
	 * @var Orders
	 */
	private Orders $orders;

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Publishes the events.
	 *
	 * @since 0.1.0
	 *
	 * @var EventPublisher
	 */
	private EventPublisher $events;

	/**
	 * Checks capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @var Authorizer
	 */
	private Authorizer $authorizer;

	/**
	 * Mints intent uuids.
	 *
	 * @since 0.1.0
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Says when an event happened.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * The request's correlation id, which a ledger row and its events share.
	 *
	 * @since 0.1.0
	 *
	 * @var CorrelationId
	 */
	private CorrelationId $correlation;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentRepository  $payments    The statements.
	 * @param PaymentGateway     $gateway     The gateway.
	 * @param Orders             $orders      The orders the payments are for.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param Authorizer         $authorizer  Checks capabilities.
	 * @param IdGenerator        $ids         Mints intent uuids.
	 * @param Clock              $clock       Says when an event happened.
	 * @param CorrelationId      $correlation The request's correlation id.
	 */
	public function __construct( PaymentRepository $payments, PaymentGateway $gateway, Orders $orders, TransactionManager $tx, EventPublisher $events, Authorizer $authorizer, IdGenerator $ids, Clock $clock, CorrelationId $correlation ) {
		$this->payments    = $payments;
		$this->gateway     = $gateway;
		$this->orders      = $orders;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->authorizer  = $authorizer;
		$this->ids         = $ids;
		$this->clock       = $clock;
		$this->correlation = $correlation;
	}

	/**
	 * Creates an order's intent, with its amounts frozen, inside the caller's transaction.
	 *
	 * One insert and PaymentIntentCreated. The amounts are the caller's: the grand total and the
	 * base grand total of the order's totals, for its one tender. The gateway is not called.
	 * Outside a transaction it throws a \LogicException, before any statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the gateway is not the one installed, or an amount is not positive; before any statement.
	 *
	 * @param int    $orderId             The order, inserted in this transaction.
	 * @param string $gatewayId           The gateway to pay through: the installed gateway's id.
	 * @param Money  $amount              The amount, in the order's currency.
	 * @param Money  $baseAmount          The amount in the base currency, at the order's rate.
	 * @param int    $conversionContextId The order's rate.
	 * @return IntentRef The intent, created.
	 */
	public function createIntent( int $orderId, string $gatewayId, Money $amount, Money $baseAmount, int $conversionContextId ): IntentRef {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( $gatewayId !== $this->gateway->id() ) {
			throw new \InvalidArgumentException( sprintf( 'An intent is paid through the installed gateway, %s.', $this->gateway->id() ) );
		}

		if ( ! self::isPositive( $amount ) || ! self::isPositive( $baseAmount ) ) {
			throw new \InvalidArgumentException( 'An intent is for a positive amount, in both currencies.' );
		}

		$uuid     = $this->ids->generate();
		$intentId = $this->payments->insertIntent( $orderId, $uuid, $gatewayId, $amount, $baseAmount, $conversionContextId );

		$this->events->publish( new PaymentIntentCreated( $intentId, $uuid, $orderId, $gatewayId, $amount->minorUnits(), $amount->currency()->code(), $this->clock->now() ) );

		return new IntentRef( $uuid, $orderId, IntentStatus::Created, null, $amount );
	}

	/**
	 * Asks the gateway to authorize an intent, outside any transaction, and returns its answer unapplied.
	 *
	 * The caller applies the answer with applyGatewayResult() in a transaction of its own. The
	 * payment data is what the customer's browser sent; the gateway reads its payment token, and a
	 * missing token is the gateway's to decline, as a provider declines a token it cannot use.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException     Inside a transaction, before any statement.
	 * @throws CodedException      `payment.intent_not_found`.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param string               $intentUuid  The intent.
	 * @param array<string, mixed> $paymentData What the customer's browser sent: `payment_token`, the gateway's token for their payment method.
	 * @return GatewayResult The gateway's answer.
	 */
	public function authorize( string $intentUuid, array $paymentData ): GatewayResult {
		$this->requireNoTransaction( __FUNCTION__ );

		$intent = $this->find( $intentUuid );
		$token  = $paymentData[ self::PAYMENT_TOKEN ] ?? '';

		return $this->gateway->authorize( new PaymentRequest( $intent->uuid, $intent->amount, is_string( $token ) ? $token : '' ) );
	}

	/**
	 * Applies one gateway result, inside the caller's transaction: the single path money takes.
	 *
	 * Runs in a savepoint of the caller's transaction, so a refused result leaves nothing behind
	 * even when the caller goes on. An approval or a decline is a money fact: the intent and then
	 * the order are locked, and the ledger row is appended, which claims the result; a row already
	 * there makes this a duplicate, which changes nothing. A request for the customer to act, or
	 * a gateway still deciding, only moves the intent to wait, and the order's payment status with
	 * it. An approval of an authorization accepts a pending order; a decline fails it.
	 *
	 * A refusal is a CodedException: `payment.intent_not_found`; `payment.unexpected_result` when
	 * the intent's state cannot take the result; `payment.refund_exceeds_captured`;
	 * `payment.projection_conflict`; `order.transition_illegal` when an order a mismatch parks
	 * cannot be put on hold.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or for a void, which nothing applies yet; before any statement.
	 *
	 * @param GatewayResult $result The gateway's answer.
	 * @param Actor         $actor  On whose authority.
	 * @return Application What was done.
	 */
	public function applyGatewayResult( GatewayResult $result, Actor $actor ): Application {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( Operation::Void === $result->operation ) {
			throw new \LogicException( 'No void is applied yet: voiding an intent arrives with the gateway call that makes one.' );
		}

		return $this->tx->transaction(
			fn(): Application => in_array( $result->outcome, Outcome::moneyFacts(), true ) ? $this->applyMoneyFact( $result, $actor ) : $this->applyWait( $result, $actor )
		);
	}

	/**
	 * Captures an authorized intent in full: the gateway outside any transaction, then the result applied in a transaction of its own.
	 *
	 * Capture is always asked for: no status change captures a payment. The plain read of the
	 * intent only saves a pointless call to the gateway; the capture's own update decides.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException     Inside a transaction, before any statement.
	 * @throws GatewayUnavailable When the gateway has no answer; nothing was applied.
	 * @throws CodedException      `authorization.denied`, before any read; `payment.intent_not_found`;
	 *                             `payment.not_capturable` when the intent is not authorized;
	 *                             `payment.unreconciled` when it has a result a person must reconcile;
	 *                             and what applying the capture raises.
	 *
	 * @param string $intentUuid The intent.
	 * @param Actor  $actor      Who captures it.
	 * @return Application What applying the capture did.
	 */
	public function capture( string $intentUuid, Actor $actor ): Application {
		$this->requireNoTransaction( __FUNCTION__ );
		$this->authorizer->authorize( $actor, self::CAPTURE_CAPABILITY );

		$intent = $this->find( $intentUuid );

		if ( IntentStatus::Authorized !== $intent->status ) {
			CodedException::raise( PaymentError::NotCapturable, array( 'status' => $intent->status->value ) );
		}

		if ( $this->payments->hasUnappliedResult( $intent->id ) ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		$result = $this->gateway->capture( new CaptureRequest( $intent->uuid, $intent->providerIntentId, $intent->amount ) );

		return $this->tx->transaction( fn(): Application => $this->applyGatewayResult( $result, $actor ), RetryPolicy::deadlocks() );
	}

	/**
	 * Lists a page of the intents still waiting for the gateway's answer that have not changed for a while, oldest first, for reconciliation.
	 *
	 * One read on the intents' state and last change, measured by the database clock, in the
	 * order the intents were created. A caller that reads more than one page passes the uuid of
	 * the last intent of the page before, so intents the gateway never answers cannot keep the
	 * ones after them from being asked.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When the age is negative or the limit is not positive.
	 *
	 * @param int    $olderThanSeconds How long they have not changed, at least.
	 * @param int    $limit            The most to list.
	 * @param string $afterUuid        Optional. The uuid of the last intent of the page before. Default '', the first page.
	 * @return list<IntentRef> The intents.
	 */
	public function staleIntents( int $olderThanSeconds, int $limit, string $afterUuid = '' ): array {
		if ( $olderThanSeconds < 0 || $limit < 1 ) {
			throw new \InvalidArgumentException( 'Stale intents are listed by an age of 0 or more seconds, at least one at a time.' );
		}

		return $this->payments->stale( IntentStatus::awaitingResult(), $olderThanSeconds, $afterUuid, $limit );
	}

	/**
	 * Asks the gateway where an intent stands, outside any transaction, and returns its answer unapplied.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException     Inside a transaction, before the call.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param IntentRef $intent The intent.
	 * @return GatewayResult|null The gateway's answer; null while it is still deciding, or knows nothing of the intent.
	 */
	public function queryGateway( IntentRef $intent ): ?GatewayResult {
		$this->requireNoTransaction( __FUNCTION__ );

		return $this->gateway->query( new PaymentQuery( $intent->uuid, $intent->providerIntentId, $intent->amount ) );
	}

	/**
	 * Applies an approval or a decline: the ledger row first, which claims it, then the money and the order.
	 *
	 * The intent and the order are locked before the ledger row is written, so the check decides the
	 * row's `applied` flag from current values; whether the result was applied before is decided by
	 * the row's unique key alone.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException For a partial refund in a converted currency, whose base share is the refund service's to allocate.
	 *
	 * @param GatewayResult $result The result.
	 * @param Actor         $actor  On whose authority.
	 * @return Application What was done.
	 */
	private function applyMoneyFact( GatewayResult $result, Actor $actor ): Application {
		$intent   = $this->lock( $result->intentUuid );
		$order    = $this->orders->lockForPayment( $intent->orderId );
		$approved = Outcome::Approved === $result->outcome;
		$matches  = ! $approved || AmountCheck::accepts( $result, $intent, $order );
		$base     = $matches ? self::baseAmount( $intent, $result->amount, $order ) : null;

		if ( $approved && $matches && null === $base ) {
			throw new \LogicException( 'Only the intent\'s whole amount has a base amount here when the order is in a converted currency: a partial refund\'s base share is the refund service\'s to allocate from the order\'s tax components.' );
		}

		list( $actorType, $actorId ) = self::actorOf( $actor );

		$transactionId = $this->payments->appendResult( $intent, $result, $base ?? Money::zero( $order->baseCurrency() ), $matches, $actorType, $actorId, $this->correlation->current() );

		if ( null === $transactionId ) {
			return $this->unchanged( ApplicationKind::Duplicate, $result, $intent, $order, $this->payments->findResult( $result ) );
		}

		if ( ! $matches ) {
			$parked = $this->orders->park( $order, self::AMOUNT_MISMATCH, $actor );

			return $this->application( ApplicationKind::Mismatch, $result, $intent, $order, $transactionId, $intent->status, $order->paymentStatus, $parked?->to );
		}

		return $approved
			? $this->approve( $result, $intent, $order, $transactionId, $base ?? $intent->baseAmount, $actor )
			: $this->decline( $result, $intent, $order, $transactionId, $actor );
	}

	/**
	 * Moves an approval's money: the intent, the order's payment amounts and, for a pending order, its acceptance.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result        The approval.
	 * @param PaymentIntent $intent        The intent, locked.
	 * @param LockedOrder   $order         The order, locked.
	 * @param int           $transactionId Its ledger row.
	 * @param Money         $base          Its amount in the base currency.
	 * @param Actor         $actor         On whose authority.
	 * @return Application What was done.
	 */
	private function approve( GatewayResult $result, PaymentIntent $intent, LockedOrder $order, int $transactionId, Money $base, Actor $actor ): Application {
		if ( ! $this->payments->applyApproval( $intent, $result, $base ) ) {
			$this->refuse( $result, $intent );
		}

		$intentTo = self::stateAfter( $result, $intent );
		$amount   = $result->amount;

		if ( Operation::Authorize === $result->operation ) {
			$this->events->publish( new PaymentAuthorized( $intent->id, $intent->orderId, $amount->minorUnits(), $amount->currency()->code(), $transactionId, $this->clock->now() ) );
		} elseif ( Operation::Capture === $result->operation ) {
			$this->events->publish( new PaymentCaptured( $intent->id, $intent->orderId, $amount->minorUnits(), $amount->currency()->code(), $transactionId, $this->clock->now() ) );
		}

		$paymentTo = $this->record( $order, self::delta( $result->operation, $amount, $base ), $intentTo, self::reasonOf( $result->operation ), $actor );
		$orderTo   = null;

		// An authorization, or the capture of an intent the gateway authorized and captured at once, accepts a pending order.
		if ( Operation::Refund !== $result->operation && OrderStatus::PendingPayment === $order->status ) {
			$orderTo = $this->orders->accept( $order->id, $actor )->to;
		}

		return $this->application( ApplicationKind::Applied, $result, $intent, $order, $transactionId, $intentTo, $paymentTo, $orderTo );
	}

	/**
	 * Applies a decline: the intent fails, the order's payment status follows, and a pending order fails with it.
	 *
	 * The order keeps its number. A decline of an order already accepted, such as a declined
	 * capture, leaves its status to a person.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result        The decline.
	 * @param PaymentIntent $intent        The intent, locked.
	 * @param LockedOrder   $order         The order, locked.
	 * @param int           $transactionId Its ledger row.
	 * @param Actor         $actor         On whose authority.
	 * @return Application What was done.
	 */
	private function decline( GatewayResult $result, PaymentIntent $intent, LockedOrder $order, int $transactionId, Actor $actor ): Application {
		if ( ! $this->payments->applyDecline( $intent->id ) ) {
			$this->refuse( $result, $intent );
		}

		$this->events->publish( new PaymentFailed( $intent->id, $intent->orderId, $result->amount->minorUnits(), $result->amount->currency()->code(), $transactionId, $result->errorCode, $this->clock->now() ) );

		$paymentTo = $this->record( $order, self::nothing( $order ), IntentStatus::Failed, self::PAYMENT_DECLINED, $actor );
		$orderTo   = null;

		if ( OrderStatus::PendingPayment === $order->status ) {
			$orderTo = $this->orders->transition( $order->id, OrderStatus::Failed, self::PAYMENT_DECLINED, $actor )->to;
		}

		return $this->application( ApplicationKind::Declined, $result, $intent, $order, $transactionId, IntentStatus::Failed, $paymentTo, $orderTo );
	}

	/**
	 * Applies an answer that moves no money: the intent waits for the customer or the gateway, and the order's payment status follows.
	 *
	 * No ledger row: only a money fact is recorded. The same answer delivered again finds the
	 * intent already waiting, and is a duplicate; the locked read is what tells so.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.intent_not_found`; `payment.unexpected_result` when the intent is past waiting.
	 *
	 * @param GatewayResult $result The answer.
	 * @param Actor         $actor  On whose authority.
	 * @return Application What was done.
	 */
	private function applyWait( GatewayResult $result, Actor $actor ): Application {
		$intent  = $this->lock( $result->intentUuid );
		$order   = $this->orders->lockForPayment( $intent->orderId );
		$waiting = Outcome::RequiresAction === $result->outcome;
		$to      = $waiting ? IntentStatus::RequiresAction : IntentStatus::Processing;

		if ( ! $this->payments->await( $intent->id, $to, $result->providerIntentId, self::ACTION_WINDOW_SECONDS ) ) {
			if ( $to !== $intent->status ) {
				CodedException::raise(
					PaymentError::UnexpectedResult,
					array(
						'intent_status' => $intent->status->value,
						'operation'     => $result->operation->value,
					)
				);
			}

			return $this->unchanged( ApplicationKind::Duplicate, $result, $intent, $order, null );
		}

		$paymentTo = $this->record( $order, self::nothing( $order ), $to, $waiting ? 'payment_action_required' : 'payment_pending', $actor );

		return $this->application( $waiting ? ApplicationKind::RequiresAction : ApplicationKind::Pending, $result, $intent, $order, null, $to, $paymentTo, null );
	}

	/**
	 * Adds a payment to the order's payment amounts, with the payment status they then amount to, and publishes a change of that status.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.projection_conflict` when the update refused the change.
	 *
	 * @param LockedOrder  $order    The order, locked.
	 * @param PaymentDelta $delta    What the payment adds.
	 * @param IntentStatus $intentTo The intent's state after the payment.
	 * @param string       $reason   Why, for the order's event.
	 * @param Actor        $actor    On whose authority.
	 * @return PaymentStatus The order's payment status after.
	 */
	private function record( LockedOrder $order, PaymentDelta $delta, IntentStatus $intentTo, string $reason, Actor $actor ): PaymentStatus {
		$after  = Projection::after( $order, $delta );
		$status = $after->status( $intentTo );

		if ( ! $this->orders->recordPayment( $order, $delta, $status, $reason, $actor ) ) {
			// The amounts changed under a lock this transaction holds: something wrote them outside the money path.
			CodedException::raise( PaymentError::ProjectionConflict, array( 'order_id' => $order->id ) );
		}

		if ( $status !== $order->paymentStatus ) {
			$this->events->publish(
				new PaymentStatusChanged(
					$order->id,
					$order->paymentStatus->value,
					$status->value,
					$after->authorized->minorUnits(),
					$after->paid->minorUnits(),
					$after->refunded->minorUnits(),
					$after->due->minorUnits(),
					$order->currency()->code(),
					$this->clock->now()
				)
			);
		}

		return $status;
	}

	/**
	 * Raises the error of an approval or a decline the intent's update refused; the savepoint takes its ledger row back.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_exceeds_captured` for an approved refund of an intent that
	 *                        can be refunded, which only the cap refuses; otherwise `payment.unexpected_result`.
	 *
	 * @param GatewayResult $result The result.
	 * @param PaymentIntent $intent The intent, as locked: under the lock, its state and amounts are still current.
	 */
	private function refuse( GatewayResult $result, PaymentIntent $intent ): never {
		$refundable = in_array( $intent->status, IntentTransitions::allowedFrom( IntentStatus::Refunded ), true );

		if ( Operation::Refund === $result->operation && Outcome::Approved === $result->outcome && $refundable ) {
			CodedException::raise(
				PaymentError::RefundExceedsCaptured,
				array(
					'captured'  => $intent->captured->minorUnits(),
					'refunded'  => $intent->refunded->minorUnits(),
					'requested' => $result->amount->minorUnits(),
				)
			);
		}

		CodedException::raise(
			PaymentError::UnexpectedResult,
			array(
				'intent_status' => $intent->status->value,
				'operation'     => $result->operation->value,
			)
		);
	}

	/**
	 * Builds the Application of a result that changed nothing: a duplicate.
	 *
	 * @since 0.1.0
	 *
	 * @param ApplicationKind $kind          What happened.
	 * @param GatewayResult   $result        The result.
	 * @param PaymentIntent   $intent        The intent, locked.
	 * @param LockedOrder     $order         The order, locked.
	 * @param int|null        $transactionId The row the result was first recorded in, or null for an answer that moves no money.
	 * @return Application The Application.
	 */
	private function unchanged( ApplicationKind $kind, GatewayResult $result, PaymentIntent $intent, LockedOrder $order, ?int $transactionId ): Application {
		return $this->application( $kind, $result, $intent, $order, $transactionId, $intent->status, $order->paymentStatus, null );
	}

	/**
	 * Builds an Application.
	 *
	 * @since 0.1.0
	 *
	 * @param ApplicationKind  $kind          What happened.
	 * @param GatewayResult    $result        The result.
	 * @param PaymentIntent    $intent        The intent, as locked.
	 * @param LockedOrder      $order         The order, as locked.
	 * @param int|null         $transactionId The ledger row, or null.
	 * @param IntentStatus     $intentTo      The intent's state after.
	 * @param PaymentStatus    $paymentTo     The order's payment status after.
	 * @param OrderStatus|null $orderStatusTo The order's status after, when it changed.
	 * @return Application The Application.
	 */
	private function application( ApplicationKind $kind, GatewayResult $result, PaymentIntent $intent, LockedOrder $order, ?int $transactionId, IntentStatus $intentTo, PaymentStatus $paymentTo, ?OrderStatus $orderStatusTo ): Application {
		return new Application( $kind, $intent->uuid, $result->operation, $transactionId, $order->id, $order->holdGroup, $intent->status, $intentTo, $order->paymentStatus, $paymentTo, $orderStatusTo );
	}

	/**
	 * Locks an intent, and raises when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.intent_not_found`.
	 *
	 * @param string $uuid The intent's uuid.
	 * @return PaymentIntent The intent, locked.
	 */
	private function lock( string $uuid ): PaymentIntent {
		return $this->payments->lock( $uuid ) ?? CodedException::raise( PaymentError::IntentNotFound, array( 'intent_uuid' => $uuid ) );
	}

	/**
	 * Reads an intent without a lock, and raises when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.intent_not_found`.
	 *
	 * @param string $uuid The intent's uuid.
	 * @return PaymentIntent The intent.
	 */
	private function find( string $uuid ): PaymentIntent {
		return $this->payments->find( $uuid ) ?? CodedException::raise( PaymentError::IntentNotFound, array( 'intent_uuid' => $uuid ) );
	}

	/**
	 * Refuses a call that must run inside the caller's transaction, before any statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException At depth 0.
	 *
	 * @param string $method The method called.
	 */
	private function requireCallersTransaction( string $method ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'PaymentService::%s() runs inside the caller\'s transaction: what it writes must commit with the rest of the caller\'s unit of work.', $method ) );
		}
	}

	/**
	 * Refuses a gateway call inside a transaction, before any statement.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException At a depth above 0.
	 *
	 * @param string $method The method called.
	 */
	private function requireNoTransaction( string $method ): void {
		if ( 0 !== $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'PaymentService::%s() calls the gateway, which may go over the network: never inside a transaction, whose locks it would hold for the call.', $method ) );
		}
	}

	/**
	 * Returns the reason an approval's change of the order's payment status is recorded with.
	 *
	 * @since 0.1.0
	 *
	 * @param Operation $operation The approval's operation.
	 * @return string The reason.
	 */
	private static function reasonOf( Operation $operation ): string {
		return match ( $operation ) {
			Operation::Authorize => 'payment_authorized',
			Operation::Capture   => 'payment_captured',
			Operation::Refund    => 'payment_refunded',
			Operation::Void      => 'payment_voided',
		};
	}

	/**
	 * Returns what a payment adds to the order's amounts, by its operation.
	 *
	 * @since 0.1.0
	 *
	 * @param Operation $operation The operation: an authorization, a capture or a refund.
	 * @param Money     $amount    The amount.
	 * @param Money     $base      The amount in the base currency.
	 * @return PaymentDelta The delta.
	 */
	private static function delta( Operation $operation, Money $amount, Money $base ): PaymentDelta {
		$zero     = Money::zero( $amount->currency() );
		$baseZero = Money::zero( $base->currency() );

		return match ( $operation ) {
			Operation::Authorize => new PaymentDelta( $amount, $zero, $zero, $base, $baseZero, $baseZero ),
			Operation::Capture   => new PaymentDelta( $zero, $amount, $zero, $baseZero, $base, $baseZero ),
			Operation::Refund    => new PaymentDelta( $zero, $zero, $amount, $baseZero, $baseZero, $base ),
			Operation::Void      => new PaymentDelta( $zero, $zero, $zero, $baseZero, $baseZero, $baseZero ),
		};
	}

	/**
	 * Returns a delta that adds nothing, in the order's currencies: a change of the payment status alone.
	 *
	 * @since 0.1.0
	 *
	 * @param LockedOrder $order The order.
	 * @return PaymentDelta The delta.
	 */
	private static function nothing( LockedOrder $order ): PaymentDelta {
		$zero     = Money::zero( $order->currency() );
		$baseZero = Money::zero( $order->baseCurrency() );

		return new PaymentDelta( $zero, $zero, $zero, $baseZero, $baseZero, $baseZero );
	}

	/**
	 * Returns the state an approval leaves its intent in, as its update decides it.
	 *
	 * @since 0.1.0
	 *
	 * @param GatewayResult $result The approval.
	 * @param PaymentIntent $intent The intent, as locked before the update.
	 * @return IntentStatus The state after.
	 */
	private static function stateAfter( GatewayResult $result, PaymentIntent $intent ): IntentStatus {
		return match ( $result->operation ) {
			Operation::Authorize => IntentStatus::Authorized,
			Operation::Capture   => IntentStatus::Captured,
			Operation::Refund    => $intent->refunded->add( $result->amount )->compare( $intent->captured ) >= 0 ? IntentStatus::Refunded : IntentStatus::PartiallyRefunded,
			Operation::Void      => IntentStatus::Voided,
		};
	}

	/**
	 * Returns a result's amount in the order's base currency, when it has one without an allocation.
	 *
	 * The intent's whole amount is its frozen base amount, so an authorization or a capture adds
	 * all of it. Any other amount has an exact base equivalent only when the order is in its base
	 * currency; otherwise its base share is the refund service's to allocate from the order's tax
	 * components, at the order's frozen rate. No rate is read here. The amount is in the order's
	 * base currency, which the amount check found the intent's to be, and which the intent's
	 * update and the order's projection each require again in their WHERE clauses.
	 *
	 * @since 0.1.0
	 *
	 * @param PaymentIntent $intent The intent.
	 * @param Money         $amount The amount, in the currency the gateway reported.
	 * @param LockedOrder   $order  The intent's order, locked.
	 * @return Money|null The base amount, or null when it has none here.
	 */
	private static function baseAmount( PaymentIntent $intent, Money $amount, LockedOrder $order ): ?Money {
		if ( $amount->equals( $intent->amount ) ) {
			return Money::of( $intent->baseAmount->minorUnits(), $order->baseCurrency() );
		}

		$inOrderCurrency = $amount->currency()->equals( $order->currency() );
		$orderIsInBase   = $order->baseCurrency()->equals( $order->currency() );

		return $inOrderCurrency && $orderIsInBase ? Money::of( $amount->minorUnits(), $order->baseCurrency() ) : null;
	}

	/**
	 * Tells whether an amount is more than nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param Money $amount The amount.
	 * @return bool True when positive.
	 */
	private static function isPositive( Money $amount ): bool {
		return ! $amount->isZero() && ! $amount->isNegative();
	}

	/**
	 * Returns how an actor is recorded: its kind, and its user when there is one.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return array{0: string, 1: int|null} `user` or `system`, and the user id or null for a visitor.
	 */
	private static function actorOf( Actor $actor ): array {
		return array( null === $actor->systemName() ? 'user' : 'system', $actor->userId() > 0 ? $actor->userId() : null );
	}
}
