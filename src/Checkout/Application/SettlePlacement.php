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
use SEOCart\Inventory\Application\InventoryError;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\Allocation;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\Application;
use SEOCart\Payment\Domain\ApplicationKind;
use SEOCart\Payment\Domain\Gateway\GatewayResult;
use SEOCart\Payment\Domain\IntentTransitions;
use SEOCart\Payment\Domain\Operation;
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
 * - an answer applied before: nothing.
 *
 * Last, after the cart, the answer the placement's key keeps is rewritten to what the settlement
 * came to, so a retry of the placement is told where it stands now; an answer applied before
 * leaves it as the first settlement wrote it.
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
	 * Applies the gateway's answer to an order's authorization, and settles the order's stock, promotion uses and cart, in one transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException What the payment path refuses, such as an answer the
	 *         intent's state cannot take: nothing is changed. An \InvalidArgumentException when the answer is not
	 *         about an authorization: a placement settles nothing else.
	 *
	 * @param GatewayResult $result The gateway's answer.
	 * @param Actor         $actor  On whose authority: the shopper, or the system for a job.
	 * @return SettledPlacement What the settlement came to.
	 */
	public function apply( GatewayResult $result, Actor $actor ): SettledPlacement {
		if ( Operation::Authorize !== $result->operation ) {
			throw new \InvalidArgumentException( 'A placement is settled by the answer to its authorization.' );
		}

		return $this->tx->transaction(
			fn(): SettledPlacement => $this->settle( $this->payments->applyGatewayResult( $result, $actor ), $actor ),
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Settles what an applied answer leaves to the order's placement.
	 *
	 * @since 0.1.0
	 *
	 * @param Application $applied What the payment path did.
	 * @param Actor       $actor   On whose authority.
	 * @return SettledPlacement What the settlement came to.
	 */
	private function settle( Application $applied, Actor $actor ): SettledPlacement {
		$orderStatus = $applied->orderStatusTo;

		switch ( $applied->kind ) {
			case ApplicationKind::Applied:
				$outcome = $this->allocate( $applied ) ? PlacementOutcome::Approved : PlacementOutcome::StockUnavailable;

				if ( PlacementOutcome::StockUnavailable === $outcome ) {
					$orderStatus = $this->orders->transition( $applied->orderId, OrderStatus::OnHold, self::STOCK_UNAVAILABLE, $actor )->to;
				}

				$this->close( $applied->orderId, true );
				break;

			case ApplicationKind::Mismatch:
				if ( IntentTransitions::isFinal( $applied->intentFrom ) ) {
					$outcome = PlacementOutcome::LateApproval;
					break;
				}

				$this->allocate( $applied );
				$this->close( $applied->orderId, true );

				$outcome = PlacementOutcome::AmountMismatch;
				break;

			case ApplicationKind::Declined:
				$this->stock->release( self::holdGroupOf( $applied ), self::PAYMENT_DECLINED );
				$this->close( $applied->orderId, false );

				$outcome = PlacementOutcome::Declined;
				break;

			case ApplicationKind::RequiresAction:
				$outcome = PlacementOutcome::RequiresAction;
				break;

			case ApplicationKind::Pending:
				$outcome = PlacementOutcome::Processing;
				break;

			default:
				return new SettledPlacement( PlacementOutcome::Duplicate, $applied->orderId, $orderStatus, $applied->paymentTo );
		}

		$this->keys->settleAnswer( $applied->orderId, $outcome, $orderStatus, $applied->paymentTo );

		return new SettledPlacement( $outcome, $applied->orderId, $orderStatus, $applied->paymentTo );
	}

	/**
	 * Allocates the order's units from its hold, all or nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the allocation refuses, but that the units are gone.
	 *
	 * @param Application $applied What the payment path did.
	 * @return bool True when the units are allocated; false when they are gone, and nothing was allocated.
	 */
	private function allocate( Application $applied ): bool {
		$lines = array_map(
			static fn( array $line ): Allocation => new Allocation( $line['orderLineId'], $line['variantId'], $line['quantity'] ),
			$this->orders->stockLines( $applied->orderId )
		);

		try {
			$this->stock->allocate( self::holdGroupOf( $applied ), $applied->orderId, $lines );
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
	 * @param Application $applied What the payment path did.
	 * @return string The hold's id.
	 */
	private static function holdGroupOf( Application $applied ): string {
		return $applied->holdGroup ?? throw new \LogicException( sprintf( 'Order %d has no stock hold, so it was not placed through checkout and has no placement to settle.', $applied->orderId ) );
	}
}
