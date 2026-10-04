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

use SEOCart\Contracts\Payment\GatewayRefund;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operations;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\RefundableOrder;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\RefundRecorded;
use SEOCart\Payment\Domain\Refund\ClaimState;
use SEOCart\Payment\Domain\Refund\LinePortion;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundAllocation;
use SEOCart\Payment\Domain\Refund\RefundClaim;
use SEOCart\Payment\Domain\Refund\RefundIdentity;
use SEOCart\Payment\Domain\Refund\RefundLineRequest;
use SEOCart\Payment\Domain\Refund\RefundPlan;
use SEOCart\Payment\Domain\Refund\RefundRepository;
use SEOCart\Payment\Domain\Refund\RefundRequest;
use SEOCart\Payment\Domain\Refund\Share;
use SEOCart\Payment\Domain\Refund\ShippingPortion;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\Authorizer;
use SEOCart\Platform\Database\Exception\TransactionIntegrityLost;
use SEOCart\Platform\Database\Exception\TransactionRetryable;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
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
 * 1. The capability `seocart_refund_orders`, before anything else.
 * 2. Outside any transaction, plain reads: the order with the lines asked for, its shipping and
 *    their tax components at its current totals version; its captured intent, with whether the
 *    ledger holds a result of it applied to nothing, which refuses the refund until a person has
 *    reconciled that money, and its oldest refund still claimed, which refuses any other refund
 *    of it (below); and what earlier refunds returned. RefundAllocation allocates the shares from
 *    those stored figures, and every cap is checked against the same reads: the units each line
 *    has left, what is left of each component and of the shipping, and what the intent captured
 *    and has not refunded, in both currencies. A refusal here happens before the gateway is asked,
 *    and leaves nothing behind.
 * 3. The refund's claim, in a short transaction of its own under the intent's lock, committed
 *    before the gateway is asked. The lock reads, as they now stand, whether the intent has money
 *    a person must reconcile, which refuses the refund `payment.unreconciled` as the reads would
 *    have; what the refund's uuid is named by; and the intent's oldest claim still open. A claim of
 *    this refund already open is not made again: the gateway is asked what became of it (below).
 *    A claim of another refund open
 *    refuses this one `payment.refund_unresolved`, naming that claim. What the refund's uuid is
 *    named by having moved since the reads refuses it `payment.refund_retry`: another refund was
 *    recorded or declined meanwhile, and asking again works the refund out anew. Otherwise the
 *    claim is inserted: the refund's uuid, the intent, the amount, who asks and when. A refusal
 *    here writes nothing and asks nothing of the gateway.
 * 4. The gateway's refund, at transaction depth 0: it may go over the network. Its idempotency key
 *    is the refund's uuid, derived from the refund itself (below).
 * 5. One transaction, which locks the intent and then the order: the gateway's answer applied to
 *    the ledger with the refund's base share (applyGatewayResult()), then the document, its lines
 *    and its components, each insert carrying its cap, the lines' refunded quantities by one
 *    conditional update, the claim ended `recorded`, then RefundRecorded, all in one savepoint.
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
 * `payment.unreconciled` when what the gateway answered was left for a person. While the claim is
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
 * - a refund recorded whose answer the caller lost: the intent's refunded amount moved, so the same
 *   request asked again is a new refund, with a new claim, which the caps allow while the units are
 *   left. Telling a retry from a second refund of the same units needs a key the caller sends with
 *   the request; that is the refund operation's, which does not exist yet;
 * - nobody asking again: a claim the gateway's answer never ended stays `claimed`, and doctor's
 *   payments check reports it once it is older than PaymentService::STALE_SECONDS; nothing asks the
 *   gateway about it until the same refund is asked for again, and until then the intent takes no
 *   other refund;
 * - a claim the gateway cannot account for: it stays `claimed`, and every refund of the intent is
 *   refused until a person settles it; no operation settles a claim yet;
 * - a refund refused `payment.refund_retry`: its caller must ask for it again.
 *
 * Nothing here reads a rate, a tax rate, a price or the calculation: the order's conversion
 * context is copied, and every figure is a share of what the order stored.
 *
 * @since 0.1.0
 */
final class RefundService {

	/**
	 * The capability a refund needs.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CAPABILITY = 'seocart_refund_orders';

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
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes the gateway registry in place of the one gateway.
	 *
	 * @param RefundRepository   $refunds    The refund statements.
	 * @param OrderRepository    $orders     The order statements.
	 * @param PaymentService     $payments   The money path.
	 * @param Gateways           $gateways   The gateways.
	 * @param TransactionManager $tx         The unit of work.
	 * @param EventPublisher     $events     Publishes the events.
	 * @param Authorizer         $authorizer Checks capabilities.
	 * @param Clock              $clock      Says when an event happened.
	 */
	public function __construct( RefundRepository $refunds, OrderRepository $orders, PaymentService $payments, Gateways $gateways, TransactionManager $tx, EventPublisher $events, Authorizer $authorizer, Clock $clock ) {
		$this->refunds    = $refunds;
		$this->orders     = $orders;
		$this->payments   = $payments;
		$this->gateways   = $gateways;
		$this->tx         = $tx;
		$this->events     = $events;
		$this->authorizer = $authorizer;
		$this->clock      = $clock;
	}

	/**
	 * Refunds an order as asked, and returns the refund's document.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException      Inside a transaction, before any statement: the gateway is called.
	 * @throws GatewayUnavailable   When the gateway has no answer; nothing was recorded, and the claim is left `claimed`.
	 * @throws TransactionRetryable When every attempt to record the answer lost a deadlock; nothing was recorded, and the claim is left `claimed`.
	 * @throws CodedException       `authorization.denied`, before any read; `order.not_found`;
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
	 *                              before the gateway is asked; `payment.refund_declined` when the
	 *                              gateway declined, now or when the refund was asked for before;
	 *                              `payment.unreconciled` when the gateway gave the money back but the
	 *                              refund could not be recorded, which is then left for a person, with
	 *                              what kept it from being recorded as the previous exception, when
	 *                              something did; and `payment.refund_unresolved`, with the refund's
	 *                              uuid, when the refund was asked for before and the gateway cannot
	 *                              account for it.
	 *
	 * @param RefundRequest $request What to refund.
	 * @param Actor         $actor   Who refunds it.
	 * @return Refund The refund; for the same refund asked again, recorded since or found made by the gateway, its document.
	 */
	public function refund( RefundRequest $request, Actor $actor ): Refund {
		$this->authorizer->authorize( $actor, self::CAPABILITY );
		$this->requireNoTransaction();

		$plan    = $this->plan( $request );
		$gateway = $this->gateways->get( $plan->intent->gatewayId, $plan->intent->mode );

		if ( ! $this->claim( $plan, $actor ) ) {
			return $this->askedBefore( $plan, $gateway, $actor );
		}

		return $this->record( $plan, $this->askGateway( $plan, $gateway ), $actor );
	}

	/**
	 * Claims the refund under its intent's lock, in a short transaction of its own that commits before the gateway is asked.
	 *
	 * Under the lock it reads, as they now stand, whether the intent has money a person must
	 * reconcile, what the refund's uuid is named by, and the intent's oldest claim still open. Money
	 * a person must reconcile refuses the refund, as it does before the claim: it may have landed
	 * since the plan read the intent. A claim of this refund already open is left as it is. A claim
	 * of another refund open, or those figures having moved since the plan read them, refuses the
	 * refund. A refusal writes nothing. Otherwise the claim is inserted. So at most one claim of an
	 * intent is open, and what refund uuids are named by cannot move while it is.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.unreconciled`; `payment.refund_unresolved`, naming the other
	 *                        refund's open claim; `payment.refund_retry`.
	 *
	 * @param RefundPlan $plan  The refund.
	 * @param Actor      $actor Who asks for it.
	 * @return bool True when this request claimed the refund; false when its claim exists already.
	 */
	private function claim( RefundPlan $plan, Actor $actor ): bool {
		list( $actorType, $actorId ) = self::actorOf( $actor );

		return $this->tx->transaction(
			function () use ( $plan, $actorType, $actorId ): bool {
				$intent = $this->refunds->lockForClaim( $plan->intent->id );

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

				return $this->refunds->claim( $plan, $actorType, $actorId );
			},
			RetryPolicy::deadlocks()
		);
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
	 * @since 0.1.0
	 * @since 0.2.0 Checks the gateway's capability matrix.
	 *
	 * @throws CodedException The refusals refund() lists before the gateway is asked.
	 *
	 * @param RefundRequest $request What to refund.
	 * @return RefundPlan The refund, worked out.
	 */
	private function plan( RefundRequest $request ): RefundPlan {
		$order  = $this->orders->findRefundable( $request->orderUuid, $request->lineUuids(), $request->shipping ) ?? CodedException::raise( OrderError::NotFound );
		$intent = $this->refunds->refundableIntent( $order->id ) ?? CodedException::raise( PaymentError::RefundNotRefundable, array( 'order_uuid' => $order->uuid ) );

		if ( $intent->hasUnappliedResult ) {
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

		// Another refund of the intent is claimed and its answer is not recorded: what it did is not
		// known, and once recorded it would move what refund uuids are named by. This one waits for it.
		if ( null !== $intent->openClaim && $intent->openClaim !== $uuid ) {
			CodedException::raise( PaymentError::RefundUnresolved, array( 'refund_uuid' => $intent->openClaim ) );
		}

		$plan = new RefundPlan( $uuid, $order, $intent, $portions, $shipping, RefundAllocation::total( $portions, $shipping, $order->currency, $order->baseCurrency ), $request->reasonCode );

		$this->checkCaps( $plan, $components );

		// Less than was captured is a partial refund to the provider, whether or not a refund came before it.
		$operation = $plan->total->amount->gross()->compare( $intent->captured ) < 0 ? Operations::PARTIAL_REFUND : Operations::REFUND;

		$this->payments->require( $intent->gatewayId, $operation, $intent->mode, $order->currency );

		return $plan;
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
	 * The claim says how the refund ended: its document when it was recorded, a decline, or what
	 * was left for a person. A refund still claimed may be on its way, or may have been made by the
	 * gateway while its answer was lost: the gateway is asked what became of it, at transaction
	 * depth 0, and a refund it made, or declined, is recorded as a first answer is. When it cannot
	 * say, or says it made no such refund, however old the claim, the refund is refused, nothing is
	 * written, and the claim waits for a person.
	 *
	 * @since 0.1.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer; the claim stays as it was.
	 * @throws CodedException     `payment.refund_declined`; `payment.unreconciled`;
	 *                            `payment.refund_unresolved` with the refund's uuid.
	 *
	 * @param RefundPlan     $plan    The refund, which an earlier request claimed.
	 * @param PaymentGateway $gateway The intent's gateway.
	 * @param Actor          $actor   Who refunds.
	 * @return Refund The refund's document.
	 */
	private function askedBefore( RefundPlan $plan, PaymentGateway $gateway, Actor $actor ): Refund {
		$claim = $this->refunds->findClaim( $plan->uuid );

		if ( null !== $claim && ClaimState::Claimed === $claim->state ) {
			return $this->askWhatBecameOf( $plan, $gateway, $actor );
		}

		return $this->answerFrom( $plan, $claim );
	}

	/**
	 * Answers a refund from how its claim ended: its document when it was recorded, a decline, or what was left for a person.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `payment.refund_declined`; `payment.unreconciled` when what the gateway
	 *                        answered was left for a person, or there is no claim where one was found.
	 *
	 * @param RefundPlan       $plan  The refund.
	 * @param RefundClaim|null $claim Its claim, ended.
	 * @return Refund The refund's document.
	 */
	private function answerFrom( RefundPlan $plan, ?RefundClaim $claim ): Refund {
		return match ( $claim?->state ) {
			ClaimState::Recorded => $this->recordedDocument( $plan, $claim->transactionId ) ?? CodedException::raise( PaymentError::Unreconciled ),
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
			return $this->answerFrom( $plan, $this->refunds->findClaim( $plan->uuid ) );
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
		return $this->endWithout( $plan, $result, $this->payments->recordUnapplied( $result, $actor ) );
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

		return $this->endWithout( $plan, $result, $application );
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
	 * @param RefundPlan    $plan        The refund.
	 * @param GatewayResult $result      The gateway's answer.
	 * @param Application   $application What the ledger did with it: anything but an application.
	 * @return Refund|null The refund's document, recorded before; null otherwise.
	 */
	private function endWithout( RefundPlan $plan, GatewayResult $result, Application $application ): ?Refund {
		$document = $this->documentOf( $plan, $application );

		if ( null !== $document ) {
			return $document;
		}

		$duplicate = ApplicationKind::Duplicate === $application->kind;
		$declined  = ! $duplicate && Outcome::Declined === $result->outcome;
		$ended     = $this->refunds->settleClaim( $plan->uuid, $declined ? ClaimState::Declined : ClaimState::Unreconciled, $duplicate ? null : $application->transactionId );

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
	 * @param RefundPlan  $plan        The refund.
	 * @param Application $application What the ledger did with the gateway's answer.
	 * @return Refund|null The document; null when the answer was not a duplicate, or its row has no document of this refund.
	 */
	private function documentOf( RefundPlan $plan, Application $application ): ?Refund {
		return ApplicationKind::Duplicate === $application->kind ? $this->recordedDocument( $plan, $application->transactionId ) : null;
	}

	/**
	 * Returns the document that states a ledger row, when it is this refund's: of the same uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param RefundPlan $plan          The refund.
	 * @param int|null   $transactionId The ledger row, or null for none.
	 * @return Refund|null The document; null when the row has no document of this refund.
	 */
	private function recordedDocument( RefundPlan $plan, ?int $transactionId ): ?Refund {
		$document = null === $transactionId ? null : $this->refunds->findByTransaction( $transactionId );

		return null !== $document && $document->uuid === $plan->uuid ? $document : null;
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
