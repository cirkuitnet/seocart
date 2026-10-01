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

use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\RefundableLine;
use SEOCart\Order\Domain\RefundableOrder;
use SEOCart\Order\Domain\StoredTaxComponent;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Event\RefundRecorded;
use SEOCart\Payment\Domain\Gateway\GatewayRefund;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\Gateway\GatewayUnavailable;
use SEOCart\Payment\Domain\Gateway\PaymentGateway;
use SEOCart\Payment\Domain\Outcome;
use SEOCart\Payment\Domain\Refund\LinePortion;
use SEOCart\Payment\Domain\Refund\Refund;
use SEOCart\Payment\Domain\Refund\RefundAllocation;
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
 *    reconciled that money; and what earlier refunds returned. RefundAllocation allocates the
 *    shares from those stored figures, and every cap is checked against the same reads: the units
 *    each line has left, what is left of each component and of the shipping, and what the intent
 *    captured and has not refunded, in both currencies. A refusal here happens before the gateway
 *    is asked.
 * 3. The gateway's refund, at transaction depth 0: it may go over the network. Its idempotency key
 *    is the refund's uuid, derived from the refund itself (below).
 * 4. One transaction, which locks the intent and then the order: the gateway's answer applied to
 *    the ledger with the refund's base share (applyGatewayResult()), then the document, its lines
 *    and its components, each insert carrying its cap, and the lines' refunded quantities by one
 *    conditional update, then RefundRecorded, all in one savepoint. If anything there refuses or
 *    fails after the gateway gave the money back (a cap, because another refund landed after the
 *    reads, or any other error), the savepoint takes it all back, the money is recorded for a
 *    person (recordUnapplied()), and the caller is answered `payment.unreconciled`, with what
 *    failed as its previous exception. So is an approval of another amount than was asked. A lost
 *    deadlock and a transaction whose integrity was lost are not caught: there is no transaction
 *    left to record the money in. A deadlock runs the whole transaction again; when every attempt
 *    is lost, nothing was recorded, and the same refund asked again asks with the same key.
 *
 * The refund's uuid is RefundIdentity's: a name-based uuid of the order, the units asked of each
 * line, whether the shipping is asked for, and what the intent had refunded when it was read. So
 * the same refund asked again before the first was recorded (after the gateway's answer was lost,
 * after the process died between the gateway's call and the transaction, or by two people at
 * once) asks the gateway again with the same key, and a gateway that honours the key answers with
 * the refund it already made: it is recorded once. A delivery of it the ledger already has
 * returns its document; a delivery whose document is another refund's is answered
 * `payment.unreconciled`, and moves nothing. What the key does not cover:
 *
 * - a refund that was recorded but whose answer the caller lost: the intent's refunded amount
 *   moved, so the same request asked again is a new refund, with a new key, which the caps allow
 *   while the units are left;
 * - nobody asking again: a refund the gateway made, whose answer was lost before anything was
 *   recorded, stays unrecorded until someone asks again or reconciliation finds it;
 * - a gateway that does not honour the key: asked again, it makes a second refund, of which only
 *   the one whose answer arrived is recorded;
 * - a declined refund asked again: the same key, so a gateway that keeps its answers by key
 *   answers with the same decline until it forgets the key or the intent's refunded amount moves.
 *
 * So the admin operation that calls this must persist a claim of the refund it asks for (who
 * asked, what, and when) before the gateway is called, so that a refund whose answer was lost is
 * found and asked again; this service persists no claim.
 *
 * Nothing here reads a rate, a tax rate, a price or the calculation: the order's conversion
 * context is copied, and every figure is a share of what the order stored. A declined refund
 * records the decline and nothing else.
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
	 * The gateway.
	 *
	 * @since 0.1.0
	 *
	 * @var PaymentGateway
	 */
	private PaymentGateway $gateway;

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
	 *
	 * @param RefundRepository   $refunds    The refund statements.
	 * @param OrderRepository    $orders     The order statements.
	 * @param PaymentService     $payments   The money path.
	 * @param PaymentGateway     $gateway    The gateway.
	 * @param TransactionManager $tx         The unit of work.
	 * @param EventPublisher     $events     Publishes the events.
	 * @param Authorizer         $authorizer Checks capabilities.
	 * @param Clock              $clock      Says when an event happened.
	 */
	public function __construct( RefundRepository $refunds, OrderRepository $orders, PaymentService $payments, PaymentGateway $gateway, TransactionManager $tx, EventPublisher $events, Authorizer $authorizer, Clock $clock ) {
		$this->refunds    = $refunds;
		$this->orders     = $orders;
		$this->payments   = $payments;
		$this->gateway    = $gateway;
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
	 * @throws GatewayUnavailable   When the gateway has no answer; nothing was recorded.
	 * @throws TransactionRetryable When every attempt to record the answer lost a deadlock; nothing was recorded.
	 * @throws CodedException       `authorization.denied`, before any read; `order.not_found`;
	 *                              `payment.refund_not_refundable` when the order has no captured intent;
	 *                              `payment.unreconciled` when the ledger holds a result of the intent
	 *                              applied to nothing; `payment.refund_line_not_found`;
	 *                              `payment.refund_line_exhausted` with the units the line has left;
	 *                              `payment.refund_exceeds_captured`; `payment.refund_nothing_left` —
	 *                              each before the gateway is asked; `payment.refund_declined` when the
	 *                              gateway declined; and `payment.unreconciled` when the gateway gave the
	 *                              money back but the refund could not be recorded, which is then left
	 *                              for a person, with what kept it from being recorded as the previous
	 *                              exception, when something did.
	 *
	 * @param RefundRequest $request What to refund.
	 * @param Actor         $actor   Who refunds it.
	 * @return Refund The refund; for the same refund asked again, which the gateway answered with the refund it had already made, its document.
	 */
	public function refund( RefundRequest $request, Actor $actor ): Refund {
		$this->authorizer->authorize( $actor, self::CAPABILITY );
		$this->requireNoTransaction();

		$plan     = $this->plan( $request );
		$result   = $this->askGateway( $plan );
		$recorded = $this->tx->transaction( fn(): Refund|\Throwable|null => $this->record( $plan, $result, $actor ), RetryPolicy::deadlocks() );

		if ( $recorded instanceof Refund ) {
			return $recorded;
		}

		if ( Outcome::Approved !== $result->outcome ) {
			CodedException::raise( PaymentError::RefundDeclined );
		}

		// Raised only now, once the transaction has committed the money kept for a person.
		$unreconciled = CodedException::because( PaymentError::Unreconciled, array(), $recorded );

		throw $unreconciled;
	}

	/**
	 * Works the refund out from plain reads, and checks every cap, before the gateway is asked.
	 *
	 * Reads the order with the lines asked for, its shipping and their components; its captured
	 * intent, with whether the ledger holds a result of it applied to nothing; and what earlier
	 * refunds returned of the lines, the components and the shipping: one read of each kind,
	 * whatever the number of lines. Money of the intent that a person has not reconciled refuses
	 * the refund: what it did is not known, so a refund worked out without it could give back more
	 * than is left.
	 *
	 * @since 0.1.0
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
		$uuid         = RefundIdentity::uuid( $order->uuid, $units, $request->shipping, $intent->refunded );
		$plan         = new RefundPlan( $uuid, $order, $intent, $portions, $shipping, RefundAllocation::total( $portions, $shipping, $order->currency, $order->baseCurrency ), $request->reasonCode );

		$this->checkCaps( $plan, $components );

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
	 *
	 * @throws \LogicException     Inside a transaction.
	 * @throws GatewayUnavailable When the gateway has no answer.
	 *
	 * @param RefundPlan $plan The refund.
	 * @return GatewayResult The gateway's answer, not yet applied.
	 */
	private function askGateway( RefundPlan $plan ): GatewayResult {
		$this->requireNoTransaction();

		return $this->gateway->refund( new GatewayRefund( $plan->intent->uuid, $plan->intent->providerIntentId, $plan->total->amount->gross(), $plan->uuid ) );
	}

	/**
	 * Records the gateway's answer, in the transaction the caller opened: the refund and its document, or, when it cannot be recorded as worked out, the money for a person.
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
	private function record( RefundPlan $plan, GatewayResult $result, Actor $actor ): Refund|\Throwable|null {
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
		return $this->documentOf( $plan, $this->payments->recordUnapplied( $result, $actor ) );
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

		return $this->documentOf( $plan, $application );
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
		if ( ApplicationKind::Duplicate !== $application->kind || null === $application->transactionId ) {
			return null;
		}

		$first = $this->refunds->findByTransaction( $application->transactionId );

		return null !== $first && $first->uuid === $plan->uuid ? $first : null;
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
