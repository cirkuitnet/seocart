<?php
/**
 * SettlePlacement: applies a gateway's answer to a placed order and settles what the order holds, in one unit of work
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Application\CartService;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Domain\SettledPlacement;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Operation;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\VoidReason;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Promotion\Application\PromotionUsage;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The second unit of work of an order placement: the gateway's answer to the order's authorization, applied and settled.
 *
 * Owns one fact: what a placement's settlement does with each answer. Every path that learns the
 * gateway's answer comes here: the placement itself, a webhook, the reconciliation job. One
 * transaction, at READ COMMITTED and run again whole on a deadlock, takes its locks in one order:
 * the intent and the order, which the payment path locks first, then the stock items in ascending
 * order, the promotions, and the cart last. The payment path applies the answer, with the money and
 * the order's status; by what it came to, this settles the rest in the same transaction:
 *
 * - approved: the order's held units are allocated to it, its promotions' uses committed and its
 *   cart converted. When its units are gone, because its hold expired and was taken, the order
 *   goes on hold for a person, with its uses committed and its cart converted: the payment stands;
 * - declined: its hold released, its uses given back and its cart opened again;
 * - an amount or a currency the order does not have: the order is on hold already, by the payment
 *   path, and nothing is ever captured; its units are allocated to it while a person looks, its uses
 *   committed and its cart converted;
 * - an approval that came after the placement ended without it, its intent failed: the payment path
 *   keeps it unapplied and flags the order; the units, the uses and the cart it gave back stay so;
 * - the shopper must act, or the gateway is still deciding: nothing more; the order keeps its hold
 *   and its cart keeps placing it;
 * - the void of a payment whose shopper never acted, which the gateway made: what is left of its
 *   hold released, its uses given back and its cart opened again, the payment path having cancelled
 *   the order. A void that lands after the approval did releases nothing: the payment path parked
 *   the order for a person, with what it holds. A gateway that answers the void with the
 *   authorization's approval, the shopper having finished just before, is settled as an approval;
 * - an answer applied before, or a stale one, about a state the intent has left, or a void kept
 *   for a person: nothing.
 *
 * An order placed with nothing to pay has no answer to wait for: settleNothingDue() runs the
 * same unit of work with the payment path recording it paid instead of applying an answer, the
 * order locked first, and settles it as an approval.
 *
 * Last, after the cart, the answer the placement's key keeps is rewritten to what the settlement
 * came to, so a retry of the placement is told where it stands now; an answer applied before
 * leaves it as the first settlement wrote it. While the shopper must act, the answer keeps what
 * they must do, sealed by the placement's own request, which alone can seal it; once they need not,
 * it is cleared. The placement's own request writes it even when another path settled the request
 * to act first, as long as the payment still waits for the shopper under the intent's lock.
 *
 * @since 0.1.0
 */
final class SettlePlacement {

	/**
	 * The reason an order goes on hold when its payment stands but its units are gone.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const STOCK_UNAVAILABLE = 'stock_unavailable';

	/**
	 * The reason a declined order's hold is released with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const PAYMENT_DECLINED = 'payment_declined';

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param TransactionManager $tx       The unit of work.
	 * @param PaymentService     $payments The one money path.
	 * @param Orders             $orders   The order's lines, and its status when its units are gone.
	 * @param StockService       $stock    The order's hold: allocated, or released.
	 * @param PromotionUsage     $usage    The order's promotion uses: committed, or given back.
	 * @param CartService        $carts    The cart that placed the order: converted, or opened again.
	 * @param IdempotencyKeys    $keys     The answer the placement's key keeps: rewritten to the outcome.
	 */
	public function __construct(
		private TransactionManager $tx,
		private PaymentService $payments,
		private Orders $orders,
		private StockService $stock,
		private PromotionUsage $usage,
		private CartService $carts,
		private IdempotencyKeys $keys
	) {
	}

	/**
	 * Applies the gateway's answer to an order's authorization, or to its void, and settles the order's stock, promotion uses and cart, in one transaction.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Settles the void of a payment whose shopper never acted.
	 *
	 * @throws CodedException|\InvalidArgumentException What the payment path refuses: nothing is changed. An
	 *         \InvalidArgumentException when the answer is not about the order's authorization or its void: a
	 *         placement settles nothing else.
	 *
	 * @param GatewayResult   $result     The gateway's answer.
	 * @param Actor           $actor      On whose authority: the shopper, or the system for a job.
	 * @param VoidReason|null $voidReason Optional. Why the void the answer is about was asked for. Default null.
	 * @param string|null     $keptAction Optional. What the shopper must do, sealed for the answer the placement's key
	 *                                    keeps (KeptAnswer::sealAction()); only the placement's own request has it.
	 *                                    Default null.
	 * @return SettledPlacement What the settlement came to.
	 */
	public function apply( GatewayResult $result, Actor $actor, ?VoidReason $voidReason = null, ?string $keptAction = null ): SettledPlacement {
		if ( ! in_array( $result->operation, array( Operation::Authorize, Operation::Void ), true ) ) {
			throw new \InvalidArgumentException( 'A placement is settled by the answer to its authorization, or to the void of it.' );
		}

		return $this->tx->transaction(
			fn(): SettledPlacement => $this->settle( $this->payments->applyGatewayResult( $result, $actor, null, $voidReason ), $actor, $voidReason, $keptAction ),
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Voids, as the store, a payment whose shopper was asked to act and whose time to do so ran out, and settles the void; answers null for any other payment.
	 *
	 * The end of a shopper's time to act, wherever it is found: by the reconciliation run, or by a
	 * resume that comes too late. A real provider keeps such a payment waiting until something
	 * cancels it, so the gateway is asked to void it, outside any transaction, before anything the
	 * order holds is released; the void is then settled as apply() settles it. A provider that had
	 * approved meanwhile answers with the approval, which is settled as an approval: the order goes
	 * on, and nothing is released.
	 *
	 * @since 0.2.0
	 *
	 * @throws GatewayUnavailable When the gateway has no answer; nothing was applied, and asking again sends the same key.
	 * @throws CodedException     `payment.operation_declined` when the provider refuses to cancel, which changes
	 *                            nothing; what asking for the void refuses (PaymentService::askVoid()); what the
	 *                            settlement refuses.
	 *
	 * @param IntentRef $intent The payment, as read outside any transaction.
	 * @return SettledPlacement|null What the settlement of the void came to; null when the payment does not wait for
	 *                               a shopper whose time ran out, and nothing was asked.
	 */
	public function voidEndedAction( IntentRef $intent ): ?SettledPlacement {
		if ( IntentStatus::RequiresAction !== $intent->status || ! $intent->waitEnded ) {
			return null;
		}

		$void = $this->payments->askVoid( $intent->uuid, self::store(), VoidReason::ActionWindowEnded );

		if ( Operation::Void === $void->operation && Outcome::Declined === $void->outcome ) {
			CodedException::raise(
				PaymentError::OperationDeclined,
				array(
					'gateway_id' => $void->provider,
					'operation'  => Operation::Void->value,
				)
			);
		}

		return $this->apply( $void, self::store(), VoidReason::ActionWindowEnded );
	}

	/**
	 * Returns the actor the store settles a waiting payment as, on no user's authority: the reconciliation run's, whoever found the payment waiting.
	 *
	 * @since 0.2.0
	 *
	 * @return Actor The actor.
	 */
	public static function store(): Actor {
		return Actor::system( 'reconciliation', 0 );
	}

	/**
	 * Settles an order placed with nothing to pay, in one transaction: the payment path records it paid and accepts it, and the order's stock, promotion uses and cart are settled as for an approval.
	 *
	 * The order has no intent and no gateway was asked. Its units are allocated, its uses committed
	 * and its cart converted, and the answer its key keeps is rewritten; when its units are gone, it
	 * goes on hold as an approved one does. An order settled before is answered as a duplicate, and
	 * nothing changes.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\LogicException What the payment path refuses: nothing is changed.
	 *
	 * @param int   $orderId The order: its grand total is zero.
	 * @param Actor $actor   On whose authority: the shopper, or the system for a job.
	 * @return SettledPlacement What the settlement came to.
	 */
	public function settleNothingDue( int $orderId, Actor $actor ): SettledPlacement {
		return $this->tx->transaction(
			function () use ( $orderId, $actor ): SettledPlacement {
				$paid = $this->payments->settleNothingDue( $orderId, $actor );

				if ( null === $paid->acceptedAs ) {
					return new SettledPlacement( PlacementOutcome::Duplicate, $paid->orderId, null, $paid->paymentStatus );
				}

				return $this->accept( $paid->orderId, self::holdGroup( $paid->orderId, $paid->holdGroup ), $paid->acceptedAs, $paid->paymentStatus, $actor );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Settles what an applied answer leaves to the order's placement.
	 *
	 * @since 0.1.0
	 *
	 * @param Application     $applied    What the payment path did.
	 * @param Actor           $actor      On whose authority.
	 * @param VoidReason|null $voidReason Why a void was asked for; null for an authorization's answer.
	 * @param string|null     $keptAction What the shopper must do, sealed; null when the caller has none.
	 * @return SettledPlacement What the settlement came to.
	 */
	private function settle( Application $applied, Actor $actor, ?VoidReason $voidReason, ?string $keptAction ): SettledPlacement {
		if ( Operation::Void === $applied->operation && ApplicationKind::Applied === $applied->kind && null !== $voidReason ) {
			return $this->voided( $applied, $voidReason );
		}

		if ( Operation::Void === $applied->operation ) {
			// Applied before, stale, or kept for a person: the payment path settled the order, and nothing more moves.
			return new SettledPlacement( PlacementOutcome::Duplicate, $applied->orderId, $applied->orderStatusTo, $applied->paymentTo );
		}

		switch ( $applied->kind ) {
			case ApplicationKind::Applied:
				return $this->accept( $applied->orderId, self::holdGroup( $applied->orderId, $applied->holdGroup ), $applied->orderStatusTo, $applied->paymentTo, $actor );

			case ApplicationKind::Mismatch:
				if ( IntentTransitions::isFinal( $applied->intentFrom ) ) {
					$outcome = PlacementOutcome::LateApproval;
					break;
				}

				$this->allocate( $applied->orderId, self::holdGroup( $applied->orderId, $applied->holdGroup ) );
				$this->close( $applied->orderId, true );

				$outcome = PlacementOutcome::AmountMismatch;
				break;

			case ApplicationKind::Declined:
				$this->stock->release( self::holdGroup( $applied->orderId, $applied->holdGroup ), self::PAYMENT_DECLINED );
				$this->close( $applied->orderId, false );

				$outcome = PlacementOutcome::Declined;
				break;

			case ApplicationKind::RequiresAction:
				$outcome = PlacementOutcome::RequiresAction;
				break;

			case ApplicationKind::Duplicate:
				// Another path settled the request to act first; the shopper still must act, and only this request can say how.
				if ( null !== $keptAction && IntentStatus::RequiresAction === $applied->intentTo ) {
					return $this->answered( $applied->orderId, PlacementOutcome::RequiresAction, $applied->orderStatusTo, $applied->paymentTo, $keptAction );
				}

				return new SettledPlacement( PlacementOutcome::Duplicate, $applied->orderId, $applied->orderStatusTo, $applied->paymentTo );

			case ApplicationKind::Pending:
				$outcome = PlacementOutcome::Processing;
				break;

			default:
				// Applied before, or stale: the payment path changed nothing, and nor does the settlement.
				return new SettledPlacement( PlacementOutcome::Duplicate, $applied->orderId, $applied->orderStatusTo, $applied->paymentTo );
		}

		return $this->answered( $applied->orderId, $outcome, $applied->orderStatusTo, $applied->paymentTo, $keptAction );
	}

	/**
	 * Returns the order's status and payment status after a settlement: the settlement's, or, when it changed no status, as for an answer applied before by another path, the order's status now, read.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the order the settlement names is gone.
	 *
	 * @param SettledPlacement $settled What the settlement came to.
	 * @return array{status: OrderStatus, payment_status: PaymentStatus} The two.
	 */
	public function statusesOf( SettledPlacement $settled ): array {
		$status = $settled->orderStatus ?? $this->orders->statusOf( $settled->orderId )['status'] ?? throw new \LogicException( sprintf( 'Order %d was settled, and is gone.', $settled->orderId ) );

		return array(
			'status'         => $status,
			'payment_status' => $settled->paymentStatus,
		);
	}

	/**
	 * Settles a void the payment path applied: what is left of the hold released, the uses given back and the cart opened again; or nothing, for an order whose approval landed first.
	 *
	 * The payment path cancelled an order still pending payment, and parked one already accepted,
	 * which keeps what it holds for a person.
	 *
	 * @since 0.2.0
	 *
	 * @param Application $applied What the payment path did: a void, applied.
	 * @param VoidReason  $reason  Why the void was asked for, which the hold is released with.
	 * @return SettledPlacement What the settlement came to.
	 */
	private function voided( Application $applied, VoidReason $reason ): SettledPlacement {
		if ( IntentStatus::Authorized !== $applied->intentFrom ) {
			$this->stock->release( self::holdGroup( $applied->orderId, $applied->holdGroup ), $reason->value );
			$this->close( $applied->orderId, false );
		}

		return $this->answered( $applied->orderId, PlacementOutcome::Voided, $applied->orderStatusTo, $applied->paymentTo );
	}

	/**
	 * Settles an order whose payment stands: its units allocated, or the order on hold when they are gone; its uses committed and its cart converted.
	 *
	 * @since 0.1.0
	 *
	 * @param int              $orderId       The order, accepted by the payment path.
	 * @param string           $holdGroup     Its stock hold.
	 * @param OrderStatus|null $orderStatus   Its status after the payment path, or null when that did not change it.
	 * @param PaymentStatus    $paymentStatus Its payment status after the payment path.
	 * @param Actor            $actor         On whose authority.
	 * @return SettledPlacement What the settlement came to.
	 */
	private function accept( int $orderId, string $holdGroup, ?OrderStatus $orderStatus, PaymentStatus $paymentStatus, Actor $actor ): SettledPlacement {
		$outcome = PlacementOutcome::Approved;

		if ( ! $this->allocate( $orderId, $holdGroup ) ) {
			$outcome     = PlacementOutcome::StockUnavailable;
			$orderStatus = $this->orders->transition( $orderId, OrderStatus::OnHold, self::STOCK_UNAVAILABLE, $actor )->to;
		}

		$this->close( $orderId, true );

		return $this->answered( $orderId, $outcome, $orderStatus, $paymentStatus );
	}

	/**
	 * Rewrites the answer the placement's key keeps to what the settlement came to, last in the lock order, and returns it.
	 *
	 * @since 0.1.0
	 *
	 * @param int              $orderId       The order.
	 * @param PlacementOutcome $outcome       What the settlement came to.
	 * @param OrderStatus|null $orderStatus   The order's status after it, or null when it did not change.
	 * @param PaymentStatus    $paymentStatus The order's payment status after it.
	 * @param string|null      $keptAction    Optional. What the shopper must do, sealed, for an outcome that asks them to
	 *                                        act. Default null: the one kept, or none.
	 * @return SettledPlacement What the settlement came to.
	 */
	private function answered( int $orderId, PlacementOutcome $outcome, ?OrderStatus $orderStatus, PaymentStatus $paymentStatus, ?string $keptAction = null ): SettledPlacement {
		$this->keys->settleAnswer( $orderId, $outcome, $orderStatus, $paymentStatus, $keptAction );

		return new SettledPlacement( $outcome, $orderId, $orderStatus, $paymentStatus );
	}

	/**
	 * Allocates the order's units from its hold, all or nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the allocation refuses, but that the units are gone.
	 *
	 * @param int    $orderId   The order.
	 * @param string $holdGroup Its stock hold.
	 * @return bool True when the units are allocated; false when they are gone, and nothing was allocated.
	 */
	private function allocate( int $orderId, string $holdGroup ): bool {
		$lines = array_map(
			static fn( array $line ): Allocation => new Allocation( $line['orderLineId'], $line['variantId'], $line['quantity'] ),
			$this->orders->stockLines( $orderId )
		);

		try {
			$this->stock->allocate( $holdGroup, $orderId, $lines );
		} catch ( CodedException $short ) {
			if ( ! in_array( $short->errorCode(), array( InventoryError::Insufficient, InventoryError::ItemMissing ), true ) ) {
				throw $short;
			}

			return false;
		}

		return true;
	}

	/**
	 * Settles the order's promotion uses and its cart: committed and converted, or given back and opened again.
	 *
	 * @since 0.1.0
	 *
	 * @param int  $orderId  The order.
	 * @param bool $accepted True when the order stands.
	 */
	private function close( int $orderId, bool $accepted ): void {
		if ( $accepted ) {
			$this->usage->commit( $orderId );
		} else {
			$this->usage->release( $orderId );
		}

		$this->carts->settleOrder( $orderId, $accepted );
	}

	/**
	 * Returns the stock hold placement took for the order.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the order has none: it was not placed through checkout.
	 *
	 * @param int         $orderId   The order.
	 * @param string|null $holdGroup Its stock hold, as the payment path read it with the order's lock.
	 * @return string The hold's id.
	 */
	private static function holdGroup( int $orderId, ?string $holdGroup ): string {
		return $holdGroup ?? throw new \LogicException( sprintf( 'Order %d has no stock hold, so it was not placed through checkout and has no placement to settle.', $orderId ) );
	}
}
