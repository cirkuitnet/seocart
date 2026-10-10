<?php
/**
 * RefundService: gives back part or all of what an order's customer paid, at the order's own rate
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\RefundableOrder;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\RefundRecorded;
use SEOCart\Payment\Domain\Refund\ClaimRequest;
use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Domain\Refund\ProviderRefundKind;
use SEOCart\Payment\Domain\Refund\ProviderRefundOutcome;
use SEOCart\Payment\Domain\Refund\LinePortion;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundAllocation;
use SEOCart\Payment\Domain\Refund\RefundClaim;
use SEOCart\Payment\Domain\Refund\RefundIdentity;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundPlan;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\RequestKey;
use SEOCart\Payment\Domain\Refund\Share;
use SEOCart\Payment\Domain\Refund\ShippingPortion;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CardNumbers;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Money;
use SEOCart\Support\TaxedMoney;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * Refunds some units of some lines of an order, and optionally what is left of its shipping, through the gateway it was paid through.
 *
 * Owns one fact: the order a refund happens in.
 *
 * 1. The capability `seocart_refund_orders`, before anything else; then the note: one that holds
 *    what reads as a card number is refused `payment.refund_note_rejected`, whoever asks, so it is
 *    never kept; then the schema gate, so a migration the refund's statements need, still
 *    outstanding, refuses the refund `store.unavailable` before its first read.
 * 2. With an idempotency key, the claim it names, by one read: none, and the refund goes on; a
 *    claim made with the key for another request refuses it `payment.refund_key_reused`; an ended
 *    claim answers it, as the same refund asked again does below, without a read of the order; a
 *    claim still open goes on, and is found again by the refund's identity.
 * 3. Outside any transaction, plain reads: the order with the lines asked for, its shipping and
 *    their tax components at its current totals version; its captured intent, with whether the
 *    ledger holds a result of it applied to nothing, which refuses the refund until a person has
 *    reconciled that money, and its oldest refund still claimed, which refuses any other refund
 *    of it (below); and what earlier refunds returned. RefundAllocation allocates the shares from
 *    those stored figures, and every cap is checked against the same reads: the units each line
 *    has left, what is left of each component and of the shipping, and what the intent captured
 *    and has not refunded, in both currencies. So is the cap of one order that RefundCapPolicy
 *    holds the user to: what the intent refunded and this refund's base share together. A refusal
 *    here happens before the gateway is asked, and leaves nothing behind.
 * 4. The refund's claim, in a short transaction of its own under the intent's lock, committed
 *    before the gateway is asked. For a user capped by the day, the user's lock row is taken
 *    first, before any read, so the sum below counts every claim of theirs committed while this one
 *    waited for it. The intent's lock reads, as they now stand, whether the intent has money
 *    a person must reconcile, which refuses the refund `payment.unreconciled` as the reads would
 *    have; what the refund's uuid is named by; and the intent's oldest claim still open. A claim of
 *    this refund already open is not made again: the gateway is asked what became of it (below).
 *    A claim of another refund open
 *    refuses this one `payment.refund_unresolved`, naming that claim. What the refund's uuid is
 *    named by having moved since the reads refuses it `payment.refund_retry`: another refund was
 *    recorded or declined meanwhile, and asking again works the refund out anew. The gateway's
 *    capability matrix is asked again, as its account may have moved to another country. The user's
 *    caps are checked again: of one order, from what the intent refunded in the base currency as it
 *    now stands, and of a day, from what the user asked of the gateway in the last 24 hours; past
 *    either, the refund is refused `payment.refund_cap_exceeded`, whole. Otherwise
 *    the claim is inserted, with the lines asked: the refund's uuid, the intent, the amount and its
 *    base share, the shipping, the reason and the note, the key's hash and the request's
 *    fingerprint, who asks and when. A refusal here writes nothing and asks nothing of the gateway.
 *    A request with a key that 3 or 4 refuses, whatever the refusal, reads its key once more:
 *    another request with the same key may have claimed the refund, and recorded it, since this
 *    one first looked, and so taken the units, a cap or the figures this one was worked out from;
 *    its claim, once ended, is the answer, and otherwise the refusal stands.
 * 5. The gateway's refund, at transaction depth 0: it may go over the network. Its idempotency key
 *    is the refund's uuid, derived from the refund itself (below).
 * 6. One transaction, which locks the intent and then the order: the gateway's answer applied to
 *    the ledger with the refund's base share (applyGatewayResult()), then the document, its lines
 *    and its components, each insert carrying its cap, the lines' refunded quantities by one
 *    conditional update, the claim ended `recorded`, the order's event of the refund
 *    (`refund_recorded`, naming it by its uuid, the actor and the request), then RefundRecorded,
 *    all in one savepoint.
 *    If anything there refuses or fails after the gateway gave the money back (a cap, or any other
 *    error), the savepoint takes it all back, the money is recorded for a person
 *    (recordUnapplied()), the claim ends `unreconciled`, and the caller is answered
 *    `payment.unreconciled`, with what failed as its previous exception. So is an approval of
 *    another amount than was asked. A decline is recorded on the ledger and ends the claim
 *    `declined`, and nothing else changes; a decline whose claim another request ended first is
 *    taken back with its savepoint, and the request is answered from the claim. An answer the
 *    ledger already had for another refund ends the claim `unreconciled`, naming no row, whatever
 *    its outcome: what the gateway did with this refund is not known. A lost deadlock and a
 *    transaction whose integrity was lost are not caught: there is no transaction left to record
 *    the money in. A deadlock runs the whole transaction again; when every attempt is lost,
 *    nothing was recorded, and the claim is left `claimed`.
 *
 * The refund's uuid is RefundIdentity's: a name-based uuid of the order, the units asked of each
 * line, whether the shipping is asked for, what the intent had refunded and how many of its
 * refunds were declined when it was read. A claim is made only under the intent's lock, only while
 * no other claim of the intent is open, and only from those figures as they then stand; while it is
 * open (`claimed`: asked of the gateway, its answer not recorded), the intent takes no other
 * refund, so those figures cannot move. So the same refund asked again while its claim is open
 * (after the answer was lost, after the process died between the gateway's call and the
 * transaction, or by two people at once) is always the same refund, and finds its claim. A refund
 * whose claim exists is never asked of the gateway again. It is answered from its claim: its
 * document when it was recorded, `payment.refund_declined` when it was declined,
 * `payment.unreconciled` when what the gateway answered was left for a person. To a request with an
 * idempotency key, a claim is answered only when that key made it: a claim of the same refund that
 * another key made, or no key, refuses the request `payment.refund_retry`, as another request is
 * refunding the same units and the answer is that request's. Asked again once that claim has
 * ended, the request is worked out anew, as a new refund. While the claim is
 * still `claimed`, the gateway is asked what became of the refund (queryRefund()): a refund it made,
 * or declined, is recorded as a first answer would be. A claim the gateway cannot account for waits
 * for a person: when it cannot say, or says it made no such refund, nothing is written, the claim
 * stays `claimed`, and the request is refused `payment.refund_unresolved`, naming the refund. A
 * not-found is never taken for a decline: the first request may still be on its way to the
 * gateway, however long ago it was claimed, and a refund made then would be a second one. So a
 * refund is given back at most once for its claim, even by a gateway that does not honour the key,
 * and a refund the gateway made whose answer was lost is recorded the next time it is asked for.
 *
 * What the claim does not cover:
 *
 * - a refund recorded whose answer the caller lost, asked again without a key: the intent's refunded
 *   amount moved, so the same request is a new refund, with a new claim, which the caps allow while
 *   the units are left. Only the key the caller sends tells a retry from a second refund of the same
 *   units, and the refund operation always sends one;
 * - nobody asking again: a claim the gateway's answer never ended stays `claimed`, and doctor's
 *   payments check reports it once it is older than PaymentService::STALE_SECONDS; nothing asks the
 *   gateway about it until the same refund is asked for again, and until then the intent takes no
 *   other refund;
 * - a claim the gateway cannot account for: it stays `claimed`, and every refund of the intent is
 *   refused until a person settles it, through settleClaim(), with a statement the gateway is
 *   asked to confirm once more first;
 * - a refund refused `payment.refund_retry`: its caller must ask for it again.
 *
 * A provider also reports a refund on its own, in a webhook delivery: recordProviderRefund()
 * settles it through the refund's claim, as the refund's own answer would be recorded, or keeps the
 * money for a person when no open claim accounts for it.
 *
 * Nothing here reads a rate, a tax rate, a price or the calculation: the order's conversion
 * context is copied, and every figure is a share of what the order stored.
 *
 * @since 0.1.0
 */
final class RefundService {

	/**
	 * The capability a refund needs, the refund operation's.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY = PaymentOperations::REFUND_CAPABILITY;

	/**
	 * The reason of the order event that records a refund.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const AUDIT_REASON = 'refund_recorded';

	/**
	 * The capability that settles a refund claim: overriding what the plugin knows of the money, with a person's say-so.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLE_CAPABILITY = PaymentOperations::SETTLE_CAPABILITY;

	/**
	 * The reason of the order event that records a person's settlement of a refund claim.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLED_REASON = 'refund_claim_settled';

	/**
	 * Why an order is flagged when a refund is recorded on a person's statement: the money is on their word.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SETTLED_BY_STATEMENT = 'refund_settled_by_statement';

	/**
	 * The refund statements.
	 *
	 * @since 0.1.0
	 *
	 * @var RefundRepository
	 */
	private RefundRepository $refunds;

	/**
	 * The order statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * The money path, which applies the gateway's answer to the ledger.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentService
	 */
	private PaymentService $payments;

	/**
	 * The gateways, which each refund's intent's own is found in.
	 *
	 * @since 0.2.0
	 *
	 * @var Gateways
	 */
	private Gateways $gateways;

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
	 * Says when an event happened.
	 *
	 * @since 0.1.0
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Says which refund caps a user is held to.
	 *
	 * @since 0.2.0
	 *
	 * @var RefundCapPolicy
	 */
	private RefundCapPolicy $caps;

	/**
	 * The order module's service, which records each refund among the order's events.
	 *
	 * @since 0.2.0
	 *
	 * @var Orders
	 */
	private Orders $orderEvents;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes the gateway registry in place of the one gateway, the policy of the refund caps, and the order module's service.
	 *
	 * @param RefundRepository   $refunds     The refund statements.
	 * @param OrderRepository    $orders      The order statements.
	 * @param PaymentService     $payments    The money path.
	 * @param Gateways           $gateways    The gateways.
	 * @param TransactionManager $tx          The unit of work.
	 * @param EventPublisher     $events      Publishes the events.
	 * @param Authorizer         $authorizer  Checks capabilities.
	 * @param Clock              $clock       Says when an event happened.
	 * @param RefundCapPolicy    $caps        Says which refund caps a user is held to.
	 * @param Orders             $orderEvents The order module's service, which records each refund among the order's events.
	 */
	public function __construct( RefundRepository $refunds, OrderRepository $orders, PaymentService $payments, Gateways $gateways, TransactionManager $tx, EventPublisher $events, Authorizer $authorizer, Clock $clock, RefundCapPolicy $caps, Orders $orderEvents ) {
		$this->refunds     = $refunds;
		$this->orders      = $orders;
		$this->payments    = $payments;
		$this->gateways    = $gateways;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->authorizer  = $authorizer;
		$this->clock       = $clock;
		$this->caps        = $caps;
		$this->orderEvents = $orderEvents;
	}

	/**
	 * Refunds an order as the refund operation asks, and answers with the refund.
	 *
	 * Sends no statement of its own before refund(). The idempotency key is required, 1 to
	 * IdempotencyKey::MAX_LENGTH bytes long; a request that asks for nothing, or names a line twice,
	 * is refused; refund() refuses a note that holds what reads as a card number. The key is hashed
	 * with the user who asks, the same user on every surface, so no two users share a key; the
	 * request's fingerprint is its canonical form's.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied`; `payment.refund_key_missing`, also for a key longer than
	 *                        IdempotencyKey::MAX_LENGTH bytes; `payment.refund_request_invalid`, naming the
	 *                        problem; `payment.refund_note_rejected`; what refund() raises.
	 *
	 * @param array<string, mixed> $input The prepared input: order_uuid, lines, shipping, reason_code, note and idempotency_key.
	 * @param Actor                $actor Who refunds.
	 * @return array<string, mixed> The refund, keyed by wire name.
	 */
	public function refundOrder( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, self::CAPABILITY );

		$key = (string) ( $input[ IdempotencyKey::FIELD ] ?? '' );

		// The header reaches here unchecked by the field's schema, which counts characters: a key the
		// hash does not take, empty or longer than its bytes allow, is refused before anything else.
		if ( ! IdempotencyKey::accepts( $key ) ) {
			CodedException::raise( PaymentError::RefundKeyMissing, array( 'max_bytes' => IdempotencyKey::MAX_LENGTH ) );
		}

		$request = self::requestOf( $input );
		$keyHash = IdempotencyKey::hash( PaymentOperations::REFUND_ORDER . '|' . $actor->userId(), $key );

		return self::answer( $this->refund( $request, $actor, new RequestKey( $keyHash, IdempotencyKey::fingerprint( $request->canonical() ) ) ), $request );
	}

	/**
	 * Refunds an order as asked, and returns the refund's document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException      Inside a transaction, before any statement: the gateway is called. Or when the claim
	 *                              the key names is open but is not the claim the same request names, naming both: a
	 *                              claim was changed outside this service.
	 * @throws GatewayUnavailable   When the gateway has no answer; nothing was recorded, and the claim is left `claimed`.
	 * @throws TransactionRetryable When every attempt to record the answer lost a deadlock; nothing was recorded, and the claim is left `claimed`.
	 * @throws CodedException       `authorization.denied`, before any read; `payment.refund_note_rejected` when the
	 *                              note holds what reads as a card number, before any read; `store.unavailable` while
	 *                              the schema gate is closed, before any read; `payment.refund_key_reused` when the key
	 *                              was sent before with another request, from the key's read alone; `order.not_found`;
	 *                              `payment.refund_not_refundable` when the order has no captured intent;
	 *                              `payment.gateway_unavailable` when the intent's gateway is not registered
	 *                              or its credentials for the intent's mode cannot be used;
	 *                              `payment.unreconciled` when the ledger holds a result of the intent
	 *                              applied to nothing; `payment.refund_unresolved`, naming the other
	 *                              refund, when another refund of the intent is still claimed;
	 *                              `payment.refund_line_not_found`;
	 *                              `payment.refund_line_exhausted` with the units the line has left;
	 *                              `payment.refund_exceeds_captured`; `payment.refund_nothing_left`;
	 *                              `payment.operation_unsupported` when the gateway does not declare the
	 *                              refund, or the partial refund — each before the refund is claimed and
	 *                              the gateway asked;
	 *                              `payment.refund_retry` when another refund was recorded or declined
	 *                              after this one was worked out, and `payment.refund_unresolved` when
	 *                              another refund was claimed meanwhile, both under the intent's lock,
	 *                              before the gateway is asked; `payment.refund_retry`, to a request
	 *                              with a key, when the same refund was claimed by another request with
	 *                              another key or none, before the gateway is asked;
	 *                              `payment.refund_cap_exceeded` when the
	 *                              refund is past one of the user's refund caps, before the claim or
	 *                              under its locks; `payment.refund_declined` when the
	 *                              gateway declined, now or when the refund was asked for before;
	 *                              `payment.unreconciled` when the gateway gave the money back but the
	 *                              refund could not be recorded, which is then left for a person, with
	 *                              what kept it from being recorded as the previous exception, when
	 *                              something did; and `payment.refund_unresolved`, with the refund's
	 *                              uuid, when the refund was asked for before and the gateway cannot
	 *                              account for it.
	 *
	 * @param RefundRequest   $request What to refund.
	 * @param Actor           $actor   Who refunds it.
	 * @param RequestKey|null $key     Optional. The idempotency key the caller sent, as the refund operation hashes it; null for
	 *                                 a refund asked without one. Default null.
	 * @return Refund The refund; for the same refund asked again, recorded since or found made by the gateway, its document; for
	 *                a request refused before the gateway was asked whose key another request ended a claim with meanwhile,
	 *                that claim's document.
	 */
	public function refund( RefundRequest $request, Actor $actor, ?RequestKey $key = null ): Refund {
		$this->authorizer->authorize( $actor, self::CAPABILITY );
		$this->requireNoTransaction();

		// A note is kept for as long as the order is: one that holds a card number is refused before
		// anything is read, so no caller can have it kept.
		if ( null !== $request->note && CardNumbers::contains( $request->note ) ) {
			CodedException::raise( PaymentError::RefundNoteRejected );
		}

		// Before the first read: a column the refund reads may belong to a migration still outstanding.
		$this->tx->refuseWhileClosed();

		$keyed = null === $key ? null : $this->claimNamedBy( $key );

		if ( null !== $keyed && ClaimState::Claimed !== $keyed->state ) {
			return $this->answerFrom( $keyed->uuid, $keyed );
		}

		try {
			$plan    = $this->plan( $request, $keyed?->uuid );
			$gateway = $this->gateways->get( $plan->intent->gatewayId, $plan->intent->mode );
			$caps    = $this->caps->for( $actor, $plan->order->baseCurrency );

			// The cap of one order, from the plain reads, refuses before the claim's transaction; it, and the
			// cap of a day, are decided under the locks.
			$caps->requirePerOrder( $plan->intent->baseRefunded, $plan->total->base->gross() );

			$claimed = $this->claim( $plan, $request, $actor, $key, $caps );
		} catch ( CodedException $refused ) {
			return $this->answerRefusedByKey( $refused, $key );
		}

		if ( ! $claimed ) {
			return $this->askedBefore( $plan, $gateway, $actor, $key );
		}

		return $this->record( $plan, $this->askGateway( $plan, $gateway ), $actor );
	}

	/**
	 * Settles a refund claim as the settle operation asks, and answers with how it ended.
	 *
	 * Sends no statement of its own before settleClaim().
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `authorization.denied`; what settleClaim() raises.
	 *
	 * @param array<string, mixed> $input The prepared input: refund_uuid, statement, note, and provider_refund_id and amount_minor when given.
	 * @param Actor                $actor Who settles it.
	 * @return array<string, mixed> The settlement, keyed by wire name.
	 */
	public function settleRefundClaim( array $input, Actor $actor ): array {
		$this->authorizer->authorize( $actor, self::SETTLE_CAPABILITY );

		$statement = new ClaimStatement(
			ClaimStatement::REFUNDED === (string) $input['statement'],
			(string) $input['note'],
			isset( $input['provider_refund_id'] ) ? (string) $input['provider_refund_id'] : null,
			isset( $input['amount_minor'] ) ? (int) $input['amount_minor'] : null
		);

		return $this->settleClaim( (string) $input['refund_uuid'], $statement, $actor )->toArray();
	}

	/**
	 * Settles a refund claim the gateway could not account for, on a person's say-so, and answers with how it ended.
	 *
	 * Nothing automatic settles a claim: only a person allowed to override what the plugin knows of
	 * the money, through this method, which the settle operation calls.
	 *
	 * 1. The capability `seocart_override_money_state`, before anything else; then the statement:
	 *    one whose note holds what reads as a card number is refused `payment.refund_note_rejected`,
	 *    and one that cannot settle a claim `payment.refund_statement_incomplete`; then the schema
	 *    gate, before the first read.
	 * 2. The claim, with what it asked: none is `payment.refund_claim_not_found`; one that has ended
	 *    is `payment.refund_claim_ended`, with how it ended.
	 * 3. The refund worked out again from what the claim asked, as a refund is worked out: the claim
	 *    must still be the intent's open claim, and the refund the one it claimed. Neither money a
	 *    person has not reconciled nor the gateway's capability matrix holds it back. A statement
	 *    that the refund was made, naming a provider's refund the ledger holds already, is refused
	 *    `payment.refund_statement_incomplete` (`already_recorded`) by one read of the ledger's key:
	 *    that refund is another's, and recorded for this claim it would meet the key and end the
	 *    claim unreconciled with no row to show for it, which nothing could then settle.
	 * 4. The gateway asked once more what became of the refund, at transaction depth 0, through the
	 *    intent's own gateway, when it is registered, usable in the intent's mode and declares the
	 *    query. What it says is kept with the claim: `approved`, `declined`, `not_found`,
	 *    `cannot_say`, or `unavailable`, with why when the plugin knows.
	 * 5. A refund the gateway made, or declined, is what is recorded, whatever the person stated:
	 *    the gateway decides. Only when it cannot say does the statement decide: a refund stated made
	 *    is recorded as the gateway's approval would be, of the provider's refund and the amount the
	 *    person names; a refund stated not made is no answer at all.
	 * 6. One transaction, which takes the intent's lock first, as every refund does. Under it the
	 *    claim must still be open: a late answer of the first request, a retry, a provider's
	 *    delivery or another settlement may have ended it since it was read, each under the same
	 *    lock, and the person is then answered `payment.refund_claim_ended`, with how it ended, with
	 *    nothing written. An answer is recorded as any refund's answer is: an approval of the claimed
	 *    amount records the refund; a decline ends the claim declined, with its ledger row; another
	 *    amount, or another refund's provider object, is left for a person, the order flagged and
	 *    parked. A refund stated not made ends the claim declined with no ledger row: a person's
	 *    statement is not a gateway's decline. Then the settlement is noted on the claim, once, and
	 *    the order's event records it (`refund_claim_settled`, naming the refund). A refund recorded
	 *    on a person's statement flags the order, without parking it: the money is on their word
	 *    until a person clears the flag.
	 * 7. The answer, once the transaction has committed: how the claim ended, what decided it, what
	 *    the gateway said, and whether the order holds unreconciled money, read then. Money left for a
	 *    person is an answer here, not an error: reconciling it is what the person is doing.
	 *
	 * A claim that ends declined, by the gateway or by the statement, frees its intent, and the same
	 * refund asked for again is a new refund. A settle retried after its process died before the
	 * transaction settles the claim then, as nothing was written; after it, the retry is told how
	 * the claim ended.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException      Inside a transaction, before any statement: the gateway is asked. Or when the refund
	 *                              worked out again is not the claim's own, or the settlement could not be noted: a claim
	 *                              was changed outside this service.
	 * @throws TransactionRetryable When every attempt lost a deadlock; nothing was written, and the claim is left `claimed`.
	 * @throws CodedException       `authorization.denied`; `payment.refund_note_rejected`; `payment.refund_statement_incomplete`,
	 *                              naming the problem; `store.unavailable` while the schema gate is closed;
	 *                              `payment.refund_claim_not_found`; `payment.refund_claim_ended`, with how it ended;
	 *                              `payment.refund_statement_incomplete` (`already_recorded`) for a provider's refund
	 *                              the ledger holds, before the gateway is asked.
	 *
	 * @param string         $uuid      The refund's uuid, which names its claim.
	 * @param ClaimStatement $statement What the person states, and why.
	 * @param Actor          $actor     Who settles it.
	 * @return SettledClaim How the claim ended.
	 */
	public function settleClaim( string $uuid, ClaimStatement $statement, Actor $actor ): SettledClaim {
		$this->authorizer->authorize( $actor, self::SETTLE_CAPABILITY );
		$this->requireNoTransaction();
		self::requireSettling( $statement );

		// Before the first read: the columns a settlement writes may belong to a migration still outstanding.
		$this->tx->refuseWhileClosed();

		$claim = $this->refunds->claimRequest( $uuid ) ?? CodedException::raise( PaymentError::RefundClaimNotFound, array( 'refund_uuid' => $uuid ) );

		if ( ClaimState::Claimed !== $claim->state ) {
			CodedException::raise( PaymentError::RefundClaimEnded, array( 'state' => $claim->state->value ) );
		}

		$plan   = $this->planOfClaim( $claim, true );
		$stated = self::statedResult( $plan, $statement );

		// The provider's refund the person names is another refund's when the ledger holds it already.
		if ( null !== $stated && $this->refunds->holdsResult( $stated ) ) {
			self::refuseStatement( ClaimStatement::ALREADY_RECORDED );
		}

		list( $reading, $answer ) = $this->askOnceMore( $plan );
		$decidedBy                = null !== $answer && SettledClaim::decides( $reading ) ? SettledClaim::BY_GATEWAY : SettledClaim::BY_STATEMENT;
		$result                   = SettledClaim::BY_GATEWAY === $decidedBy ? $answer : $stated;

		try {
			$state = $this->tx->transaction( fn(): ClaimState => $this->settleLocked( $plan, $result, $statement, $reading, $decidedBy, $actor ), RetryPolicy::deadlocks() );
		} catch ( ClaimEndedElsewhere $ended ) {
			$this->claimEnded( $uuid );
		}

		$order   = $this->orders->reconciliation( $plan->order->uuid );
		$flagged = null !== $order && $order['has_unreconciled_money'];

		return new SettledClaim( $uuid, $plan->order->uuid, $state, $decidedBy, $reading, $flagged );
	}

	/**
	 * Records a refund result the provider delivered on its own, through the claim of the refund it names: the store's own answer when the claim is still open, and money kept for a person when no open claim accounts for it.
	 *
	 * For the webhook receiver, at transaction depth 0, on no user's authority: it checks no
	 * capability, never asks the gateway, and never refuses a money outcome, which it answers
	 * instead. The claim is the one the provider echoed the uuid of, read with what it asked; a claim
	 * of another intent than the result's is none.
	 *
	 * - **An open claim**: its refund is worked out again from what it asked, as a person's
	 *   settlement works it out; money a person has not reconciled does not hold it back, as the
	 *   result is itself money landing. Under the intent's lock the result is then recorded as the
	 *   refund's own answer would be, `Recorded` with the document or `Declined` with the decline,
	 *   unless it cannot be the claim's refund. Then an approval is kept for a person and the claim
	 *   ends `Unreconciled` with why: PaymentService::EXTERNAL_REFUND when what the claim asked now
	 *   works out to another refund, PaymentService::AMOUNT_MISMATCH when the provider gave back
	 *   another amount, PaymentService::OPERATION_UNSUPPORTED when the gateway's capability matrix
	 *   no longer declares the refund; a decline still ends the claim `Declined`, with the base
	 *   share the claim asked for. Money the refund's own answer could not record after it moved,
	 *   such as a cap refusing, is `Unreconciled` under PaymentService::PAYMENT_UNRECORDED. A result
	 *   that moves no money, such as one still pending, is `Ignored`.
	 * - **An ended claim**, whether its own answer or a person's settlement ended it, or no claim at
	 *   all (no uuid, an unknown one, a refund made in the provider's dashboard): an approval is
	 *   money kept for a person, under PaymentService::EXTERNAL_REFUND,
	 *   the order flagged and parked, through PaymentService::recordUnapplied(), which locks the
	 *   order, appends the row dated after the order's last clearance and only then raises the flag:
	 *   `Unexpected`, or `Duplicate` when the ledger has the result already, as the claim's own answer
	 *   or an earlier delivery wrote it. Never a second row applied. A decline of a refund the ledger
	 *   holds as made is the provider taking the refund back: kept for a person the same way, under
	 *   PaymentService::REFUND_REVERSED, `Reversed`. Anything else is `Ignored`.
	 *
	 * A claim that ends between its read and the intent's lock, by the refund's own answer or by a
	 * person's settlement, is answered as it then ended.
	 *
	 * Also thrown: TransactionRetryable when every attempt lost a deadlock, with nothing recorded;
	 * `payment.intent_not_found` when the result's intent is gone; and what working the refund out
	 * again refuses, but the claim's having ended.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction, or for a result that is not a refund's, before any statement.
	 * @phpstan-throws \LogicException|TransactionRetryable|CodedException
	 *
	 * @param string|null   $refundUuid The refund uuid the provider echoed: the key the store asked it with; null for none.
	 * @param GatewayResult $result     The provider's result, of the store's intent.
	 * @param Actor         $actor      On whose authority: the webhook's.
	 * @return ProviderRefundOutcome What the result came to.
	 */
	public function recordProviderRefund( ?string $refundUuid, GatewayResult $result, Actor $actor ): ProviderRefundOutcome {
		$this->requireNoTransaction();

		if ( Operation::Refund !== $result->operation ) {
			throw new \LogicException( 'RefundService::recordProviderRefund() records a refund\'s result, never another operation\'s.' );
		}

		$claim = null === $refundUuid ? null : $this->refunds->claimRequest( $refundUuid );

		// A claim of another intent is another payment's refund: this result does not answer it.
		if ( null !== $claim && $claim->intentUuid !== $result->intentUuid ) {
			$claim = null;
		}

		if ( null === $claim || ClaimState::Claimed !== $claim->state ) {
			return $this->keepProviderRefund( $claim, $result, $actor );
		}

		try {
			return $this->recordClaimed( $claim, $result, $actor );
		} catch ( ClaimEndedElsewhere $ended ) {
			// The claim ended since it was read, under the intent's lock: the result meets it as it ended.
			return $this->keepProviderRefund( $this->refunds->claimRequest( $claim->uuid ), $result, $actor );
		}
	}

	/**
	 * Records a provider's refund result as the answer of the refund's open claim, worked out again from what the claim asked, or keeps it for a person when it cannot be the claim's refund.
	 *
	 * @since 0.2.0
	 *
	 * @throws ClaimEndedElsewhere When the claim ended since it was read: before it was worked out again, or before the intent's lock.
	 * @throws CodedException      What working the refund out again refuses, but the claim's having ended.
	 * @phpstan-throws ClaimEndedElsewhere|CodedException|\LogicException
	 *
	 * @param ClaimRequest  $claim  The claim, open when it was read.
	 * @param GatewayResult $result The provider's result.
	 * @param Actor         $actor  On whose authority.
	 * @return ProviderRefundOutcome How the claim ended, or `Ignored` for a result that moves no money.
	 */
	private function recordClaimed( ClaimRequest $claim, GatewayResult $result, Actor $actor ): ProviderRefundOutcome {
		if ( ! in_array( $result->outcome, array( Outcome::Approved, Outcome::Declined ), true ) ) {
			return new ProviderRefundOutcome( ProviderRefundKind::Ignored, $claim->uuid, null, $result->outcome->value );
		}

		try {
			$plan = $this->planOfClaim( $claim, true, true );
		} catch ( CodedException $refused ) {
			if ( PaymentError::RefundClaimEnded === $refused->errorCode() ) {
				throw new ClaimEndedElsewhere( 'The refund\'s claim ended before the provider\'s answer was recorded.', 0, $refused );
			}

			throw $refused;
		}

		$kept = $this->keptReason( $claim, $plan, $result );

		return self::outcomeOf(
			$this->tx->transaction(
				function () use ( $claim, $plan, $result, $actor, $kept ): RefundClaim {
					$this->lockOpenClaim( $plan, $claim->uuid, 'The refund\'s claim ended before the provider\'s answer was recorded.' );

					return null === $kept ? $this->recordAndRead( $plan, $result, $actor ) : $this->keepAgainstClaim( $claim, $result, $actor, $kept );
				},
				RetryPolicy::deadlocks()
			),
			$kept ?? PaymentService::PAYMENT_UNRECORDED
		);
	}

	/**
	 * Says why a provider's result cannot be the answer of the refund its open claim asked for, or null when it can.
	 *
	 * A decline can always end the claim: it moves no money. An approval cannot when what the claim
	 * asked now works out to another refund, when it gave back another amount than that refund's, or
	 * when the gateway's capability matrix no longer declares that refund.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException A refusal of the matrix's check other than the refund's not being declared.
	 *
	 * @param ClaimRequest  $claim  The claim, open when it was read.
	 * @param RefundPlan    $plan   The refund, worked out again from what the claim asked.
	 * @param GatewayResult $result The provider's result: an approval or a decline.
	 * @return string|null Why the money is kept for a person; null when the result is the claim's answer.
	 */
	private function keptReason( ClaimRequest $claim, RefundPlan $plan, GatewayResult $result ): ?string {
		if ( $plan->uuid !== $claim->uuid ) {
			return PaymentService::EXTERNAL_REFUND;
		}

		if ( Outcome::Approved !== $result->outcome ) {
			return null;
		}

		if ( ! $result->amount->equals( $plan->total->amount->gross() ) ) {
			return PaymentService::AMOUNT_MISMATCH;
		}

		try {
			$this->requireDeclared( $plan );
		} catch ( CodedException $undeclared ) {
			if ( PaymentError::OperationUnsupported !== $undeclared->errorCode() ) {
				throw $undeclared;
			}

			return PaymentService::OPERATION_UNSUPPORTED;
		}

		return null;
	}

	/**
	 * Ends an open claim with a provider's result that cannot be its refund, in the caller's transaction under the intent's lock: an approval is kept for a person, a decline declines the claim.
	 *
	 * The approval goes through PaymentService::recordUnapplied(), which locks the order, appends the
	 * row dated after the order's last clearance and only then raises the flag. A decline is
	 * recorded with the base share the claim asked for, as the claim's own decline would be.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the claim is gone, which nothing deletes.
	 *
	 * @param ClaimRequest  $claim  The claim, open under the lock.
	 * @param GatewayResult $result The provider's result.
	 * @param Actor         $actor  On whose authority.
	 * @param string        $reason Why an approval is kept for a person.
	 * @return RefundClaim The claim as it ended: unreconciled, or declined.
	 */
	private function keepAgainstClaim( ClaimRequest $claim, GatewayResult $result, Actor $actor, string $reason ): RefundClaim {
		$this->endWithout(
			$claim->uuid,
			$result,
			Outcome::Approved === $result->outcome ? $this->payments->recordUnapplied( $result, $actor, $reason ) : $this->payments->applyGatewayResult( $result, $actor, $claim->baseShare )
		);

		return $this->refunds->findClaim( $claim->uuid ) ?? throw new \LogicException( sprintf( 'The claim of refund %s is gone.', $claim->uuid ) );
	}

	/**
	 * Says what a provider's result came to from how it ended the refund's claim.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException For a claim still open, which the answer always ends.
	 *
	 * @param RefundClaim $ended  The claim, as the answer ended it.
	 * @param string      $reason Why money is kept for a person, for a claim ended unreconciled.
	 * @return ProviderRefundOutcome `Recorded`, `Declined`, or `Unreconciled` with the money kept for a person.
	 */
	private static function outcomeOf( RefundClaim $ended, string $reason ): ProviderRefundOutcome {
		return match ( $ended->state ) {
			ClaimState::Recorded     => new ProviderRefundOutcome( ProviderRefundKind::Recorded, $ended->uuid, $ended->transactionId ),
			ClaimState::Declined     => new ProviderRefundOutcome( ProviderRefundKind::Declined, $ended->uuid, $ended->transactionId ),
			ClaimState::Unreconciled => new ProviderRefundOutcome( ProviderRefundKind::Unreconciled, $ended->uuid, $ended->transactionId, $reason ),
			ClaimState::Claimed      => throw new \LogicException( sprintf( 'The claim of refund %s is still open once its answer was recorded.', $ended->uuid ) ),
		};
	}

	/**
	 * Keeps a provider's refund that no open claim accounts for: an approval is money for a person, and so is a decline of a refund the ledger holds as made; anything else changes nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param ClaimRequest|null $claim  The ended claim the result names, or null for none.
	 * @param GatewayResult     $result The provider's result.
	 * @param Actor             $actor  On whose authority.
	 * @return ProviderRefundOutcome `Unexpected`, `Reversed`, `Duplicate` or `Ignored`.
	 */
	private function keepProviderRefund( ?ClaimRequest $claim, GatewayResult $result, Actor $actor ): ProviderRefundOutcome {
		$reason = match ( true ) {
			Outcome::Approved === $result->outcome                                   => PaymentService::EXTERNAL_REFUND,
			Outcome::Declined === $result->outcome && $this->recordedAsMade( $result ) => PaymentService::REFUND_REVERSED,
			default                                                                  => null,
		};

		if ( null === $reason ) {
			return new ProviderRefundOutcome( ProviderRefundKind::Ignored, $claim?->uuid, null, null === $claim ? ProviderRefundOutcome::NO_CLAIM : ProviderRefundOutcome::CLAIM_ENDED );
		}

		$kept = $this->tx->transaction( fn(): Application => $this->payments->recordUnapplied( $result, $actor, $reason ), RetryPolicy::deadlocks() );

		if ( ApplicationKind::Duplicate === $kept->kind ) {
			return new ProviderRefundOutcome( ProviderRefundKind::Duplicate, $claim?->uuid, $kept->transactionId );
		}

		return new ProviderRefundOutcome( PaymentService::REFUND_REVERSED === $reason ? ProviderRefundKind::Reversed : ProviderRefundKind::Unexpected, $claim?->uuid, $kept->transactionId, $reason );
	}

	/**
	 * Tells whether the ledger holds the refund a decline names as made: its approval of the same provider object, applied, with its document.
	 *
	 * Two plain reads: the approval's row by the ledger's key, and the document that states it,
	 * which only an applied refund has.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult $declined The provider's decline of a refund.
	 * @return bool True when the refund was recorded as made; false otherwise, or when the decline names no object.
	 */
	private function recordedAsMade( GatewayResult $declined ): bool {
		if ( null === $declined->providerObjectId ) {
			return false;
		}

		$made = $this->payments->ledgerRowOf( new GatewayResult( $declined->provider, Operation::Refund, Outcome::Approved, $declined->intentUuid, $declined->amount, $declined->providerObjectId, $declined->providerIntentId ) );

		return null !== $made && null !== $this->refunds->findByTransaction( $made );
	}

	/**
	 * Builds the request the refund operation's input asks for, refusing one a refund cannot be made from.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_request_invalid`, naming the problem.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return RefundRequest The request.
	 */
	private static function requestOf( array $input ): RefundRequest {
		$lines = array_map(
			static fn( array $line ): RefundLineRequest => new RefundLineRequest( (string) $line['line_uuid'], (int) $line['quantity'], (bool) ( $line['restock'] ?? false ) ),
			array_values( (array) ( $input['lines'] ?? array() ) )
		);

		$shipping = (bool) ( $input['shipping'] ?? false );
		$problem  = RefundRequest::problem( array_map( static fn( RefundLineRequest $line ): string => $line->lineUuid, $lines ), $shipping );

		if ( null !== $problem ) {
			CodedException::raise( PaymentError::RefundRequestInvalid, array( 'problem' => $problem ) );
		}

		$note = isset( $input['note'] ) ? (string) $input['note'] : null;

		return new RefundRequest( (string) $input['order_uuid'], $lines, $shipping, (string) $input['reason_code'], $note );
	}

	/**
	 * Answers the refund operation: the refund's money, and what was asked, in the canonical order of its lines.
	 *
	 * @since 0.2.0
	 *
	 * @param Refund        $refund  The refund's document.
	 * @param RefundRequest $request What was asked, which a retry's key proved the same as the first's.
	 * @return array<string, mixed> The refund, keyed by wire name.
	 */
	private static function answer( Refund $refund, RefundRequest $request ): array {
		$answer = array(
			'refund_uuid'      => $refund->uuid,
			'order_uuid'       => $request->orderUuid,
			'total_minor'      => $refund->total->minorUnits(),
			'tax_minor'        => $refund->tax->minorUnits(),
			'shipping_minor'   => $refund->shipping->minorUnits(),
			'currency'         => $refund->total->currency()->code(),
			'base_total_minor' => $refund->baseTotal->minorUnits(),
			'base_currency'    => $refund->baseTotal->currency()->code(),
			'reason_code'      => $refund->reasonCode,
		);

		if ( null !== $request->note ) {
			$answer['note'] = $request->note;
		}

		$answer['lines'] = $request->canonical()['lines'];

		return $answer;
	}

	/**
	 * Claims the refund under its intent's lock, in a short transaction of its own that commits before the gateway is asked.
	 *
	 * Under the lock it reads, as they now stand, whether the intent has money a person must
	 * reconcile, what the refund's uuid is named by, and the intent's oldest claim still open. Money
	 * a person must reconcile refuses the refund, as it does before the claim: it may have landed
	 * since the plan read the intent. A claim of this refund already open is left as it is. A claim
	 * of another refund open, or those figures having moved since the plan read them, refuses the
	 * refund. So does the gateway's capability matrix, asked again: the account of the intent's mode
	 * may have moved since the plan to a country whose row does not declare the refund. A refusal
	 * writes nothing. Otherwise the claim is inserted, with what was asked and the caller's key. So
	 * at most one claim of an intent is open, and what refund uuids are named by cannot move while it
	 * is.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The request, the key, and the matrix asked again.
	 *
	 * @throws CodedException `payment.unreconciled`; `payment.refund_unresolved`, naming the other
	 *                        refund's open claim; `payment.refund_retry`; `payment.operation_unsupported`.
	 *
	 * @param RefundPlan      $plan    The refund.
	 * @param RefundRequest   $request What was asked.
	 * @param Actor           $actor   Who asks for it.
	 * @param RequestKey|null $key     The caller's key, or null.
	 * @param RefundCaps      $caps    The caps the user is held to.
	 * @return bool True when this request claimed the refund; false when its claim, or a claim with its key, exists already.
	 */
	private function claim( RefundPlan $plan, RefundRequest $request, Actor $actor, ?RequestKey $key, RefundCaps $caps ): bool {
		list( $actorType, $actorId ) = self::actorOf( $actor );

		return $this->tx->transaction(
			function () use ( $plan, $request, $actor, $actorType, $actorId, $key, $caps ): bool {
				// The user's lock row first, before the transaction's first read, which fixes what every
				// later read sees: the sum of what the user asked then counts every claim of theirs that
				// committed while this one waited for the row.
				if ( null !== $caps->perDay ) {
					$this->refunds->lockActor( $actor->userId() );
				}

				$intent = $this->refunds->lockForClaim( $plan->intent->id, $plan->order->moneyReconciledAt );

				if ( $intent['has_unapplied_result'] ) {
					CodedException::raise( PaymentError::Unreconciled );
				}

				if ( $plan->uuid === $intent['open_claim'] ) {
					return false;
				}

				if ( null !== $intent['open_claim'] ) {
					CodedException::raise( PaymentError::RefundUnresolved, array( 'refund_uuid' => $intent['open_claim'] ) );
				}

				if ( $intent['refunded_minor'] !== $plan->intent->refunded->minorUnits() || $intent['declined_refunds'] !== $plan->intent->declinedRefunds ) {
					CodedException::raise( PaymentError::RefundRetry );
				}

				$this->requireDeclared( $plan );
				$this->requireCaps( $plan, $caps, $intent['base_refunded_minor'], $actor );

				return $this->refunds->claim( $plan, $request, $actorType, $actorId, $key );
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Refuses, under the claim's locks, a refund past the user's caps: of one order, from what the intent refunded as it now stands, and of a day, from what the user asked in the last 24 hours.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_cap_exceeded`.
	 *
	 * @param RefundPlan $plan         The refund.
	 * @param RefundCaps $caps         The caps the user is held to.
	 * @param int        $baseRefunded What the intent refunded, in minor units of the base currency, read under its lock.
	 * @param Actor      $actor        Who asks for it.
	 */
	private function requireCaps( RefundPlan $plan, RefundCaps $caps, int $baseRefunded, Actor $actor ): void {
		$share = $plan->total->base->gross();
		$base  = $plan->order->baseCurrency;

		$caps->requirePerOrder( Money::of( $baseRefunded, $base ), $share );

		if ( null !== $caps->perDay ) {
			$caps->requirePerDay( $this->refunds->askedToday( $actor->userId(), $base ), $share );
		}
	}

	/**
	 * Reads the claim an idempotency key names, and refuses the key when it was sent before with another request.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_key_reused` when the claim's request is not this one.
	 *
	 * @param RequestKey $key The caller's key.
	 * @return RefundClaim|null The claim, made for this same request; null when the key names none.
	 */
	private function claimNamedBy( RequestKey $key ): ?RefundClaim {
		$claim = $this->refunds->findClaimByKey( $key->keyHash );

		if ( null !== $claim && ! hash_equals( (string) $claim->requestFingerprint, $key->fingerprint ) ) {
			CodedException::raise( PaymentError::RefundKeyReused );
		}

		return $claim;
	}

	/**
	 * Answers a refund refused before the gateway was asked from the claim its key names, when that claim has ended; otherwise raises the refusal as it came.
	 *
	 * Another request with the same key may have claimed the refund after this one looked its key
	 * up, and recorded it. Whatever then refused this request, as it was worked out or claimed (the
	 * line's last unit taken, a cap used, what the refund's uuid is named by moved, or that
	 * request's claim still open), the key names the refund the caller asked for, so its claim, once
	 * ended, answers this request as it would at the first lookup. The key is read once more, and
	 * only here, on a refusal: a request without a key, or whose key names no claim or one still
	 * open, is refused as it was.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException The refusal; `payment.refund_key_reused`; what answerFrom() raises.
	 *
	 * @param CodedException  $refused The refusal.
	 * @param RequestKey|null $key     The caller's key, or null.
	 * @return Refund The refund's document.
	 */
	private function answerRefusedByKey( CodedException $refused, ?RequestKey $key ): Refund {
		$claim = null === $key ? null : $this->claimNamedBy( $key );

		if ( null === $claim || ClaimState::Claimed === $claim->state ) {
			throw $refused;
		}

		return $this->answerFrom( $claim->uuid, $claim );
	}

	/**
	 * Refuses, as a programming error, an open claim found by the request's key that is not the claim the same request names by its identity.
	 *
	 * While a claim is open, nothing a refund's uuid is named by moves, and the key's fingerprint
	 * proves the request is the same: the two are one claim. Two claims mean a row was changed
	 * outside this service, and a person must look.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Naming both.
	 *
	 * @param string $byKey      The uuid of the claim the key names.
	 * @param string $byIdentity The uuid of the refund the request names.
	 */
	private static function requireOneClaim( string $byKey, string $byIdentity ): void {
		if ( $byKey !== $byIdentity ) {
			throw new \LogicException( sprintf( 'The refund claim %1$s, found open by the request\'s idempotency key, is not the refund %2$s the same request names: a claim was changed outside the refund service.', $byKey, $byIdentity ) );
		}
	}

	/**
	 * Works the refund out from plain reads, and checks every cap, before the gateway is asked.
	 *
	 * Reads the order with the lines asked for, its shipping and their components; its captured
	 * intent, with whether the ledger holds a result of it applied to nothing and its oldest refund
	 * still claimed; and what earlier refunds returned of the lines, the components and the
	 * shipping: one read of each kind, whatever the number of lines. Money of the intent that a
	 * person has not reconciled refuses the refund: what it did is not known, so a refund worked out
	 * without it could give back more than is left. So does another refund of the intent still
	 * claimed, for the same reason. Last, the intent's gateway must declare the refund in its
	 * capability matrix: a partial refund, for less than was captured, or a whole one.
	 *
	 * A refund worked out again to settle its claim is the claim's own: the claim must still be the
	 * intent's open claim, else it ended since it was read, and the refund must be the one it
	 * claimed. Neither money a person has not reconciled nor the gateway's matrix holds it back: the
	 * person settling it is reconciling exactly such money, and the gateway was asked for it long
	 * since, and may be gone, as when its plugin was removed.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Checks the gateway's capability matrix, and the claim the request's key found open; works out a claim to settle.
	 *
	 * @throws \LogicException When the claim the request's key found open is still the intent's open claim, but not this
	 *                         refund's; or when a claim to settle, still open, is not the refund worked out from it.
	 * @throws CodedException  The refusals refund() lists before the gateway is asked; for a claim to settle,
	 *                         `payment.refund_claim_ended` when it is no longer the intent's open claim.
	 *
	 * @param RefundRequest $request    What to refund.
	 * @param string|null   $keyedClaim The uuid of the open claim the request's key named; null for none.
	 * @param string|null   $settling   Optional. The uuid of the claim this refund is worked out again from, to settle it. Default null.
	 * @param bool          $delivered  Optional. Whether the claim is answered by the provider's own delivery, which may report
	 *                                  another refund than the claim's: its caller keeps that one for a person, so a refund
	 *                                  worked out to another uuid is not refused. Default false.
	 * @return RefundPlan The refund, worked out.
	 */
	private function plan( RefundRequest $request, ?string $keyedClaim, ?string $settling = null, bool $delivered = false ): RefundPlan {
		$order  = $this->orders->findRefundable( $request->orderUuid, $request->lineUuids(), $request->shipping ) ?? CodedException::raise( OrderError::NotFound );
		$intent = $this->refunds->refundableIntent( $order->id, $order->moneyReconciledAt ) ?? CodedException::raise( PaymentError::RefundNotRefundable, array( 'order_uuid' => $order->uuid ) );

		if ( $intent->hasUnappliedResult && null === $settling ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		$componentIds = array_map( static fn( StoredTaxComponent $component ): int => $component->id, $order->components );
		$lineIds      = array_values( array_map( static fn( RefundableLine $line ): int => $line->id, $order->lines ) );
		$components   = $this->refunds->returnedOfComponents( $componentIds, $order->currency, $order->baseCurrency );
		$lines        = $this->refunds->returnedOfLines( $lineIds, $order->currency, $order->baseCurrency );
		$portions     = array_map( fn( RefundLineRequest $asked ): LinePortion => $this->linePortion( $order, $asked, $lines, $components ), $request->lines );
		$shipping     = $request->shipping ? $this->shippingPortion( $order, $components ) : null;
		$units        = array_combine( array_map( static fn( LinePortion $portion ): string => $portion->line->lineUuid, $portions ), array_map( static fn( LinePortion $portion ): int => $portion->quantity, $portions ) );
		$uuid         = RefundIdentity::uuid( $order->uuid, $units, $request->shipping, $intent->refunded, $intent->declinedRefunds );

		// The claim the key named is still open: nothing a refund's uuid is named by has moved since
		// it was made, and the key's fingerprint proves this is the same request, so it is this refund's.
		if ( null !== $keyedClaim && $keyedClaim === $intent->openClaim ) {
			self::requireOneClaim( $keyedClaim, $uuid );
		}

		if ( null !== $settling ) {
			$this->requireStillOpen( $settling, $intent->openClaim );

			if ( ! $delivered ) {
				self::requireClaimedRefund( $settling, $uuid );
			}
		}

		// Another refund of the intent is claimed and its answer is not recorded: what it did is not
		// known, and once recorded it would move what refund uuids are named by. This one waits for it.
		if ( null === $settling && null !== $intent->openClaim && $intent->openClaim !== $uuid ) {
			CodedException::raise( PaymentError::RefundUnresolved, array( 'refund_uuid' => $intent->openClaim ) );
		}

		$plan = new RefundPlan( $uuid, $order, $intent, $portions, $shipping, RefundAllocation::total( $portions, $shipping, $order->currency, $order->baseCurrency ), $request->reasonCode );

		$this->checkCaps( $plan, $components );

		if ( null === $settling ) {
			$this->requireDeclared( $plan );
		}

		return $plan;
	}

	/**
	 * Works a claimed refund out again from what its claim asked, as plan() works out a refund asked for: the order, the units of each line, the shipping and the reason.
	 *
	 * One read more than the claim's: the order's uuid, which the refund is asked by. The note is
	 * not asked again: nothing a refund's uuid or money is worked out from depends on it. What
	 * plan() throws or raises passes through.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the claim's order is gone, which nothing deletes.
	 * @phpstan-throws \LogicException|CodedException
	 *
	 * @param ClaimRequest $claim     The claim, with what it asked.
	 * @param bool         $settling  Optional. Whether the claim is being ended, by a person or by the provider's delivery,
	 *                                which plan() then requires to be the intent's open claim. Default false.
	 * @param bool         $delivered Optional. Whether the provider's delivery ends it, whose refund may work out to another
	 *                                than the claim's. Default false: a person's settlement, which requires the claim's own.
	 * @return RefundPlan The refund, worked out again.
	 */
	private function planOfClaim( ClaimRequest $claim, bool $settling = false, bool $delivered = false ): RefundPlan {
		$order = $this->orders->statusOf( $claim->orderId ) ?? throw new \LogicException( sprintf( 'Order %1$d, which refund %2$s was claimed of, is gone.', $claim->orderId, $claim->uuid ) );

		return $this->plan( new RefundRequest( $order['uuid'], $claim->lines, $claim->shipping, $claim->reasonCode ), null, $settling ? $claim->uuid : null, $delivered );
	}

	/**
	 * Refuses to settle a claim that is no longer its intent's open claim: it ended since it was read, and is answered as it ended.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_claim_ended`, with how it ended, read now.
	 *
	 * @param string      $uuid      The claim being settled.
	 * @param string|null $openClaim The intent's open claim, as read.
	 */
	private function requireStillOpen( string $uuid, ?string $openClaim ): void {
		if ( $uuid !== $openClaim ) {
			$this->claimEnded( $uuid );
		}
	}

	/**
	 * Refuses, as a programming error, a refund worked out again from its open claim that is not the refund the claim names.
	 *
	 * While a claim is open, nothing a refund's uuid is named by moves, so what the claim asked
	 * works out to the claim's own uuid. Another means a row was changed outside this service.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Naming both.
	 *
	 * @param string $claimed   The claim's uuid.
	 * @param string $workedOut The uuid of the refund worked out from what it asked.
	 */
	private static function requireClaimedRefund( string $claimed, string $workedOut ): void {
		if ( $claimed !== $workedOut ) {
			throw new \LogicException( sprintf( 'The refund claim %1$s, worked out again from what it asked, is the refund %2$s: what a refund\'s uuid is named by moved while the claim was open, which nothing in the refund service does.', $claimed, $workedOut ) );
		}
	}

	/**
	 * Answers a request to settle a claim that has ended: with how it ended, read now.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_claim_ended`, with the claim's state; `payment.refund_claim_not_found` for no claim.
	 *
	 * @param string $uuid The claim.
	 * @return never
	 */
	private function claimEnded( string $uuid ): never {
		$claim = $this->refunds->findClaim( $uuid ) ?? CodedException::raise( PaymentError::RefundClaimNotFound, array( 'refund_uuid' => $uuid ) );

		CodedException::raise( PaymentError::RefundClaimEnded, array( 'state' => $claim->state->value ) );
	}

	/**
	 * Refuses a statement that cannot settle a claim, before anything is read.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_note_rejected` when the note holds what reads as a card number;
	 *                        `payment.refund_statement_incomplete`, naming the problem.
	 *
	 * @param ClaimStatement $statement What the person states.
	 */
	private static function requireSettling( ClaimStatement $statement ): void {
		// The note is kept with the claim for as long as the order is: one that holds a card number is never kept.
		if ( CardNumbers::contains( $statement->note ) ) {
			CodedException::raise( PaymentError::RefundNoteRejected );
		}

		$problem = $statement->problem();

		if ( null !== $problem ) {
			self::refuseStatement( $problem );
		}
	}

	/**
	 * Refuses a statement that cannot settle a claim, naming why.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.refund_statement_incomplete`, with the problem and the longest note a statement keeps.
	 *
	 * @param string $problem What keeps the statement from settling a claim: one of ClaimStatement's problems.
	 * @return never
	 */
	private static function refuseStatement( string $problem ): never {
		CodedException::raise(
			PaymentError::RefundStatementIncomplete,
			array(
				'problem'    => $problem,
				'max_length' => PaymentOperations::NOTE_MAX_LENGTH,
			)
		);
	}

	/**
	 * Asks the intent's gateway once more what became of a claimed refund, at transaction depth 0, and says what it said.
	 *
	 * A gateway that cannot be asked is a reading too: one whose plugin was removed, that no longer
	 * declares the intent's mode, whose credentials cannot be used, that Safe Mode keeps from live
	 * calls, or that does not declare the query, is `unavailable` with the reason; one that does not
	 * answer is `unavailable`.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Inside a transaction.
	 * @throws CodedException  Any refusal but the gateway's being unavailable or not declaring the query.
	 *
	 * @param RefundPlan $plan The refund, still claimed.
	 * @return array{0: string, 1: GatewayResult|null} What the gateway said, and its answer when it gave one.
	 */
	private function askOnceMore( RefundPlan $plan ): array {
		$this->requireNoTransaction();

		try {
			$gateway = $this->gateways->get( $plan->intent->gatewayId, $plan->intent->mode );

			$this->payments->require( $plan->intent->gatewayId, Operations::QUERY_REFUND, $plan->intent->mode, $plan->order->currency );

			$answer = $gateway->queryRefund( self::gatewayRefund( $plan ) );
		} catch ( GatewayUnavailable $unanswered ) {
			return array( SettledClaim::UNAVAILABLE, null );
		} catch ( CodedException $refused ) {
			return array( self::unavailableBecause( $refused ), null );
		}

		return array( self::readingOf( $answer ), $answer );
	}

	/**
	 * Says why a gateway that refused to be asked is unavailable, from its refusal.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException The refusal itself, when it is anything else.
	 *
	 * @param CodedException $refused The refusal.
	 * @return string `unavailable:` and the reason, such as `unavailable:not_registered`, or `unavailable:query_refund_unsupported`.
	 */
	private static function unavailableBecause( CodedException $refused ): string {
		return match ( $refused->errorCode() ) {
			PaymentError::GatewayUnavailable   => SettledClaim::UNAVAILABLE . ':' . (string) ( $refused->context()['reason'] ?? '' ),
			PaymentError::OperationUnsupported => SettledClaim::UNAVAILABLE . ':' . Operations::QUERY_REFUND . '_unsupported',
			default                            => throw $refused,
		};
	}

	/**
	 * Says what a gateway's answer about a refund was: a refund it made or declined, one it says it never made, or none.
	 *
	 * @since 0.2.0
	 *
	 * @param GatewayResult|null $answer The answer; null when the gateway cannot say.
	 * @return string SettledClaim::APPROVED, DECLINED, NOT_FOUND or CANNOT_SAY; an answer that has not decided cannot say.
	 */
	private static function readingOf( ?GatewayResult $answer ): string {
		return match ( $answer?->outcome ) {
			Outcome::Approved => SettledClaim::APPROVED,
			Outcome::Declined => PaymentGateway::NOT_FOUND === $answer->errorCode ? SettledClaim::NOT_FOUND : SettledClaim::DECLINED,
			default           => SettledClaim::CANNOT_SAY,
		};
	}

	/**
	 * Builds the answer a person's statement stands for: the gateway's approval of the provider's refund and the amount they name, when they state it was made; no answer when they state it was not.
	 *
	 * @since 0.2.0
	 *
	 * @param RefundPlan     $plan      The refund.
	 * @param ClaimStatement $statement What the person states.
	 * @return GatewayResult|null The approval, as the intent's gateway would have answered it; null for a refund stated not made.
	 */
	private static function statedResult( RefundPlan $plan, ClaimStatement $statement ): ?GatewayResult {
		if ( ! $statement->refunded ) {
			return null;
		}

		return new GatewayResult( $plan->intent->gatewayId, Operation::Refund, Outcome::Approved, $plan->intent->uuid, Money::of( (int) $statement->amountMinor, $plan->order->currency ), $statement->providerRefundId, $plan->intent->providerIntentId );
	}

	/**
	 * Settles a claim in the transaction the caller opened: under the intent's lock, the answer recorded or the claim declined, the settlement noted, the order's event written, and the order flagged for a refund recorded on a person's word.
	 *
	 * @since 0.2.0
	 *
	 * A claim no longer the intent's open claim under its lock ended since it was read:
	 * lockOpenClaim() throws ClaimEndedElsewhere.
	 *
	 * @throws \LogicException When the settlement could not be noted, which the lock rules out.
	 * @phpstan-throws \LogicException|ClaimEndedElsewhere
	 *
	 * @param RefundPlan         $plan      The refund, worked out again from its claim.
	 * @param GatewayResult|null $result    The answer that decides: the gateway's, or the one the statement stands for; null for a refund stated not made.
	 * @param ClaimStatement     $statement What the person states.
	 * @param string             $reading   What the gateway said when it was asked once more.
	 * @param string             $decidedBy SettledClaim::BY_GATEWAY or BY_STATEMENT.
	 * @param Actor              $actor     Who settles it.
	 * @return ClaimState How the claim ended.
	 */
	private function settleLocked( RefundPlan $plan, ?GatewayResult $result, ClaimStatement $statement, string $reading, string $decidedBy, Actor $actor ): ClaimState {
		$this->lockOpenClaim( $plan, $plan->uuid, 'The refund\'s claim ended before a person settled it.' );

		$state = null === $result ? $this->declineAsStated( $plan ) : $this->recordAndRead( $plan, $result, $actor )->state;

		list( , $settledBy ) = self::actorOf( $actor );

		if ( ! $this->refunds->noteSettlement( $plan->uuid, $statement->word(), $reading, $settledBy, $statement->note ) ) {
			throw new \LogicException( sprintf( 'The settlement of refund %s could not be noted under its intent\'s lock: a claim was changed outside the refund service.', $plan->uuid ) );
		}

		$this->orderEvents->appendAudit( $plan->order->id, self::SETTLED_REASON, $plan->uuid, $actor );

		// No gateway says this money moved: it is on the person's word until a person clears the flag.
		if ( SettledClaim::BY_STATEMENT === $decidedBy && ClaimState::Recorded === $state ) {
			$this->orderEvents->flagUnreconciled( $plan->order->id, self::SETTLED_BY_STATEMENT, $plan->uuid, $actor );
		}

		return $state;
	}

	/**
	 * Locks the refund's intent, in the caller's transaction, and refuses to go on unless the refund's claim is still the intent's open claim.
	 *
	 * Whatever ended the claim since it was read ended it under this lock, and won.
	 *
	 * @since 0.2.0
	 *
	 * @throws ClaimEndedElsewhere When the claim is no longer open, with why it mattered.
	 *
	 * @param RefundPlan $plan  The refund, worked out again from its claim: its intent and the order's clearance.
	 * @param string     $uuid  The claim, which a provider's delivery may find worked out to another refund.
	 * @param string     $ended What the claim ended before, for the exception.
	 */
	private function lockOpenClaim( RefundPlan $plan, string $uuid, string $ended ): void {
		$intent = $this->refunds->lockForClaim( $plan->intent->id, $plan->order->moneyReconciledAt );

		if ( $uuid !== $intent['open_claim'] ) {
			throw new ClaimEndedElsewhere( $ended );
		}
	}

	/**
	 * Ends a claim declined on a person's statement that the refund was never made: no ledger row, as no gateway declined it.
	 *
	 * @since 0.2.0
	 *
	 * @throws ClaimEndedElsewhere When the claim had ended already.
	 *
	 * @param RefundPlan $plan The refund.
	 * @return ClaimState Declined.
	 */
	private function declineAsStated( RefundPlan $plan ): ClaimState {
		if ( ! $this->refunds->settleClaim( $plan->uuid, ClaimState::Declined, null ) ) {
			throw new ClaimEndedElsewhere( 'The refund\'s claim ended before a person settled it.' );
		}

		return ClaimState::Declined;
	}

	/**
	 * Records the answer to a claim still open under the intent's lock, a person's settlement or the provider's own delivery, as any refund's answer is recorded, and reads how the claim ended.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the claim is gone, which nothing deletes.
	 *
	 * @param RefundPlan    $plan   The refund.
	 * @param GatewayResult $result The answer.
	 * @param Actor         $actor  On whose authority.
	 * @return RefundClaim The claim as it ended: recorded, declined, or unreconciled when the money was left for a person.
	 */
	private function recordAndRead( RefundPlan $plan, GatewayResult $result, Actor $actor ): RefundClaim {
		$this->recordAnswer( $plan, $result, $actor );

		return $this->refunds->findClaim( $plan->uuid ) ?? throw new \LogicException( sprintf( 'The claim of refund %s is gone.', $plan->uuid ) );
	}

	/**
	 * Refuses a refund the intent's gateway does not declare in its capability matrix: a partial refund, for less than was captured, or a whole one.
	 *
	 * Reads no statement: the matrix is the gateway's declaration, and its account's country a setting.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.operation_unsupported`; `payment.gateway_unavailable` when the gateway is not registered.
	 *
	 * @param RefundPlan $plan The refund.
	 */
	private function requireDeclared( RefundPlan $plan ): void {
		// Less than was captured is a partial refund to the provider, whether or not a refund came before it.
		$operation = $plan->total->amount->gross()->compare( $plan->intent->captured ) < 0 ? Operations::PARTIAL_REFUND : Operations::REFUND;

		$this->payments->require( $plan->intent->gatewayId, $operation, $plan->intent->mode, $plan->order->currency );
	}

	/**
	 * Allocates what some units of one line return, after checking the line is the order's and has the units left.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_line_not_found`; `payment.refund_line_exhausted`.
	 *
	 * @param RefundableOrder   $order      The order.
	 * @param RefundLineRequest $asked      The line asked for.
	 * @param array<int, Share> $lines      What earlier refunds returned, by line id.
	 * @param array<int, Share> $components What earlier refunds returned, by component id.
	 * @return LinePortion What the units return.
	 */
	private function linePortion( RefundableOrder $order, RefundLineRequest $asked, array $lines, array $components ): LinePortion {
		$line = $order->lines[ $asked->lineUuid ] ?? CodedException::raise( PaymentError::RefundLineNotFound, array( 'line_uuid' => $asked->lineUuid ) );
		$left = $line->quantity - $line->refundedQuantity;

		if ( $asked->quantity > $left ) {
			CodedException::raise(
				PaymentError::RefundLineExhausted,
				array(
					'line_uuid'  => $line->lineUuid,
					'returnable' => $left,
				)
			);
		}

		return RefundAllocation::line( $line, $asked->quantity, $asked->restock, $lines[ $line->id ] ?? self::nothing( $order ), self::componentsOf( $order, $line->id, $components ) );
	}

	/**
	 * Allocates what is left of the order's shipping; one more read, of the shipping earlier refunds returned.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundableOrder   $order      The order.
	 * @param array<int, Share> $components What earlier refunds returned, by component id.
	 * @return ShippingPortion|null What the shipping returns; null when the order has no shipping.
	 */
	private function shippingPortion( RefundableOrder $order, array $components ): ?ShippingPortion {
		if ( null === $order->shipping || null === $order->baseShipping ) {
			return null;
		}

		list( $net, $baseNet ) = $this->refunds->returnedShipping( $order->id, $order->currency, $order->baseCurrency );

		return RefundAllocation::shipping( $order->shipping, $order->baseShipping, $net, $baseNet, self::componentsOf( $order, null, $components ) );
	}

	/**
	 * Checks every cap of a worked-out refund against the reads it was worked out from, so a refusal comes before the gateway is asked.
	 *
	 * The statements that write the refund carry the same caps; these checks only save asking the
	 * gateway for a refund the database would refuse.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_nothing_left` when it would give back nothing;
	 *                        `payment.refund_exceeds_captured` when a component, the shipping or the
	 *                        intent has less left than it asks for, in either currency.
	 *
	 * @param RefundPlan        $plan       The refund.
	 * @param array<int, Share> $components What earlier refunds returned, by component id.
	 */
	private function checkCaps( RefundPlan $plan, array $components ): void {
		$total = $plan->total;

		if ( $total->amount->gross()->isZero() || $total->amount->gross()->isNegative() ) {
			CodedException::raise( PaymentError::RefundNothingLeft );
		}

		foreach ( $plan->components() as $portion ) {
			$stored   = $portion->component->stored;
			$returned = $components[ $portion->component->id ] ?? self::nothing( $plan->order );

			self::requireFits( $stored->amount->gross(), $returned->amount->gross(), $portion->share->amount->gross() );
			self::requireFits( $stored->base->gross(), $returned->base->gross(), $portion->share->base->gross() );
		}

		$shipping = $plan->shipping;

		if ( null !== $shipping ) {
			self::requireFits( $shipping->stored->net(), $shipping->returnedNet, $shipping->share->amount->net() );
			self::requireFits( $shipping->storedBase->net(), $shipping->returnedBaseNet, $shipping->share->base->net() );
		}

		self::requireFits( $plan->intent->captured, $plan->intent->refunded, $total->amount->gross() );
		self::requireFits( $plan->intent->baseCaptured, $plan->intent->baseRefunded, $total->base->gross() );
	}

	/**
	 * Asks the gateway for the refund, at transaction depth 0.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateway was added.
	 *
	 * @throws \LogicException     Inside a transaction.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param RefundPlan     $plan    The refund.
	 * @param PaymentGateway $gateway The intent's gateway.
	 * @return GatewayResult The gateway's answer, not yet applied.
	 */
	private function askGateway( RefundPlan $plan, PaymentGateway $gateway ): GatewayResult {
		$this->requireNoTransaction();

		return $gateway->refund( self::gatewayRefund( $plan ) );
	}

	/**
	 * Answers a refund whose claim an earlier request made, without asking the gateway for it again.
	 *
	 * With a key, the claim is the one the key names, read again now: another request with the key
	 * may have claimed the refund, under another identity, and recorded it since this one looked.
	 * When the key names none, the claim this request met is another request's, made with another
	 * key or none, for the same units: its refund is answered only to that request, and this one is
	 * refused `payment.refund_retry`. Without a key, the claim is the refund's own, by its uuid.
	 * The claim says how the refund ended: its document when it was recorded, a decline, or what
	 * was left for a person. A refund still claimed may be on its way, or may have been made by the
	 * gateway while its answer was lost: the gateway is asked what became of it, at transaction
	 * depth 0, and a refund it made, or declined, is recorded as a first answer is. When it cannot
	 * say, or says it made no such refund, however old the claim, the refund is refused, nothing is
	 * written, and the claim waits for a person.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The claim the request's key names, and only it.
	 *
	 * @throws \LogicException    When the claim the key names is open but is not this refund's.
	 * @throws GatewayUnavailable When the gateway has no answer; the claim stays as it was.
	 * @throws CodedException     `payment.refund_retry` when the request's key names no claim; `payment.refund_declined`;
	 *                            `payment.unreconciled`; `payment.refund_unresolved` with the refund's uuid;
	 *                            `payment.refund_key_reused`.
	 *
	 * @param RefundPlan      $plan    The refund, which an earlier request claimed.
	 * @param PaymentGateway  $gateway The intent's gateway.
	 * @param Actor           $actor   Who refunds.
	 * @param RequestKey|null $key     The caller's key, or null.
	 * @return Refund The refund's document.
	 */
	private function askedBefore( RefundPlan $plan, PaymentGateway $gateway, Actor $actor, ?RequestKey $key ): Refund {
		$claim = null === $key ? $this->refunds->findClaim( $plan->uuid ) : $this->claimNamedBy( $key );

		// A claim's refund is answered only to the key that made it. This request met a claim of the
		// same units that its key did not make: another request is refunding them, and once that
		// claim has ended, this request asked again is worked out anew, as a refund of its own.
		if ( null !== $key && null === $claim ) {
			CodedException::raise( PaymentError::RefundRetry );
		}

		if ( null !== $claim && ClaimState::Claimed === $claim->state ) {
			self::requireOneClaim( $claim->uuid, $plan->uuid );

			return $this->askWhatBecameOf( $plan, $gateway, $actor );
		}

		return $this->answerFrom( $claim->uuid ?? $plan->uuid, $claim );
	}

	/**
	 * Answers a refund from how its claim ended: its document when it was recorded, a decline, or what was left for a person.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_declined`; `payment.unreconciled` when what the gateway
	 *                        answered was left for a person, or there is no claim where one was found.
	 *
	 * @param string           $uuid  The refund's uuid.
	 * @param RefundClaim|null $claim Its claim, ended.
	 * @return Refund The refund's document.
	 */
	private function answerFrom( string $uuid, ?RefundClaim $claim ): Refund {
		return match ( $claim?->state ) {
			ClaimState::Recorded => $this->recordedDocument( $uuid, $claim->transactionId ) ?? CodedException::raise( PaymentError::Unreconciled ),
			ClaimState::Declined => CodedException::raise( PaymentError::RefundDeclined ),
			// What the gateway answered was left for a person; or no claim, where one was found: a person must look.
			default              => CodedException::raise( PaymentError::Unreconciled ),
		};
	}

	/**
	 * Asks the gateway what became of a refund still claimed, at transaction depth 0, and records its answer as a first answer is recorded.
	 *
	 * A refund the gateway made, or declined, is recorded. A claim the gateway cannot account for
	 * waits for a person: when it cannot say, or says it made no such refund, nothing is written and
	 * the claim stays `claimed`. A not-found is never taken for a decline, however old the claim:
	 * the first request may still be on its way to the gateway.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer.
	 * @throws CodedException     `payment.operation_unsupported`, before the gateway is asked;
	 *                            `payment.refund_unresolved` with the refund's uuid; what record() raises.
	 *
	 * @param RefundPlan     $plan    The refund, still claimed.
	 * @param PaymentGateway $gateway The intent's gateway.
	 * @param Actor          $actor   Who refunds.
	 * @return Refund The refund's document.
	 */
	private function askWhatBecameOf( RefundPlan $plan, PaymentGateway $gateway, Actor $actor ): Refund {
		$this->requireNoTransaction();
		$this->payments->require( $plan->intent->gatewayId, Operations::QUERY_REFUND, $plan->intent->mode, $plan->order->currency );

		$answer = $gateway->queryRefund( self::gatewayRefund( $plan ) );

		if ( null === $answer || ( Outcome::Declined === $answer->outcome && PaymentGateway::NOT_FOUND === $answer->errorCode ) ) {
			CodedException::raise( PaymentError::RefundUnresolved, array( 'refund_uuid' => $plan->uuid ) );
		}

		return $this->record( $plan, $answer, $actor );
	}

	/**
	 * Records the gateway's answer in one transaction, and answers with the refund, or raises what the answer came to.
	 *
	 * A decline is answered as its claim then stands, read once the transaction has ended: declined
	 * when this answer ended it; left for a person when the gateway answered with a result the ledger
	 * already had; or as another request ended it first, in which case the decline this answer
	 * wrote was taken back with its savepoint.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionRetryable When every attempt lost a deadlock; nothing was recorded.
	 * @throws CodedException       `payment.refund_declined` for a decline; `payment.unreconciled` when
	 *                              an approval could not be recorded as the refund, raised once the
	 *                              transaction has committed the money kept for a person, or when a
	 *                              decline's claim was left for a person.
	 *
	 * @param RefundPlan    $plan   The refund.
	 * @param GatewayResult $result The gateway's answer.
	 * @param Actor         $actor  Who refunds.
	 * @return Refund The refund.
	 */
	private function record( RefundPlan $plan, GatewayResult $result, Actor $actor ): Refund {
		try {
			$recorded = $this->tx->transaction( fn(): Refund|\Throwable|null => $this->recordAnswer( $plan, $result, $actor ), RetryPolicy::deadlocks() );
		} catch ( ClaimEndedElsewhere $ended ) {
			$recorded = null;
		}

		if ( $recorded instanceof Refund ) {
			return $recorded;
		}

		if ( Outcome::Approved !== $result->outcome ) {
			return $this->answerFrom( $plan->uuid, $this->refunds->findClaim( $plan->uuid ) );
		}

		// Raised only now, once the transaction has committed the money kept for a person.
		$unreconciled = CodedException::because( PaymentError::Unreconciled, array(), $recorded );

		throw $unreconciled;
	}

	/**
	 * Records the gateway's answer, in the transaction the caller opened: the refund and its document, or, when it cannot be recorded as worked out, the money for a person; and ends the refund's claim.
	 *
	 * The answer is applied in a savepoint. An approval of another amount than was asked does not
	 * state this refund, and is kept for a person without one. For an approval, whatever the
	 * savepoint throws is caught once the savepoint has taken the refund back: a cap refusing,
	 * because a refund that landed after the reads changed what this one was allocated from, or any
	 * other error. The money the gateway moved is then kept on the ledger for a person, and what
	 * failed is returned, for the caller to raise once this transaction has committed the money.
	 * A lost deadlock and a transaction whose integrity was lost leave no transaction to keep the
	 * money in, so they are thrown as they came: the caller's retry policy runs a deadlocked
	 * transaction again from the start.
	 *
	 * @since 0.1.0
	 *
	 * @throws TransactionRetryable     A lost deadlock or lock wait, for the caller's retry policy.
	 * @throws TransactionIntegrityLost When the transaction can no longer be trusted.
	 * @throws \Throwable              What applying a declined answer threw.
	 *
	 * @param RefundPlan    $plan   The refund.
	 * @param GatewayResult $result The gateway's answer.
	 * @param Actor         $actor  Who refunds.
	 * @return Refund|\Throwable|null The refund; what kept an approval from being recorded, now kept for a person; null for a decline, or for an approval kept for a person with nothing thrown.
	 */
	private function recordAnswer( RefundPlan $plan, GatewayResult $result, Actor $actor ): Refund|\Throwable|null {
		$approved = Outcome::Approved === $result->outcome;

		if ( $approved && ! $result->amount->equals( $plan->total->amount->gross() ) ) {
			return $this->keepForAPerson( $plan, $result, $actor );
		}

		try {
			return $this->tx->transaction( fn(): ?Refund => $this->apply( $plan, $result, $actor ) );
		} catch ( TransactionRetryable | TransactionIntegrityLost $lost ) {
			throw $lost;
		} catch ( \Throwable $failed ) {
			if ( ! $approved ) {
				throw $failed;
			}

			// The gateway gave the money back, and the savepoint took back everything that recorded it.
			return $this->keepForAPerson( $plan, $result, $actor ) ?? $failed;
		}
	}

	/**
	 * Keeps an approval that cannot be recorded as this refund on the ledger for a person, unless the ledger has it already as this same refund, whose document is then the answer.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan    $plan   The refund.
	 * @param GatewayResult $result The approval.
	 * @param Actor         $actor  Who refunds.
	 * @return Refund|null This refund's document, recorded before; null when the money is now kept for a person, or was already.
	 */
	private function keepForAPerson( RefundPlan $plan, GatewayResult $result, Actor $actor ): ?Refund {
		return $this->endWithout( $plan->uuid, $result, $this->payments->recordUnapplied( $result, $actor ) );
	}

	/**
	 * Applies the gateway's answer to the ledger, then writes the document of an applied refund.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan    $plan   The refund.
	 * @param GatewayResult $result The gateway's answer.
	 * @param Actor         $actor  Who refunds.
	 * @return Refund|null The refund, or its document recorded before for a duplicate of it; null for a decline, another refund's duplicate, or an answer that did not match the intent.
	 */
	private function apply( RefundPlan $plan, GatewayResult $result, Actor $actor ): ?Refund {
		$application = $this->payments->applyGatewayResult( $result, $actor, $plan->total->base->gross() );

		if ( ApplicationKind::Applied === $application->kind ) {
			return $this->writeDocument( $plan, (int) $application->transactionId, $actor );
		}

		return $this->endWithout( $plan->uuid, $result, $application );
	}

	/**
	 * Answers with the refund's document when the ledger already had it; otherwise ends the refund's claim: declined, or left for a person.
	 *
	 * A decline this answer wrote ends the claim `declined`, naming its ledger row. Anything else is
	 * left for a person: money kept unapplied ends the claim `unreconciled`, naming the row that keeps
	 * it; a duplicate's row was written by whichever answer the ledger had first, which may be
	 * another refund's, as when a gateway answers with a refund, or a decline, it gave another
	 * request, so whatever its outcome it ends the claim `unreconciled`, naming no row, and doctor
	 * reports it. A claim another request ended first is left as it ended. A decline this answer
	 * wrote for such a claim is taken back with the savepoint it was written in: a gateway may decline
	 * without naming a provider object, which the ledger's unique key could tell apart, so the claim
	 * is what keeps one decline per refund.
	 *
	 * @since 0.1.0
	 *
	 * @throws ClaimEndedElsewhere For a decline this answer wrote for a claim that had ended, inside its savepoint.
	 *
	 * @param string        $uuid        The refund's uuid, which names its claim.
	 * @param GatewayResult $result      The gateway's answer.
	 * @param Application   $application What the ledger did with it: anything but an application.
	 * @return Refund|null The refund's document, recorded before; null otherwise.
	 */
	private function endWithout( string $uuid, GatewayResult $result, Application $application ): ?Refund {
		$document = $this->documentOf( $uuid, $application );

		if ( null !== $document ) {
			return $document;
		}

		$duplicate = ApplicationKind::Duplicate === $application->kind;
		$declined  = ! $duplicate && Outcome::Declined === $result->outcome;
		$ended     = $this->refunds->settleClaim( $uuid, $declined ? ClaimState::Declined : ClaimState::Unreconciled, $duplicate ? null : $application->transactionId );

		if ( $declined && ! $ended ) {
			throw new ClaimEndedElsewhere( 'The refund\'s claim had ended before its decline was recorded.' );
		}

		return null;
	}

	/**
	 * Returns this refund's document when the ledger already had the gateway's answer: the same refund, asked again.
	 *
	 * Only a document of the same uuid is this refund's. A gateway answering with a refund it made
	 * for another request did not make this one, so that refund's document is not the answer.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $uuid        The refund's uuid.
	 * @param Application $application What the ledger did with the gateway's answer.
	 * @return Refund|null The document; null when the answer was not a duplicate, or its row has no document of this refund.
	 */
	private function documentOf( string $uuid, Application $application ): ?Refund {
		return ApplicationKind::Duplicate === $application->kind ? $this->recordedDocument( $uuid, $application->transactionId ) : null;
	}

	/**
	 * Returns the document that states a ledger row, when it is this refund's: of the same uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $uuid          The refund's uuid.
	 * @param int|null $transactionId The ledger row, or null for none.
	 * @return Refund|null The document; null when the row has no document of this refund.
	 */
	private function recordedDocument( string $uuid, ?int $transactionId ): ?Refund {
		$document = null === $transactionId ? null : $this->refunds->findByTransaction( $transactionId );

		return null !== $document && $document->uuid === $uuid ? $document : null;
	}

	/**
	 * Writes the refund's document, its lines and its components, adds its units to its lines, and publishes RefundRecorded.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.unreconciled` when a cap refused: the shipping's, a component's, or a line's.
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $transactionId Its ledger row.
	 * @param Actor      $actor         Who refunds.
	 * @return Refund The refund.
	 */
	private function writeDocument( RefundPlan $plan, int $transactionId, Actor $actor ): Refund {
		list( $actorType, $actorId ) = self::actorOf( $actor );

		$refundId = $this->refunds->insertRefund( $plan, $transactionId, $actorType, $actorId ) ?? CodedException::raise( PaymentError::Unreconciled );

		$this->refunds->insertLines( $refundId, $plan );

		if ( ! $this->refunds->insertComponents( $refundId, $plan ) ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		if ( array() !== $plan->lines && ! $this->orders->addRefundedQuantities( $plan->order->id, $plan->units() ) ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		// Another request's answer ended the claim first: two refunds for one claim, and the money the
		// gateway gave back for this one goes to a person.
		if ( ! $this->refunds->settleClaim( $plan->uuid, ClaimState::Recorded, $transactionId ) ) {
			CodedException::raise( PaymentError::Unreconciled );
		}

		// Every refund is one of the order's events, whether or not its payment status moved, which
		// records itself in an event of its own; the refund's reason, note and lines are reached by its uuid.
		$this->orderEvents->appendAudit( $plan->order->id, self::AUDIT_REASON, $plan->uuid, $actor );

		$refund = self::document( $plan, $refundId, $transactionId );

		$this->events->publish(
			new RefundRecorded(
				$refund->id,
				$refund->uuid,
				$refund->orderId,
				$transactionId,
				$refund->total->minorUnits(),
				$refund->tax->minorUnits(),
				$refund->total->currency()->code(),
				$refund->baseTotal->minorUnits(),
				$refund->baseTotal->currency()->code(),
				$refund->reasonCode,
				$this->clock->now()
			)
		);

		return $refund;
	}

	/**
	 * Refuses a refund unless its share fits what is left of a stored figure.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_exceeds_captured` when it does not.
	 *
	 * @param Money $stored   The stored figure: a component's, the shipping's or what the intent captured.
	 * @param Money $returned What earlier refunds returned of it.
	 * @param Money $share    What this refund would return.
	 */
	private static function requireFits( Money $stored, Money $returned, Money $share ): void {
		if ( ! RefundAllocation::fits( $stored, $returned, $share ) ) {
			CodedException::raise(
				PaymentError::RefundExceedsCaptured,
				array(
					'captured'  => $stored->minorUnits(),
					'refunded'  => $returned->minorUnits(),
					'requested' => $share->minorUnits(),
				)
			);
		}
	}

	/**
	 * Returns the components of one line, or of the shipping, each with what earlier refunds returned of it.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundableOrder   $order    The order.
	 * @param int|null          $lineId   The line, or null for the shipping.
	 * @param array<int, Share> $returned What earlier refunds returned, by component id.
	 * @return list<array{0: StoredTaxComponent, 1: Share}> The components.
	 */
	private static function componentsOf( RefundableOrder $order, ?int $lineId, array $returned ): array {
		$components = array();

		foreach ( $order->components as $component ) {
			if ( $lineId === $component->lineId ) {
				$components[] = array( $component, $returned[ $component->id ] ?? self::nothing( $order ) );
			}
		}

		return $components;
	}

	/**
	 * Builds the document a refund's plan and its ids describe.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int        $refundId      Its id.
	 * @param int        $transactionId Its ledger row.
	 * @return Refund The document.
	 */
	private static function document( RefundPlan $plan, int $refundId, int $transactionId ): Refund {
		$total    = $plan->total;
		$shipping = $plan->shipping->share ?? self::nothing( $plan->order );

		return new Refund(
			$refundId,
			$plan->uuid,
			$plan->order->id,
			$transactionId,
			$plan->order->conversionContextId,
			$total->amount->gross(),
			$total->amount->tax(),
			$shipping->amount->net(),
			$total->base->gross(),
			$total->base->tax(),
			$shipping->base->net(),
			$plan->reasonCode
		);
	}

	/**
	 * Builds what the gateway is asked: the intent, the provider's reference to it, the refund's total, its uuid and the intent's mode.
	 *
	 * The same request asks for the refund and asks what became of it.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan The refund.
	 * @return GatewayRefund The request.
	 */
	private static function gatewayRefund( RefundPlan $plan ): GatewayRefund {
		return new GatewayRefund( $plan->intent->uuid, $plan->intent->providerIntentId, $plan->total->amount->gross(), $plan->uuid, $plan->intent->mode );
	}

	/**
	 * Returns nothing returned, in the order's currencies.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundableOrder $order The order.
	 * @return Share Zero, in both currencies.
	 */
	private static function nothing( RefundableOrder $order ): Share {
		return new Share( TaxedMoney::zero( $order->currency ), TaxedMoney::zero( $order->baseCurrency ) );
	}

	/**
	 * Refuses a refund inside a transaction, before any statement: it asks the gateway, which may go over the network.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException At a depth above 0.
	 */
	private function requireNoTransaction(): void {
		if ( 0 !== $this->tx->depth() ) {
			throw new \LogicException( 'RefundService::refund() calls the gateway, which may go over the network: never inside a transaction, whose locks it would hold for the call.' );
		}
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
