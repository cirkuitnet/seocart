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

use SEOCart\Contracts\Payment\CaptureRequest;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Contracts\Payment\PaymentQuery;
use SEOCart\Contracts\Payment\PaymentRequest;
use SEOCart\Contracts\Payment\VoidRequest;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Domain\AmountCheck;
use SEOCart\Payment\Domain\CaptureShare;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\PaymentAuthorized;
use SEOCart\Payment\Domain\Event\PaymentCaptured;
use SEOCart\Payment\Domain\Event\PaymentFailed;
use SEOCart\Payment\Domain\Event\PaymentIntentCreated;
use SEOCart\Payment\Domain\Event\PaymentStatusChanged;
use SEOCart\Payment\Domain\Event\PaymentVoided;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\NothingDue;
use SEOCart\Payment\Domain\PaymentIntent;
use SEOCart\Payment\Domain\PaymentRepository;
use SEOCart\Payment\Domain\Projection;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Currency;
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
 * status allows; the row is kept whatever the order's status. So is an approval the intent's state
 * cannot take, such as a capture of an intent never authorized: the provider moved money the
 * ledger did not expect. A result about a state the intent has left is stale and changes nothing,
 * with no row: an earlier attempt's decline delivered after a later attempt was authorized, a
 * decline of a void, an authorization of an intent since voided, a void of one already voided or
 * failed (IntentTransitions says which). What only a statement can refuse, a refund past what was
 * captured, is refused, and its ledger row goes back with the savepoint it was written in. Stock,
 * promotion usage and the cart are the caller's, settled by the Application's kind in the same
 * transaction.
 *
 * A declined refund is recorded and changes nothing else: the money the intent captured stays
 * captured. An approval a caller could not go on to record, such as a refund whose document a cap
 * refused after the gateway had given the money back, is kept the way a mismatch is
 * (recordUnapplied()), so money the gateway moved is never left unrecorded.
 *
 * An order with nothing to pay has no intent, and moves no money: settleNothingDue() records it
 * paid and accepts it, with no ledger row.
 *
 * Each call to a gateway goes to the intent's own gateway, found in the registry by the gateway
 * and the mode the intent recorded when it was created (Gateways::get()); a gateway that is not
 * registered, or whose credentials for that mode cannot be used, is refused
 * `payment.gateway_unavailable` before a request is built. Before that, every call is checked
 * against the gateway's capability matrix (require()): an operation the matrix does not declare
 * for the intent's currency and the account's country is refused `payment.operation_unsupported`,
 * and nothing is asked of the gateway.
 *
 * @since 0.1.0
 * @since 0.2.0 Finds each intent's gateway in the registry, and refuses what its matrix does not declare.
 */
final class PaymentService {

	/**
	 * The capability a capture needs, the capture operation's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPTURE_CAPABILITY = PaymentOperations::CAPTURE_CAPABILITY;

	/**
	 * The capability a person needs to void a payment, the void operation's.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VOID_CAPABILITY = PaymentOperations::VOID_CAPABILITY;

	/**
	 * How long an intent waits, for a customer asked to act (for example to confirm with their bank) or for a gateway still deciding, before the attempt is taken as expired, in seconds.
	 *
	 * The window a provider that reports none of its own is held to: reconciliation sends it with
	 * its query, and the gateway answers an intent waiting past it as expired.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const ACTION_WINDOW_SECONDS = 900;

	/**
	 * How long a placement's payment may wait unchanged before it is called stale, in seconds: longer than any call to a gateway takes.
	 *
	 * Reconciliation asks the gateway about an intent, and settles an order with nothing due, only
	 * once it has waited this long; doctor calls an order with something to pay and no intent
	 * broken only once it is this old.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const STALE_SECONDS = 600;

	/**
	 * The reason an order with nothing to pay is recorded as paid with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const NOTHING_DUE = 'nothing_due';

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
	 * The reason an order is parked with when the gateway moved money that could not be recorded against it.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_UNRECORDED = 'payment_unrecorded';

	/**
	 * The reason an order is parked with when the gateway approved what its intent's state cannot take, such as a capture of an intent never authorized.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const UNEXPECTED_RESULT = 'unexpected_result';

	/**
	 * The reason an accepted order is parked with when its payment is voided without a person asking, as when its approval landed while the shopper's time to act ran out.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VOIDED_AFTER_APPROVAL = 'voided_after_approval';

	/**
	 * The reason an accepted order is parked with when the provider reports its payment voided and nobody asked the store for it, as when someone cancelled it in the provider's dashboard.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const VOIDED_EXTERNALLY = 'voided_externally';

	/**
	 * The reason an order is parked with when its authorization was approved after the placement had ended without it: the provider holds money the store gave up waiting for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const LATE_APPROVAL = 'late_approval';

	/**
	 * The reason an order is parked with when the provider gave money back that no refund of the store's asked for, as a refund made in the provider's dashboard.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const EXTERNAL_REFUND = 'external_refund';

	/**
	 * Why money is kept for a person: the provider reports a refund its gateway's capability matrix no longer declares, so the store cannot record it as the refund its claim asked for.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const OPERATION_UNSUPPORTED = 'operation_unsupported';

	/**
	 * Why money is kept for a person: the provider declines a refund the ledger holds as made, so money the store counts as given back was not.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const REFUND_REVERSED = 'refund_reversed';

	/**
	 * The machine code of the decline of an authorization the store refused to send, so its provider never saw it.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_SENT = 'not_sent';

	/**
	 * The code a provider's "not found" is reported with when it is taken for no answer: the provider could not have found the intent yet.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NOT_FOUND_IGNORED = 'payment.not_found_ignored';

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentRepository
	 */
	private PaymentRepository $payments;

	/**
	 * The gateways, which each intent's own is found in.
	 *
	 * @since 0.2.0
	 *
	 * @var Gateways
	 */
	private Gateways $gateways;

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
	 * Receives a report code and its context.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(string, array<string, mixed>): void
	 */
	private \Closure $report;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes the gateway registry in place of the one gateway, and a reporter.
	 *
	 * @param PaymentRepository  $payments    The statements.
	 * @param Gateways           $gateways    The gateways.
	 * @param Orders             $orders      The orders the payments are for.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param Authorizer         $authorizer  Checks capabilities.
	 * @param IdGenerator        $ids         Mints intent uuids.
	 * @param Clock              $clock       Says when an event happened.
	 * @param CorrelationId      $correlation The request's correlation id.
	 * @param callable           $report      Receives a report code (string) and its context (array).
	 *
	 * @phpstan-param callable(string, array<string, mixed>): void $report
	 */
	public function __construct( PaymentRepository $payments, Gateways $gateways, Orders $orders, TransactionManager $tx, EventPublisher $events, Authorizer $authorizer, IdGenerator $ids, Clock $clock, CorrelationId $correlation, callable $report ) {
		$this->payments    = $payments;
		$this->gateways    = $gateways;
		$this->orders      = $orders;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->authorizer  = $authorizer;
		$this->ids         = $ids;
		$this->clock       = $clock;
		$this->correlation = $correlation;
		$this->report      = \Closure::fromCallable( $report );
	}

	/**
	 * Creates an order's intent, with its amounts frozen, inside the caller's transaction.
	 *
	 * One insert and PaymentIntentCreated. The intent records the mode it is created in, which every
	 * later call about it uses, whatever the store is set to by then. The amounts are the caller's:
	 * the grand total and the
	 * base grand total of the order's totals, for its one tender. An order with nothing due has no
	 * intent (settleNothingDue()), so the amount in the order's currency is positive; its base
	 * equivalent may round to zero, for a small order in a currency worth far less than the base
	 * one. The gateway is not called. Outside a transaction it throws a \LogicException, before any
	 * statement.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The mode was added.
	 *
	 * @throws \InvalidArgumentException When the amount is not positive, or the base amount is
	 *                                   negative; before any statement.
	 * @phpstan-throws \InvalidArgumentException|CodedException `payment.gateway_unavailable` when the gateway is not registered.
	 *
	 * @param int    $orderId             The order, inserted in this transaction.
	 * @param string $gatewayId           The gateway to pay through: a registered gateway's id.
	 * @param Mode   $mode                The mode the intent is created in: the gateway's mode now.
	 * @param Money  $amount              The amount, in the order's currency.
	 * @param Money  $baseAmount          The amount in the base currency, at the order's rate.
	 * @param int    $conversionContextId The order's rate.
	 * @return IntentRef The intent, created.
	 */
	public function createIntent( int $orderId, string $gatewayId, Mode $mode, Money $amount, Money $baseAmount, int $conversionContextId ): IntentRef {
		$this->requireCallersTransaction( __FUNCTION__ );

		// Placement found the gateway available before its transaction began, which built the registry.
		$this->gateways->descriptor( $gatewayId );

		if ( ! self::isPositive( $amount ) || $baseAmount->isNegative() ) {
			throw new \InvalidArgumentException( 'An intent is for a positive amount in the order\'s currency, and an amount in the base currency that is not negative: an order with nothing due has no intent.' );
		}

		$uuid     = $this->ids->generate();
		$intentId = $this->payments->insertIntent( $orderId, $uuid, $gatewayId, $mode, $amount, $baseAmount, $conversionContextId );

		$this->events->publish( new PaymentIntentCreated( $intentId, $uuid, $orderId, $gatewayId, $amount->minorUnits(), $amount->currency()->code(), $this->clock->now() ) );

		return new IntentRef( $uuid, $orderId, $gatewayId, $mode, IntentStatus::Created, null, $amount );
	}

	/**
	 * Asks the gateway to authorize an intent, outside any transaction, and returns its answer unapplied.
	 *
	 * The caller applies the answer with applyGatewayResult() in a transaction of its own. The
	 * payment data is what the customer's browser sent; the gateway reads its payment token, and a
	 * missing token is the gateway's to decline, as a provider declines a token it cannot use. The
	 * order's uuid and number are the caller's, which placed the order: a provider shows them to the
	 * merchant, and reading them here would cost the placement a statement.
	 *
	 * The gateway's matrix is checked again right before the call, for the intent's currency and
	 * recorded mode: placement checked it before writing the order, but the account's country, or
	 * the gateway itself, may have changed since. The return address is where a provider that asks
	 * the shopper to act sends them back; it carries nothing secret.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The order's uuid and number, and the return address, were added, and the matrix is checked before the call.
	 *
	 * @throws \LogicException     Inside a transaction, before any statement.
	 * @throws CodedException      `payment.intent_not_found`; `payment.operation_unsupported`;
	 *                             `payment.gateway_unavailable`; each before the gateway is asked.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param string               $intentUuid  The intent.
	 * @param array<string, mixed> $paymentData What the customer's browser sent: `payment_token`, the gateway's token for their payment method.
	 * @param string               $orderUuid   The order's uuid.
	 * @param string               $orderNumber The order's number.
	 * @param string|null          $returnUrl   Optional. Where the provider sends the shopper back after asking them to
	 *                                          act. Default null, for none.
	 * @return GatewayResult The gateway's answer.
	 */
	public function authorize( string $intentUuid, array $paymentData, string $orderUuid, string $orderNumber, ?string $returnUrl = null ): GatewayResult {
		$this->requireNoTransaction( __FUNCTION__ );

		$intent = $this->find( $intentUuid );
		$token  = $paymentData[ self::PAYMENT_TOKEN ] ?? null;

		$this->require( $intent->gatewayId, Operations::AUTHORIZE, $intent->mode, $intent->amount->currency() );

		return $this->gateways->get( $intent->gatewayId, $intent->mode )->authorize( new PaymentRequest( $intent->uuid, $intent->amount, is_string( $token ) && '' !== $token ? $token : null, $intent->mode, $orderUuid, $orderNumber, $returnUrl ) );
	}

	/**
	 * Applies one gateway result, inside the caller's transaction: the single path money takes.
	 *
	 * Runs in a savepoint of the caller's transaction, so a refused result leaves nothing behind
	 * even when the caller goes on. An approval or a decline is a money fact: the intent and then
	 * the order are locked, and the ledger row is appended, which claims the result; a row already
	 * there makes this a duplicate, which changes nothing. A request for the customer to act, or
	 * a gateway still deciding, only moves the intent to wait, and the order's payment status with
	 * it. An approval of an authorization accepts a pending order; a decline fails it. A declined
	 * refund is recorded and changes nothing else.
	 *
	 * What the intent's state decides is decided under its lock, the same for every caller. An
	 * approval the state cannot take still moved money at the provider: an authorization that comes
	 * after its intent failed, as when the gateway once had no record of it, or a capture of an
	 * intent never authorized, is kept as recordUnapplied() keeps one, its ledger row `applied = 0`
	 * and the order flagged, never refused and rolled back. A result about a state the intent has
	 * left is stale (IntentTransitions::isStaleDecline() and isStaleApproval(), and a wait reported
	 * for an intent past waiting): nothing changes and no row is written, unless the ledger has the
	 * very same result already, which is then a duplicate.
	 *
	 * An authorization or a capture is for the intent's whole frozen amount, whose base twin was
	 * frozen with it. A refund of part of an order in a converted currency has no base twin of its
	 * own: the refund service allocates it from the order's stored figures and passes it as the
	 * base amount, which is then added as given. No rate is read either way.
	 *
	 * A refusal is a CodedException: `payment.intent_not_found`; `payment.refund_exceeds_captured`;
	 * `payment.unexpected_result` when a statement refuses what the intent's state allowed, which
	 * the checks before it rule out; `payment.projection_conflict`; `order.transition_illegal` when
	 * an order a mismatch parks cannot be put on hold.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Keeps for a person every approval the intent's state cannot take, and changes nothing for a stale result.
	 *
	 * @throws \LogicException Outside a transaction, for a base amount given with anything but a
	 *                         refund, or for an approved void given no reason; before any statement.
	 *
	 * @param GatewayResult   $result     The gateway's answer.
	 * @param Actor           $actor      On whose authority.
	 * @param Money|null      $baseAmount Optional. A refund's amount in the order's base currency, at the order's
	 *                                    frozen rate, as the refund service allocated it. Default null: the
	 *                                    intent's frozen base amount for its whole amount.
	 * @param VoidReason|null $voidReason Optional. Why the void the answer is about was asked for, which an approved
	 *                                    void records; any other answer ignores it. Default null.
	 * @return Application What was done.
	 */
	public function applyGatewayResult( GatewayResult $result, Actor $actor, ?Money $baseAmount = null, ?VoidReason $voidReason = null ): Application {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( Operation::Void === $result->operation && Outcome::Approved === $result->outcome && null === $voidReason ) {
			throw new \LogicException( 'A void is applied with the reason it was asked for, which the provider\'s answer does not carry: pass it with the answer.' );
		}

		if ( null !== $baseAmount && Operation::Refund !== $result->operation ) {
			throw new \LogicException( 'Only a refund is given its base amount: an authorization or a capture is for the intent\'s whole amount, whose base amount was frozen with it.' );
		}

		return $this->tx->transaction(
			fn(): Application => in_array( $result->outcome, Outcome::moneyFacts(), true ) ? $this->applyMoneyFact( $result, $actor, $baseAmount, $voidReason ) : $this->applyWait( $result, $actor )
		);
	}

	/**
	 * Records an approval the caller could not go on to record, or a refund's reversal, as money a person must reconcile: its ledger row kept with `applied = 0`, and the order parked.
	 *
	 * For a refund the gateway made whose document a cap then refused, because another refund
	 * landed after this one was checked: the money moved at the gateway, so it is never left
	 * unrecorded; for a refund the provider reports that no claim of the store's asked for
	 * (EXTERNAL_REFUND); and for the provider's decline of a refund the ledger holds as made
	 * (REFUND_REVERSED), the one outcome of its kind that is no approval: the money the store
	 * counts as given back was not, which only a person can put right. Inside the caller's
	 * transaction, after the savepoint that tried to apply it
	 * rolled back: the intent and then the order are locked, the row is appended, which claims the
	 * result, and only then is the order flagged and put `on_hold` where its status allows, as for a
	 * mismatch, so a person's clearance of the order, which takes its lock first, is dated after
	 * every such row it clears. Nothing else moves. A result already recorded is a duplicate, which
	 * changes nothing. A result whose intent does not exist raises `payment.intent_not_found`.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The reason.
	 *
	 * @throws \LogicException Outside a transaction, or for a result that is neither an approval nor a refund's reversal; before any statement.
	 *
	 * @param GatewayResult $result The approval, or the decline of a refund kept under REFUND_REVERSED.
	 * @param Actor         $actor  On whose authority.
	 * @param string        $reason Optional. Why the order is parked. Default PAYMENT_UNRECORDED.
	 * @return Application What was done: a mismatch, or a duplicate.
	 */
	public function recordUnapplied( GatewayResult $result, Actor $actor, string $reason = self::PAYMENT_UNRECORDED ): Application {
		$this->requireCallersTransaction( __FUNCTION__ );

		$reversal = self::REFUND_REVERSED === $reason && Operation::Refund === $result->operation && Outcome::Declined === $result->outcome;

		if ( Outcome::Approved !== $result->outcome && ! $reversal ) {
			throw new \LogicException( 'Only an approval, or the decline of a refund the ledger holds as made, leaves money a person must reconcile.' );
		}

		return $this->tx->transaction(
			function () use ( $result, $actor, $reason ): Application {
				$intent = $this->lock( $result->intentUuid );

				return $this->keepUnapplied( $result, $intent, $this->orders->lockForPayment( $intent->orderId ), $reason, $actor );
			}
		);
	}

	/**
	 * Settles an order with nothing to pay, inside the caller's transaction: its payment status becomes paid and the order is accepted.
	 *
	 * An order whose grand total is zero has no intent and no gateway is asked: no money moves,
	 * so there is no ledger row. In a savepoint of the caller's transaction the order is locked,
	 * its payment amounts are recorded with nothing added, so that the status they derive, paid,
	 * is written with its record and its event, and then the order is accepted, as an approval
	 * accepts one. An order no longer pending payment was settled before, by another runner or an
	 * earlier request, and nothing changes. Stock, promotion usage and the cart are the caller's,
	 * as for an approval.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement; when the order has
	 *                         something to pay, or its amounts do not derive the status paid, which
	 *                         rolls back the savepoint.
	 * @throws CodedException  `order.not_found`; `payment.projection_conflict`.
	 *
	 * @param int   $orderId The order: its grand total and its amount due are zero.
	 * @param Actor $actor   On whose authority.
	 * @return NothingDue What was done.
	 */
	public function settleNothingDue( int $orderId, Actor $actor ): NothingDue {
		$this->requireCallersTransaction( __FUNCTION__ );

		return $this->tx->transaction(
			function () use ( $orderId, $actor ): NothingDue {
				$order = $this->orders->lockForPayment( $orderId );

				if ( ! $order->grandTotal->isZero() || ! $order->due->isZero() ) {
					throw new \LogicException( sprintf( 'Order %1$d has %2$d due of a grand total of %3$d: only an order with nothing to pay is settled without a payment.', $order->id, $order->due->minorUnits(), $order->grandTotal->minorUnits() ) );
				}

				if ( OrderStatus::PendingPayment !== $order->status ) {
					return new NothingDue( $order->id, $order->holdGroup, $order->paymentStatus, null );
				}

				$paid = $this->record( $order, self::nothing( $order ), null, self::NOTHING_DUE, $actor );

				// The acceptance claims the order is paid for: its amounts must say so.
				if ( PaymentStatus::Paid !== $paid ) {
					throw new \LogicException( sprintf( 'Order %1$d has nothing to pay, but its payment amounts derive the status %2$s: it is not accepted.', $order->id, $paid->value ) );
				}

				return new NothingDue( $order->id, $order->holdGroup, $paid, $this->orders->accept( $order->id, $actor )->to );
			}
		);
	}

	/**
	 * Captures an authorized intent, in full or in part: the gateway outside any transaction, then the result applied in a transaction of its own.
	 *
	 * Capture is always asked for: no status change captures a payment. The plain read of the
	 * intent only saves a pointless call to the gateway; the capture's own update decides. With no
	 * amount, everything authorized is captured. Less is captured only where the gateway's matrix
	 * declares partial captures for the intent's currency and account; the provider releases the
	 * rest. One capture per intent: the provider is sent the same key, whatever the amount, so a
	 * capture asked again after its answer was lost is the same capture.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes an amount, and refuses while the schema gate is closed.
	 *
	 * @throws \LogicException            Inside a transaction, before any statement.
	 * @throws \InvalidArgumentException An amount of nothing, before any statement.
	 * @throws GatewayUnavailable        When the gateway has no answer; nothing was applied.
	 * @throws CodedException             `authorization.denied`, before any read; `store.unavailable` while the schema
	 *                                    gate is closed, before any read; `payment.intent_not_found`;
	 *                                    `payment.not_capturable` when the intent is not authorized, with its status and,
	 *                                    as details, what it captured and its currency, so a capture asked again reads as
	 *                                    done; `payment.unreconciled` when it has a result a person must reconcile,
	 *                                    written since the order's money was last cleared;
	 *                                    `payment.capture_exceeds_authorized`; `payment.operation_unsupported`, also for
	 *                                    a capture of less where the matrix does not declare it;
	 *                                    `payment.gateway_unavailable`; and what applying the capture raises.
	 *
	 * @param string   $intentUuid  The intent.
	 * @param Actor    $actor       Who captures it.
	 * @param int|null $amountMinor Optional. What to capture, in minor units of the intent's currency. Default null:
	 *                              everything authorized.
	 * @return Application What applying the capture did.
	 */
	public function capture( string $intentUuid, Actor $actor, ?int $amountMinor = null ): Application {
		return $this->applyInOwnTransaction( $this->askCapture( $intentUuid, $actor, $amountMinor ), $actor );
	}

	/**
	 * Asks the gateway to capture an authorized intent, outside any transaction, and returns its answer unapplied: capture()'s checks and call.
	 *
	 * Refuses, before the gateway is asked, what capture() refuses; throws GatewayUnavailable when
	 * the gateway has no answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException An amount of nothing, before any statement.
	 *
	 * @param string   $intentUuid  The intent.
	 * @param Actor    $actor       Who captures it.
	 * @param int|null $amountMinor What to capture, in minor units; null for everything authorized.
	 * @return GatewayResult The gateway's answer.
	 */
	private function askCapture( string $intentUuid, Actor $actor, ?int $amountMinor ): GatewayResult {
		$this->requireNoTransaction( 'capture' );

		if ( null !== $amountMinor && $amountMinor < 1 ) {
			throw new \InvalidArgumentException( 'A capture takes more than nothing.' );
		}

		$this->authorizer->authorize( $actor, self::CAPTURE_CAPABILITY );

		// Before the first read: a column the intent's read names may belong to a migration still outstanding.
		$this->tx->refuseWhileClosed();

		$intent = $this->find( $intentUuid );

		if ( IntentStatus::Authorized !== $intent->status ) {
			self::refuseNotCapturable( $intent );
		}

		$this->refuseUnreconciled( $intent );

		$amount = null === $amountMinor ? $intent->authorized : Money::of( $amountMinor, $intent->amount->currency() );

		$this->requireCapturable( $intent, $amount );

		return $this->gateways->get( $intent->gatewayId, $intent->mode )->capture( new CaptureRequest( $intent->uuid, $intent->providerIntentId, $amount, $intent->mode ) );
	}

	/**
	 * Refuses a capture of an intent that is not authorized, with its status and, as details, what it captured and in which currency, so a capture asked again reads as done.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always: `payment.not_capturable`.
	 *
	 * @param PaymentIntent $intent The intent, as read.
	 */
	private static function refuseNotCapturable( PaymentIntent $intent ): never {
		CodedException::raise(
			PaymentError::NotCapturable,
			array( 'status' => $intent->status->value ),
			array(
				'captured' => $intent->captured->minorUnits(),
				'currency' => $intent->captured->currency()->code(),
			)
		);
	}

	/**
	 * Refuses a capture or a void of an intent holding money a person must reconcile: a ledger row the projection refused, written since a person last cleared the order's unreconciled money.
	 *
	 * The ledger keeps such a row for good; once a person has cleared the order, only a row written
	 * after the clearance holds the payment back, as it holds back its refunds. A clearance is dated
	 * after every row it cleared, so a row dated the same as the clearance was written after it, and
	 * counts. Plain reads, outside any transaction: the order's clearance is read only for an intent
	 * that has such a row at all.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.unreconciled`.
	 *
	 * @param PaymentIntent $intent The intent, as read.
	 */
	private function refuseUnreconciled( PaymentIntent $intent ): void {
		$newest = $this->payments->newestUnappliedOf( $intent->id );

		if ( null === $newest ) {
			return;
		}

		$clearance = $this->orders->statusOf( $intent->orderId )['money_reconciled_at'] ?? null;

		if ( null === $clearance || strcmp( $newest, $clearance ) >= 0 ) {
			CodedException::raise( PaymentError::Unreconciled );
		}
	}

	/**
	 * Refuses a capture of more than the intent authorized, and one of less where the gateway does not declare it, before the gateway is asked.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.capture_exceeds_authorized`; `payment.operation_unsupported`, naming `capture`, or
	 *                        `partial_capture` for a capture of less than was authorized.
	 *
	 * @param PaymentIntent $intent The intent, authorized.
	 * @param Money         $amount What the capture takes.
	 */
	private function requireCapturable( PaymentIntent $intent, Money $amount ): void {
		$against = $amount->compare( $intent->authorized );

		if ( $against > 0 ) {
			CodedException::raise(
				PaymentError::CaptureExceedsAuthorized,
				array(
					'authorized' => $intent->authorized->minorUnits(),
					'requested'  => $amount->minorUnits(),
				)
			);
		}

		$this->require( $intent->gatewayId, $against < 0 ? Operations::PARTIAL_CAPTURE : Operations::CAPTURE, $intent->mode, $intent->amount->currency() );
	}

	/**
	 * Asks the gateway to void an intent, outside any transaction, and returns its answer unapplied.
	 *
	 * A person needs the void capability, and voids an authorized intent. The store itself, a
	 * process acting on no user's authority, also voids an intent whose shopper was asked to act and
	 * whose time to do so ran out (VoidReason::ActionWindowEnded), so that the provider cancels it
	 * before what it holds is released: the caller applies the answer in the placement's
	 * settlement, as for an authorization. Anything else is refused before the gateway is asked: a
	 * captured intent is never voided, since what was captured goes back by a refund. The provider
	 * may answer with the authorization's approval instead, when the shopper finished just before
	 * the void reached it: that answer is applied as the authorization it is.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException     Inside a transaction, before any statement.
	 * @throws GatewayUnavailable When the gateway has no answer; nothing was applied, and asking again sends the same key.
	 * @throws CodedException      `authorization.denied`, for a person, before any read; `store.unavailable` while the
	 *                             schema gate is closed, before any read; `payment.intent_not_found`;
	 *                             `payment.not_voidable` with the intent's status; `payment.unreconciled` when it has
	 *                             a result a person must reconcile, written since the order's money was last cleared;
	 *                             `payment.operation_unsupported`; `payment.gateway_unavailable`.
	 *
	 * @param string     $intentUuid The intent.
	 * @param Actor      $actor      Who voids it: a person, or the store.
	 * @param VoidReason $reason     Why.
	 * @return GatewayResult The gateway's answer.
	 */
	public function askVoid( string $intentUuid, Actor $actor, VoidReason $reason ): GatewayResult {
		$this->requireNoTransaction( __FUNCTION__ );

		if ( ! self::isTheStore( $actor ) ) {
			$this->authorizer->authorize( $actor, self::VOID_CAPABILITY );
		}

		// Before the first read: a column the intent's read names may belong to a migration still outstanding.
		$this->tx->refuseWhileClosed();

		$intent = $this->find( $intentUuid );
		$ended  = IntentStatus::RequiresAction === $intent->status && VoidReason::ActionWindowEnded === $reason && self::isTheStore( $actor );

		if ( IntentStatus::Authorized !== $intent->status && ! $ended ) {
			CodedException::raise( PaymentError::NotVoidable, array( 'status' => $intent->status->value ) );
		}

		$this->refuseUnreconciled( $intent );

		$this->require( $intent->gatewayId, Operations::VOID, $intent->mode, $intent->amount->currency() );

		return $this->gateways->get( $intent->gatewayId, $intent->mode )->void( new VoidRequest( $intent->uuid, $intent->providerIntentId, $intent->amount, $intent->mode, $reason->value ) );
	}

	/**
	 * Voids an intent: the gateway outside any transaction, then its answer applied in a transaction of its own.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException     Inside a transaction, before any statement.
	 * @throws GatewayUnavailable When the gateway has no answer; nothing was applied.
	 * @throws CodedException      What askVoid() refuses, and what applying the answer raises.
	 *
	 * @param string     $intentUuid The intent.
	 * @param Actor      $actor      Who voids it.
	 * @param VoidReason $reason     Why.
	 * @return Application What applying the answer did.
	 */
	public function void( string $intentUuid, Actor $actor, VoidReason $reason ): Application {
		return $this->applyInOwnTransaction( $this->askVoid( $intentUuid, $actor, $reason ), $actor, $reason );
	}

	/**
	 * Applies a capture or a void the provider delivered on its own, such as by a webhook: in a transaction of its own, run again whole on a deadlock.
	 *
	 * For an intent whose order no placement is settling: the same path the capture and void
	 * operations apply their answer by, once the gateway answered. Whatever arrives first, the
	 * operation's answer or the delivery, is applied, and the other meets the ledger's key and is a
	 * duplicate; a result about a state the intent has left is stale, and one it cannot take is kept
	 * for a person, as for any caller. A void nobody asked the store for is applied with
	 * VoidReason::VoidedExternally, which parks an accepted order for a person.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException            Inside a transaction, before any statement.
	 * @throws \InvalidArgumentException For a result that is not a capture or a void, before any statement: an
	 *                                    authorization is settled with its placement, and a refund with its claim.
	 *                                    What applying the result refuses is raised as applyGatewayResult() raises it.
	 *
	 * @param GatewayResult   $result     The provider's result: a capture or a void.
	 * @param Actor           $actor      On whose authority: the store, for a webhook.
	 * @param VoidReason|null $voidReason Optional. Why the void was asked for; required for an approved void. Default null.
	 * @return Application What was done.
	 */
	public function applyDelivered( GatewayResult $result, Actor $actor, ?VoidReason $voidReason = null ): Application {
		if ( 0 !== $this->tx->depth() ) {
			throw new \LogicException( 'PaymentService::applyDelivered() applies the result in a transaction of its own: never inside another, whose locks it would join.' );
		}

		if ( ! in_array( $result->operation, array( Operation::Capture, Operation::Void ), true ) ) {
			throw new \InvalidArgumentException( 'A delivered result applied on its own is a capture or a void: an authorization is settled with its placement, and a refund with its claim.' );
		}

		return $this->applyInOwnTransaction( $result, $actor, $voidReason );
	}

	/**
	 * Captures a payment as the capture operation asks, and answers with what the gateway captured.
	 *
	 * Takes no idempotency key: the intent's state and the key the provider is sent, derived from
	 * the intent, make a capture asked twice one capture. One asked again after it was applied is
	 * refused `payment.not_capturable` with what was captured, which a client reads as done; one
	 * racing another of the same payment meets its ledger row and is answered `duplicate`.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException What capture() refuses; `payment.gateway_no_answer` when the gateway did not answer, and
	 *                        nothing was recorded; `payment.operation_declined` when it refused, raised once its
	 *                        answer is recorded; `payment.not_capturable` when the payment moved on meanwhile;
	 *                        `payment.unreconciled` when the gateway answered what a person must reconcile.
	 *
	 * @param array<string, mixed> $input The prepared input: intent_uuid, and optionally amount_minor.
	 * @param Actor                $actor Who captures.
	 * @return array<string, mixed> The payment as the capture left it, keyed by wire name.
	 */
	public function capturePayment( array $input, Actor $actor ): array {
		$uuid   = (string) $input['intent_uuid'];
		$amount = isset( $input['amount_minor'] ) ? (int) $input['amount_minor'] : null;
		$result = self::answered( fn(): GatewayResult => $this->askCapture( $uuid, $actor, $amount ) );

		return $this->operationAnswer( $result, $this->applyInOwnTransaction( $result, $actor ), Operation::Capture );
	}

	/**
	 * Voids a payment as the void operation asks, and answers with what the gateway released.
	 *
	 * Takes no idempotency key, as a capture takes none. A gateway that refuses to cancel changes
	 * nothing, and is answered `payment.operation_declined`; one that answers the authorization stands
	 * is answered `payment.not_voidable`. Refuses, as a CodedException, what askVoid() refuses;
	 * `payment.gateway_no_answer`; `payment.operation_declined`; `payment.not_voidable` when the
	 * payment moved on, or the authorization stands; `payment.unreconciled`.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException For a reason that is not one of VoidReason::merchant(), which the operation's
	 *                                   schema refuses on every surface first; the message never repeats it.
	 *
	 * @param array<string, mixed> $input The prepared input: intent_uuid and reason, one of VoidReason::merchant().
	 * @param Actor                $actor Who voids.
	 * @return array<string, mixed> The payment as the void left it, keyed by wire name.
	 */
	public function voidPayment( array $input, Actor $actor ): array {
		$uuid   = (string) $input['intent_uuid'];
		$reason = VoidReason::tryFrom( (string) $input['reason'] );

		if ( null === $reason || ! in_array( $reason, VoidReason::merchant(), true ) ) {
			throw new \InvalidArgumentException( 'A void asked through its operation gives one of the reasons a merchant may give.' );
		}

		$result = self::answered( fn(): GatewayResult => $this->askVoid( $uuid, $actor, $reason ) );

		return $this->operationAnswer( $result, $this->applyInOwnTransaction( $result, $actor, $reason ), Operation::Void );
	}

	/**
	 * Asks the gateway, and refuses with a code when it does not answer.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_no_answer`, with the gateway's failure as the previous exception.
	 *
	 * @param \Closure $ask Asks the gateway.
	 * @return GatewayResult Its answer.
	 *
	 * @phpstan-param \Closure(): GatewayResult $ask
	 */
	private static function answered( \Closure $ask ): GatewayResult {
		try {
			return $ask();
		} catch ( GatewayUnavailable $unanswered ) {
			throw CodedException::because( PaymentError::GatewayNoAnswer, array(), $unanswered );
		}
	}

	/**
	 * Answers a capture or a void from what the gateway said and what the money path did with it.
	 *
	 * Applied, or applied before by the request it raced: the payment as it now stands. Kept for a
	 * person: `payment.unreconciled`. Refused by the gateway, the payment still authorized or failed
	 * by the refusal: `payment.operation_declined`. Otherwise the payment moved on, or the gateway
	 * said the authorization stands: refused as a capture or a void of a payment in that state is.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException As described.
	 *
	 * @param GatewayResult $result  The gateway's answer.
	 * @param Application   $applied What the money path did with it.
	 * @param Operation     $asked   The operation asked: a capture or a void.
	 * @return array<string, mixed> The payment, keyed by wire name.
	 */
	private function operationAnswer( GatewayResult $result, Application $applied, Operation $asked ): array {
		$done = in_array( $applied->kind, array( ApplicationKind::Applied, ApplicationKind::Duplicate ), true );

		if ( $done && $asked === $applied->operation ) {
			return array(
				'intent_uuid'    => $applied->intentUuid,
				'outcome'        => $applied->kind->value,
				'status'         => $applied->intentTo->value,
				'payment_status' => $applied->paymentTo->value,
				'amount_minor'   => $result->amount->minorUnits(),
				'currency'       => $result->amount->currency()->code(),
			);
		}

		if ( ApplicationKind::Mismatch === $applied->kind ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		if ( Outcome::Declined === $result->outcome && ( ApplicationKind::Declined === $applied->kind || IntentStatus::Authorized === $applied->intentTo ) ) {
			CodedException::raise(
				PaymentError::OperationDeclined,
				array(
					'gateway_id' => $result->provider,
					'operation'  => $asked->value,
				)
			);
		}

		if ( Operation::Capture === $asked ) {
			self::refuseNotCapturable( $this->find( $applied->intentUuid ) );
		}

		CodedException::raise( PaymentError::NotVoidable, array( 'status' => $applied->intentTo->value ) );
	}

	/**
	 * Applies a gateway's answer in a transaction of its own, run again whole on a deadlock: for an operation on an intent whose order no placement is settling.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult   $result     The answer.
	 * @param Actor           $actor      On whose authority.
	 * @param VoidReason|null $voidReason Optional. Why the void the answer is about was asked for. Default null.
	 * @return Application What was done.
	 */
	private function applyInOwnTransaction( GatewayResult $result, Actor $actor, ?VoidReason $voidReason = null ): Application {
		return $this->tx->transaction( fn(): Application => $this->applyGatewayResult( $result, $actor, null, $voidReason ), RetryPolicy::deadlocks() );
	}

	/**
	 * Tells whether an actor is the store itself: a process acting on no user's authority, such as a job.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return bool True for a system actor with no user.
	 */
	private static function isTheStore( Actor $actor ): bool {
		return null !== $actor->systemName() && 0 === $actor->userId();
	}

	/**
	 * Lists a page of the intents still waiting for the gateway's answer that have not changed for a while, oldest first, for reconciliation.
	 *
	 * One read on the intents' state and last change, measured by the database clock, in the
	 * order the intents were created, each with when its wait runs out and whether it had. A
	 * caller that reads more than one page passes the uuid of the last intent of the page before,
	 * so intents the gateway never answers cannot keep the ones after them from being asked.
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
	 * Returns the decline of an authorization the store refused before sending it, unapplied, for the caller to settle as a decline is settled.
	 *
	 * For a placement whose authorization the gateway's matrix, or the gateway itself, refused
	 * before any request was built (require(), Gateways::get()): the provider never saw the intent,
	 * so nothing waits for its answer, and the order is released as for a decline. The decline names
	 * no provider object, and its machine code is NOT_SENT. One plain read of the intent.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.intent_not_found`.
	 *
	 * @param string $intentUuid The intent.
	 * @return GatewayResult The decline, from the intent's gateway.
	 */
	public function unsent( string $intentUuid ): GatewayResult {
		$intent = $this->find( $intentUuid );

		return new GatewayResult( $intent->gatewayId, Operation::Authorize, Outcome::Declined, $intent->uuid, $intent->amount, null, null, self::NOT_SENT );
	}

	/**
	 * Reads one intent as reconciliation sees it, for a shopper's client that resumes its payment: one plain read.
	 *
	 * @since 0.2.0
	 *
	 * @param string $intentUuid The intent's public identifier.
	 * @return IntentRef|null The intent, with its gateway, mode, age and wait; or null when there is none.
	 */
	public function intentRef( string $intentUuid ): ?IntentRef {
		return $this->payments->ref( $intentUuid );
	}

	/**
	 * Finds an intent by its gateway's own reference to it, for a provider's result that names no intent of the store's: one plain read, on the gateway's unique key.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId        The gateway.
	 * @param string $providerIntentId The gateway's reference to the intent, as the intent recorded it.
	 * @return IntentRef|null The intent, with its gateway, mode, age and wait; or null when no intent of the gateway has the reference.
	 */
	public function intentByProvider( string $gatewayId, string $providerIntentId ): ?IntentRef {
		return $this->payments->findByProvider( $gatewayId, $providerIntentId );
	}

	/**
	 * Finds the ledger row that holds a result: the same provider object and operation, in the same outcome. One plain read, on the ledger's key.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result, naming its provider object.
	 * @return int|null The row; null when the ledger holds no such result, or the result names no object.
	 */
	public function ledgerRowOf( GatewayResult $result ): ?int {
		return $this->payments->findResult( $result );
	}

	/**
	 * Asks the gateway where an intent stands, outside any transaction, and returns its answer unapplied.
	 *
	 * The query carries the intent's expiry as it was read, so a gateway can answer an intent
	 * waiting past it as expired.
	 *
	 * A provider's "not found" ends a placement, so the plugin decides when it may be believed, not
	 * the gateway: only from a provider its gateway declares searchable, and only about an intent
	 * older than the provider's search delay and STALE_SECONDS, by the database's clock. Before
	 * that, the provider may simply not have found the intent yet: the answer is taken for none, and
	 * reported NOT_FOUND_IGNORED.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Believes a provider's "not found" only when it may.
	 *
	 * @throws \LogicException     Inside a transaction, before the call.
	 * @throws CodedException      `payment.operation_unsupported`; `payment.gateway_unavailable`.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param IntentRef $intent The intent.
	 * @return GatewayResult|null The gateway's answer, a declined authorization when it has no record of the intent or it expired; null while it is still deciding.
	 */
	public function queryGateway( IntentRef $intent ): ?GatewayResult {
		$this->requireNoTransaction( __FUNCTION__ );
		$this->require( $intent->gatewayId, Operations::QUERY, $intent->mode, $intent->amount->currency() );

		$answer = $this->gateways->get( $intent->gatewayId, $intent->mode )->query( new PaymentQuery( $intent->uuid, $intent->providerIntentId, $intent->amount, $intent->mode, $intent->waitEndsAt, $intent->waitEnded ) );

		if ( null === $answer || ! self::saysNotFound( $answer ) || $this->mayBelieveNotFound( $intent ) ) {
			return $answer;
		}

		( $this->report )(
			self::NOT_FOUND_IGNORED,
			array(
				'gateway_id'  => $intent->gatewayId,
				'intent_uuid' => $intent->uuid,
				'age_seconds' => $intent->ageSeconds,
			)
		);

		return null;
	}

	/**
	 * Tells whether an answer says the provider has no record of the intent.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $answer The answer to a status query.
	 * @return bool True for a declined authorization whose error code is PaymentGateway::NOT_FOUND.
	 */
	private static function saysNotFound( GatewayResult $answer ): bool {
		return Operation::Authorize === $answer->operation && Outcome::Declined === $answer->outcome && PaymentGateway::NOT_FOUND === $answer->errorCode;
	}

	/**
	 * Tells whether a provider's "not found" about an intent may be believed: the provider can be searched, and the intent is old enough for it to have been found.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef $intent The intent, with its age by the database's clock.
	 * @return bool True when the intent is older than STALE_SECONDS and the provider's search delay, and the provider is searchable.
	 */
	private function mayBelieveNotFound( IntentRef $intent ): bool {
		$profile = $this->gateways->descriptor( $intent->gatewayId )->idempotency;

		return $profile->searchable && $intent->ageSeconds >= max( self::STALE_SECONDS, $profile->searchDelaySeconds );
	}

	/**
	 * Refuses an operation an intent's gateway does not declare, before anything is asked of the gateway.
	 *
	 * The gateway's capability matrix is its one declaration of what it can do: the cell of the
	 * intent's currency and the country of the gateway's account for the intent's mode must declare
	 * the operation. Every call to a gateway is checked here first, an authorization included,
	 * which the checkout's availability check also checked before the order was written.
	 * Reads nothing for a gateway without an account country.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.operation_unsupported`, naming the gateway and the operation;
	 *                        `payment.gateway_unavailable` when the gateway is not registered.
	 *
	 * @param string   $gatewayId The intent's gateway.
	 * @param string   $operation A name of Operations::ALL.
	 * @param Mode     $mode      The intent's mode, whose account's country selects the matrix's row.
	 * @param Currency $currency  The intent's currency.
	 */
	public function require( string $gatewayId, string $operation, Mode $mode, Currency $currency ): void {
		$matrix = $this->gateways->descriptor( $gatewayId )->matrix;

		if ( ! $matrix->allows( $operation, $currency, $this->gateways->accountCountry( $gatewayId, $mode ) ) ) {
			CodedException::raise(
				PaymentError::OperationUnsupported,
				array(
					'gateway_id' => $gatewayId,
					'operation'  => $operation,
				)
			);
		}
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
	 * @throws \LogicException For a partial refund in a converted currency given no base amount, whose base share is the refund
	 *                         service's to allocate; or for a base amount in another currency than the order's base currency.
	 *
	 * @param GatewayResult   $result     The result.
	 * @param Actor           $actor      On whose authority.
	 * @param Money|null      $baseAmount A refund's base amount, as allocated; null for none.
	 * @param VoidReason|null $voidReason Why a void was asked for; null for anything else.
	 * @return Application What was done.
	 */
	private function applyMoneyFact( GatewayResult $result, Actor $actor, ?Money $baseAmount, ?VoidReason $voidReason ): Application {
		$intent   = $this->lock( $result->intentUuid );
		$order    = $this->orders->lockForPayment( $intent->orderId );
		$approved = Outcome::Approved === $result->outcome;

		if ( $approved ? IntentTransitions::isStaleApproval( $result->operation, $intent->status ) : IntentTransitions::isStaleDecline( $result->operation, $intent->status ) ) {
			return $this->stale( $result, $intent, $order );
		}

		// The provider moved money the intent's state cannot take, such as an authorization after the intent failed: a person settles it.
		if ( $approved && ! IntentTransitions::isAllowed( $intent->status, self::stateAfter( $result, $intent ) ) ) {
			$ended = Operation::Authorize === $result->operation && IntentTransitions::isFinal( $intent->status );

			return $this->keepUnapplied( $result, $intent, $order, $ended ? self::LATE_APPROVAL : self::UNEXPECTED_RESULT, $actor );
		}

		if ( $approved && ! AmountCheck::accepts( $result, $intent, $order ) ) {
			return $this->keepUnapplied( $result, $intent, $order, self::AMOUNT_MISMATCH, $actor );
		}

		if ( null !== $baseAmount && ! $baseAmount->currency()->equals( $order->baseCurrency() ) ) {
			throw new \LogicException( 'A refund\'s base amount is in the order\'s base currency.' );
		}

		$base = $baseAmount ?? self::baseAmount( $intent, $result, $order );

		if ( $approved && null === $base ) {
			throw new \LogicException( 'Only the intent\'s whole amount has a base amount here when the order is in a converted currency: a partial refund\'s base share is the refund service\'s to allocate from the order\'s tax components.' );
		}

		list( $actorType, $actorId ) = self::actorOf( $actor );

		$transactionId = $this->payments->appendResult( $intent, $result, $base ?? Money::zero( $order->baseCurrency() ), true, $actorType, $actorId, $this->correlation->current() );

		if ( null === $transactionId ) {
			return $this->unchanged( ApplicationKind::Duplicate, $result, $intent, $order, $this->payments->findResult( $result ) );
		}

		if ( $approved ) {
			return $this->approve( $result, $intent, $order, $transactionId, $base, $actor, $voidReason );
		}

		// A declined refund gives nothing back: the intent stays captured and the order as it was.
		return Operation::Refund === $result->operation
			? $this->unchanged( ApplicationKind::Declined, $result, $intent, $order, $transactionId )
			: $this->decline( $result, $intent, $order, $transactionId, $actor );
	}

	/**
	 * Answers a result about a state the intent has left: stale, which changes nothing and writes no row; or a duplicate, when the ledger has the very same result already.
	 *
	 * One read of the ledger, by the key that claims a result, tells the two apart: the same decline
	 * delivered again after it failed the intent was applied once, and is a duplicate; an earlier
	 * attempt's decline after a later attempt was authorized never was.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result The result.
	 * @param PaymentIntent $intent The intent, locked.
	 * @param LockedOrder   $order  The order, locked.
	 * @return Application Stale, or a duplicate.
	 */
	private function stale( GatewayResult $result, PaymentIntent $intent, LockedOrder $order ): Application {
		$recorded = $this->payments->findResult( $result );

		return $this->unchanged( null === $recorded ? ApplicationKind::Stale : ApplicationKind::Duplicate, $result, $intent, $order, $recorded );
	}

	/**
	 * Keeps an approval that moves no money, for a person: its ledger row with `applied = 0`, and the order flagged and parked.
	 *
	 * The row is the claim, so a result recorded before is a duplicate, which changes nothing.
	 * Every writer of such a row keeps this order, a provider's delivery included: it locks the
	 * order, appends the row dated after the order's last clearance, then raises the flag, so a
	 * clearance, which takes the same lock, comes after every row it clears and before every row
	 * that holds the refunds back again, whatever the database clock does.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The row is dated after the order's last clearance.
	 *
	 * @param GatewayResult $result The approval.
	 * @param PaymentIntent $intent The intent, locked.
	 * @param LockedOrder   $order  The order, locked.
	 * @param string        $reason Why the order is parked, such as `amount_mismatch`.
	 * @param Actor         $actor  On whose authority.
	 * @return Application A mismatch, or a duplicate.
	 */
	private function keepUnapplied( GatewayResult $result, PaymentIntent $intent, LockedOrder $order, string $reason, Actor $actor ): Application {
		list( $actorType, $actorId ) = self::actorOf( $actor );

		$transactionId = $this->payments->appendResult( $intent, $result, Money::zero( $order->baseCurrency() ), false, $actorType, $actorId, $this->correlation->current(), $order->moneyReconciledAt );

		if ( null === $transactionId ) {
			return $this->unchanged( ApplicationKind::Duplicate, $result, $intent, $order, $this->payments->findResult( $result ) );
		}

		$parked = $this->orders->park( $order, $reason, $actor );

		return $this->application( ApplicationKind::Mismatch, $result, $intent, $order, $transactionId, $intent->status, $order->paymentStatus, $parked?->to, $reason );
	}

	/**
	 * Moves an approval's money: the intent, the order's payment amounts and, for a pending order, its acceptance.
	 *
	 * A void moves no money: voided() settles the order instead.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Applies a void.
	 *
	 * @throws \LogicException For a void given no reason, which applyGatewayResult() refuses before any statement.
	 *
	 * @param GatewayResult   $result        The approval.
	 * @param PaymentIntent   $intent        The intent, locked.
	 * @param LockedOrder     $order         The order, locked.
	 * @param int             $transactionId Its ledger row.
	 * @param Money           $base          Its amount in the base currency.
	 * @param Actor           $actor         On whose authority.
	 * @param VoidReason|null $voidReason    Why a void was asked for; null for anything else.
	 * @return Application What was done.
	 */
	private function approve( GatewayResult $result, PaymentIntent $intent, LockedOrder $order, int $transactionId, Money $base, Actor $actor, ?VoidReason $voidReason ): Application {
		if ( ! $this->payments->applyApproval( $intent, $result, $base, $voidReason ) ) {
			$this->refuse( $result, $intent );
		}

		if ( Operation::Void === $result->operation ) {
			return $this->voided( $result, $intent, $order, $transactionId, $voidReason ?? throw new \LogicException( 'A void is applied with its reason.' ), $actor );
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
	 * Settles a void the intent's update applied: no money moves, the order's payment status derives voided, and the order follows.
	 *
	 * An order still pending payment is cancelled, for the void's reason. An order already
	 * accepted is left to the person who asked the store for the void; one voided without a person
	 * asking the store, as when the shopper's time to act ran out while their approval was landing,
	 * or when the provider reports a void made in its dashboard, is flagged and parked for a
	 * person, with what it holds kept (parkReasonOf()).
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $result        The void.
	 * @param PaymentIntent $intent        The intent, locked, as it was before the void.
	 * @param LockedOrder   $order         The order, locked.
	 * @param int           $transactionId Its ledger row.
	 * @param VoidReason    $reason        Why it was asked for.
	 * @param Actor         $actor         On whose authority.
	 * @return Application What was done.
	 */
	private function voided( GatewayResult $result, PaymentIntent $intent, LockedOrder $order, int $transactionId, VoidReason $reason, Actor $actor ): Application {
		$this->events->publish( new PaymentVoided( $intent->id, $intent->orderId, $result->amount->minorUnits(), $result->amount->currency()->code(), $transactionId, $reason->value, $this->clock->now() ) );

		$paymentTo = $this->record( $order, self::nothing( $order ), IntentStatus::Voided, self::reasonOf( Operation::Void ), $actor );
		$orderTo   = null;

		if ( OrderStatus::PendingPayment === $order->status ) {
			$orderTo = $this->orders->transition( $order->id, OrderStatus::Cancelled, $reason->value, $actor )->to;
		} elseif ( null !== self::parkReasonOf( $reason ) ) {
			$orderTo = $this->orders->park( $order, self::parkReasonOf( $reason ), $actor )?->to;
		}

		return $this->application( ApplicationKind::Applied, $result, $intent, $order, $transactionId, IntentStatus::Voided, $paymentTo, $orderTo );
	}

	/**
	 * Returns the reason an accepted order is parked with when its payment is voided for a reason nobody gave the store.
	 *
	 * @since 0.2.0
	 *
	 * @param VoidReason $reason Why the void was asked for.
	 * @return string|null VOIDED_AFTER_APPROVAL for the end of a shopper's time to act, VOIDED_EXTERNALLY for a void the
	 *                     provider reports on its own; null for a reason a person gave the store, who decides the order.
	 */
	private static function parkReasonOf( VoidReason $reason ): ?string {
		return match ( $reason ) {
			VoidReason::ActionWindowEnded => self::VOIDED_AFTER_APPROVAL,
			VoidReason::VoidedExternally  => self::VOIDED_EXTERNALLY,
			default                       => null,
		};
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
	 * intent already waiting, and is a duplicate; the locked read is what tells so. One delivered
	 * after the intent moved on, authorized or ended, or still deciding after the customer was asked
	 * to act, is stale and changes nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A wait reported for an intent past waiting is stale, no longer refused.
	 *
	 * @throws CodedException `payment.intent_not_found`.
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
			return $this->unchanged( $to === $intent->status ? ApplicationKind::Duplicate : ApplicationKind::Stale, $result, $intent, $order, null );
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
	 * @param LockedOrder       $order    The order, locked.
	 * @param PaymentDelta      $delta    What the payment adds.
	 * @param IntentStatus|null $intentTo The intent's state after the payment; null for an order that has none.
	 * @param string            $reason   Why, for the order's event.
	 * @param Actor             $actor    On whose authority.
	 * @return PaymentStatus The order's payment status after.
	 */
	private function record( LockedOrder $order, PaymentDelta $delta, ?IntentStatus $intentTo, string $reason, Actor $actor ): PaymentStatus {
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
	 * Builds the Application of a result that changed nothing but its ledger row: a duplicate, a stale result, or a declined refund.
	 *
	 * @since 0.1.0
	 *
	 * @param ApplicationKind $kind          What happened.
	 * @param GatewayResult   $result        The result.
	 * @param PaymentIntent   $intent        The intent, locked.
	 * @param LockedOrder     $order         The order, locked.
	 * @param int|null        $transactionId The row the result was recorded in, first for a duplicate; null for an answer that moves no money.
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
	 * @param string|null      $reason        Optional. Why a mismatch was kept for a person. Default null.
	 * @return Application The Application.
	 */
	private function application( ApplicationKind $kind, GatewayResult $result, PaymentIntent $intent, LockedOrder $order, ?int $transactionId, IntentStatus $intentTo, PaymentStatus $paymentTo, ?OrderStatus $orderStatusTo, ?string $reason = null ): Application {
		return new Application( $kind, $intent->uuid, $result->operation, $transactionId, $order->id, $order->holdGroup, $intent->status, $intentTo, $order->paymentStatus, $paymentTo, $orderStatusTo, $reason );
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
	 * Whether a refund leaves the intent refunded or partly refunded depends on its amounts, so the
	 * projection decides it.
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
			Operation::Refund    => Projection::intentAfterRefund( $intent, $result->amount ),
			Operation::Void      => IntentStatus::Voided,
		};
	}

	/**
	 * Returns a result's amount in the order's base currency, when it has one without an allocation.
	 *
	 * The intent's whole amount is its frozen base amount, so an authorization, a void, or a
	 * capture of all of it adds all of it; a capture of part of it adds its share of the frozen
	 * base amount (CaptureShare). Any other amount has an exact base equivalent only when the order
	 * is in its base currency; otherwise its base share is the refund service's to allocate from the
	 * order's tax components, at the order's frozen rate. No rate is read here. The amount is in the
	 * order's base currency, which the amount check found the intent's to be, and which the intent's
	 * update and the order's projection each require again in their WHERE clauses.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 A capture of part of the intent's amount has its share.
	 *
	 * @param PaymentIntent $intent The intent.
	 * @param GatewayResult $result The result, with the amount in the currency the gateway reported.
	 * @param LockedOrder   $order  The intent's order, locked.
	 * @return Money|null The base amount, or null when it has none here.
	 */
	private static function baseAmount( PaymentIntent $intent, GatewayResult $result, LockedOrder $order ): ?Money {
		$amount = $result->amount;

		// A capture of at most the intent's amount, in its currency: what the amount check accepts, and what a decline may report.
		if ( Operation::Capture === $result->operation && $amount->currency()->equals( $intent->amount->currency() ) && $amount->compare( $intent->amount ) <= 0 ) {
			return Money::of( CaptureShare::baseOf( $intent, $amount )->minorUnits(), $order->baseCurrency() );
		}

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
