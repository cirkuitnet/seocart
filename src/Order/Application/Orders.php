<?php
/**
 * Orders: places orders and changes their status, each change with its record and its events
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\ConversionContexts;
use SEOCart\Order\Domain\Event\OrderCreated;
use SEOCart\Order\Domain\Event\OrderPlaced;
use SEOCart\Order\Domain\Event\OrderStatusChanged;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Domain\LockedOrder;
use SEOCart\Order\Domain\Machine;
use SEOCart\Order\Domain\NewOrder;
use SEOCart\Order\Domain\OrderNumberGenerator;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\OrderStatusRegistry;
use SEOCart\Order\Domain\OrderView;
use SEOCart\Order\Domain\PaymentDelta;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Order\Domain\Transition;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Events\EventPublisher;
use SEOCart\Platform\Logging\CorrelationId;
use SEOCart\Support\Clock;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\IdGenerator;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The order module's application service: every order is written and changed through it.
 *
 * Owns one fact: how the order's statements are sequenced. The repository owns each statement
 * and the invariant in its WHERE clause, and the status registry owns which transitions exist;
 * this class decides their order, raises the coded errors, records each change in
 * `order_events`, and publishes the events from the statements' own results, so a rolled-back
 * attempt publishes nothing.
 *
 * Placing an order and recording a payment run only inside the caller's transaction: an order
 * committed without its holds, its intents and its outbox rows, or a projection moved without its
 * ledger row, is the inconsistency the caller's unit of work exists to prevent. A transition runs
 * in the caller's transaction or its own.
 *
 * Nothing here adds money up: every total is copied from the document the calculation produced,
 * and a payment's amounts are added by the database, in the one statement that also checks them.
 *
 * @since 0.1.0
 */
final class Orders {

	/**
	 * The version of an order's first totals snapshot.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const FIRST_TOTALS_VERSION = 1;

	/**
	 * The reason of the first event of every order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PLACED = 'placed';

	/**
	 * The reason of the transition that accepts an order.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_APPROVED = 'payment_approved';

	/**
	 * The status an order enters when it is accepted. With no review policy, an accepted order is processed at once.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderStatus
	 */
	private const ACCEPTED = OrderStatus::Processing;

	/**
	 * The statuses an order is accepted from: those of an order not yet accepted. Any later return to processing is a transition, and announces nothing.
	 *
	 * @since 0.1.0
	 *
	 * @var list<OrderStatus>
	 */
	private const NOT_YET_ACCEPTED = array( OrderStatus::PendingPayment, OrderStatus::AwaitingReview );

	/**
	 * A reason: a lowercase snake_case word that fits `order_events.reason`.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const REASON_PATTERN = '/^[a-z][a-z0-9_]{0,63}\z/';

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * Allocates order numbers.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderNumberGenerator
	 */
	private OrderNumberGenerator $numbers;

	/**
	 * Makes and hashes access keys.
	 *
	 * @since 0.1.0
	 *
	 * @var AccessKeys
	 */
	private AccessKeys $keys;

	/**
	 * Freezes the rates orders are placed at.
	 *
	 * @since 0.1.0
	 *
	 * @var ConversionContexts
	 */
	private ConversionContexts $contexts;

	/**
	 * The order state machine.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderStatusRegistry
	 */
	private OrderStatusRegistry $registry;

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
	 * Mints order uuids.
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
	 * The request's correlation id, which an order and its events share.
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
	 * @param OrderRepository      $orders      The statements.
	 * @param OrderNumberGenerator $numbers     Allocates order numbers.
	 * @param AccessKeys           $keys        Makes and hashes access keys.
	 * @param ConversionContexts   $contexts    Freezes the rates orders are placed at.
	 * @param OrderStatusRegistry  $registry    The order state machine.
	 * @param TransactionManager   $tx          The unit of work.
	 * @param EventPublisher       $events      Publishes the events.
	 * @param IdGenerator          $ids         Mints order uuids.
	 * @param Clock                $clock       Says when an event happened.
	 * @param CorrelationId        $correlation The request's correlation id.
	 */
	public function __construct( OrderRepository $orders, OrderNumberGenerator $numbers, AccessKeys $keys, ConversionContexts $contexts, OrderStatusRegistry $registry, TransactionManager $tx, EventPublisher $events, IdGenerator $ids, Clock $clock, CorrelationId $correlation ) {
		$this->orders      = $orders;
		$this->numbers     = $numbers;
		$this->keys        = $keys;
		$this->contexts    = $contexts;
		$this->registry    = $registry;
		$this->tx          = $tx;
		$this->events      = $events;
		$this->ids         = $ids;
		$this->clock       = $clock;
		$this->correlation = $correlation;
	}

	/**
	 * Writes an order from its document, in its first status, inside the caller's transaction.
	 *
	 * Fourteen statements, whatever the number of lines: the rate is frozen, the number allocated
	 * last before the order row (its counter row stays locked until the caller commits, so
	 * placements serialise only for what follows), the order and each kind of child row written in
	 * one statement, the ids of the lines and adjustments read back once each, the order pointed at
	 * its totals snapshot, its first event recorded and OrderCreated stored in the outbox. The
	 * access key is returned here, once, and stored only as its hash.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 *
	 * @param NewOrder $order The document.
	 * @param Actor    $actor Who places it.
	 * @return InsertedOrder The order's identity and its access key.
	 */
	public function insert( NewOrder $order, Actor $actor ): InsertedOrder {
		$this->requireCallersTransaction( __FUNCTION__ );

		list( $actorType, $actorId ) = self::actorOf( $actor );

		$correlationId = $this->correlation->current();
		$status        = $this->registry->initial();
		$uuid          = $this->ids->generate();
		$accessKey     = $this->keys->generate();
		$contextId     = $this->contexts->freeze( $order->conversionContext );
		$number        = $this->numbers->next( OrderNumberGenerator::DEFAULT_SCOPE );
		$orderId       = $this->orders->insertOrder( $order, $uuid, $number, $status, $contextId, $this->keys->hash( $accessKey ), $actorType, $actorId, $correlationId );
		$lineIds       = $this->orders->insertLines( $orderId, $order );

		$this->orders->insertLineOptions( $order, $lineIds );

		$adjustmentIds = $this->orders->insertAdjustments( $orderId, $order, $lineIds );

		$this->orders->insertTaxComponents( $orderId, $order, $lineIds, $adjustmentIds, self::FIRST_TOTALS_VERSION );
		$this->orders->insertAddresses( $orderId, $order );

		$totalsId = $this->orders->insertTotals( $orderId, $order, $contextId, self::FIRST_TOTALS_VERSION );

		$this->orders->setCurrentTotals( $orderId, $totalsId );
		$this->orders->appendEvent( $orderId, Machine::Order, '', $status->value, self::PLACED, $actorType, $actorId, $correlationId );

		$this->events->publish( new OrderCreated( $orderId, $uuid, $number, $order->channel->value, $order->currency()->code(), $order->totals->grandTotal->minorUnits(), $this->clock->now() ) );

		return new InsertedOrder( $orderId, $uuid, $number, $accessKey );
	}

	/**
	 * Changes an order's status, when the registry allows it, in the caller's transaction or its own.
	 *
	 * Three statements and the outbox row: the order is locked, which also reads the status it
	 * changes from; one conditional update changes the status only from a status the registry
	 * lists for the target, and, for a status that claims payment, only while the payment status
	 * is settled; the change is recorded in `order_events`. The registry is not asked in PHP
	 * first: the update's WHERE clause is compiled from it, so the database refuses what the table
	 * does not allow.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `order.not_found` when there is no such order;
	 *         `order.transition_illegal` when the registry does not allow the change from the order's
	 *         status, or the target claims payment the order does not have. An \InvalidArgumentException,
	 *         before any statement, when the reason is not a lowercase snake_case word.
	 *
	 * @param int         $orderId The order's internal id.
	 * @param OrderStatus $to      The status wanted.
	 * @param string      $reason  Why, a lowercase snake_case word of at most 64 characters, such as `amount_mismatch`.
	 * @param Actor       $actor   On whose authority.
	 * @return Transition The change.
	 */
	public function transition( int $orderId, OrderStatus $to, string $reason, Actor $actor ): Transition {
		self::checkReason( $reason );

		return $this->tx->transaction(
			fn(): Transition => $this->transitionLocked( $this->lock( $orderId ), $to, $this->registry->allowedFrom( $to ), $reason, $actor ),
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Accepts an order whose payment was approved: the one place OrderPlaced is published.
	 *
	 * The transition to the accepted status, with the reason `payment_approved`, and OrderPlaced
	 * with the order's summary, read under the same lock. Only an order not yet accepted is
	 * accepted: the update's WHERE clause lists only the statuses the registry allows the accepted
	 * status from that are also NOT_YET_ACCEPTED, so an order is announced once. A parked order
	 * that returns to processing does so through transition(), and announces nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.not_found`; `order.transition_illegal` when the order is not one that may be accepted.
	 *
	 * @param int   $orderId The order's internal id.
	 * @param Actor $actor   On whose authority.
	 * @return Transition The change.
	 */
	public function accept( int $orderId, Actor $actor ): Transition {
		return $this->tx->transaction(
			function () use ( $orderId, $actor ): Transition {
				$order      = $this->lock( $orderId );
				$from       = array_values( array_filter( $this->registry->allowedFrom( self::ACCEPTED ), static fn( OrderStatus $status ): bool => in_array( $status, self::NOT_YET_ACCEPTED, true ) ) );
				$transition = $this->transitionLocked( $order, self::ACCEPTED, $from, self::PAYMENT_APPROVED, $actor );

				$this->events->publish(
					new OrderPlaced(
						$order->id,
						$order->uuid,
						$order->orderNumber,
						$order->channel->value,
						$order->currency()->code(),
						$order->grandTotal->minorUnits(),
						$order->baseCurrency()->code(),
						$order->baseGrandTotal->minorUnits(),
						$order->customerId,
						$order->actorType,
						$order->actorId,
						$this->clock->now()
					)
				);

				return $transition;
			},
			RetryPolicy::deadlocks()
		);
	}

	/**
	 * Locks an order for a payment, inside the caller's transaction, and returns what the payment decides from.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 * @throws CodedException  `order.not_found` when there is no such order.
	 *
	 * @param int $orderId The order's internal id, never a value from a request.
	 * @return LockedOrder The order, as locked.
	 */
	public function lockForPayment( int $orderId ): LockedOrder {
		$this->requireCallersTransaction( __FUNCTION__ );

		return $this->lock( $orderId );
	}

	/**
	 * Adds a payment's amounts to an order locked by lockForPayment(), and records a change of its payment status.
	 *
	 * One conditional update moves the amounts, the amount due included, by the payment's; writes
	 * the payment status the caller derived from its ledger; and refuses an order in other
	 * currencies, or a change that would authorize or capture more than the grand total in either
	 * currency, refund more than was captured, or leave less than nothing due. When the payment
	 * status changes, one `order_events` row records it. Runs only inside the caller's
	 * transaction, which appends the ledger row the amounts come from.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException           Outside a transaction, before any statement.
	 * @throws \InvalidArgumentException When the reason is not a lowercase snake_case word.
	 *
	 * @param LockedOrder   $locked The order, as lockForPayment() returned it in this transaction.
	 * @param PaymentDelta  $delta  What the payment adds.
	 * @param PaymentStatus $status The payment status once the amounts are added.
	 * @param string        $reason Why, for the event: a lowercase snake_case word such as `payment_captured`.
	 * @param Actor         $actor  On whose authority.
	 * @return bool True when the payment was recorded; false when the update refused it, which the caller reports.
	 */
	public function recordPayment( LockedOrder $locked, PaymentDelta $delta, PaymentStatus $status, string $reason, Actor $actor ): bool {
		$this->requireCallersTransaction( __FUNCTION__ );
		self::checkReason( $reason );

		if ( ! $this->orders->recordPayment( $locked->id, $delta, $status ) ) {
			return false;
		}

		if ( $status !== $locked->paymentStatus ) {
			list( $actorType, $actorId ) = self::actorOf( $actor );

			$this->orders->appendEvent( $locked->id, Machine::Payment, $locked->paymentStatus->value, $status->value, $reason, $actorType, $actorId, $this->correlation->current() );
		}

		return true;
	}

	/**
	 * Reads an order for showing it, by its public identifier.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.not_found` when there is no such order.
	 *
	 * @param string $uuid The public identifier.
	 * @return OrderView The order.
	 */
	public function findByUuid( string $uuid ): OrderView {
		$order = $this->orders->findByUuid( $uuid );

		if ( null === $order ) {
			CodedException::raise( OrderError::NotFound );
		}

		return $order;
	}

	/**
	 * Changes the status of an order this transaction has locked, records it and publishes it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.transition_illegal` when the update refused the change.
	 *
	 * @param LockedOrder   $order       The order, locked.
	 * @param OrderStatus   $to          The status wanted.
	 * @param OrderStatus[] $allowedFrom The statuses the update may change it from: the registry's, or fewer.
	 * @param string        $reason      Why.
	 * @param Actor         $actor       On whose authority.
	 * @return Transition The change.
	 *
	 * @phpstan-param list<OrderStatus> $allowedFrom
	 */
	private function transitionLocked( LockedOrder $order, OrderStatus $to, array $allowedFrom, string $reason, Actor $actor ): Transition {
		if ( ! $this->orders->transition( $order->id, $to, $allowedFrom, $this->registry->metadata( $to )->isPaid ) ) {
			// Classified from the locked read: under the lock, the status it read is still the order's.
			CodedException::raise(
				OrderError::TransitionIllegal,
				array(
					'from' => $order->status->value,
					'to'   => $to->value,
				)
			);
		}

		list( $actorType, $actorId ) = self::actorOf( $actor );

		$eventId = $this->orders->appendEvent( $order->id, Machine::Order, $order->status->value, $to->value, $reason, $actorType, $actorId, $this->correlation->current() );

		$this->events->publish( new OrderStatusChanged( $order->id, $order->status->value, $to->value, $reason, $actorType, $actorId, $this->clock->now() ) );

		return new Transition( $order->id, $order->status, $to, $eventId );
	}

	/**
	 * Locks an order, and raises when there is none.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.not_found`.
	 *
	 * @param int $orderId The order's internal id.
	 * @return LockedOrder The order, locked.
	 */
	private function lock( int $orderId ): LockedOrder {
		$order = $this->orders->lock( $orderId );

		if ( null === $order ) {
			CodedException::raise( OrderError::NotFound );
		}

		return $order;
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
			throw new \LogicException( sprintf( 'Orders::%s() runs inside the caller\'s transaction: what it writes must commit with the rest of the caller\'s unit of work.', $method ) );
		}
	}

	/**
	 * Refuses a reason the event record cannot hold.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When it is not a lowercase snake_case word of at most 64 characters.
	 *
	 * @param string $reason The reason.
	 */
	private static function checkReason( string $reason ): void {
		if ( 1 !== preg_match( self::REASON_PATTERN, $reason ) ) {
			throw new \InvalidArgumentException( 'A reason is a lowercase snake_case word of at most 64 characters, such as amount_mismatch.' );
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
