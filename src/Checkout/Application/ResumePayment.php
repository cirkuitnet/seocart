<?php
/**
 * ResumePayment: settles the payment of a placement once the shopper is back from acting for the provider
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Domain\SettledPlacement;
use SEOCart\Contracts\Payment\GatewayResult;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Outcome;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Payment\Domain\IntentRef;
use SEOCart\Payment\Domain\IntentStatus;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a broken store to the developer; it is never HTML.

/**
 * Performs `checkout.resume_payment`: for one placement's payment, what the reconciliation run does for a page of them.
 *
 * Owns one fact: how a shopper's client has the store settle a payment the shopper acted on. The
 * client sends the payment's identifier, which the return address carried, with the cart token
 * the placement was made with; the store asks the provider where the payment stands, outside any
 * transaction, and settles its answer through the placement's own settlement, the path every
 * answer takes. The browser never settles anything itself: what it reports is not read, only the
 * provider's answer. A payment another path settled first, a webhook or the reconciliation run, is
 * answered as it stands, outcome `duplicate`, without asking the provider; so is one the provider
 * answers that it settled before. A payment the provider still waits on, for the shopper or for
 * itself, is answered `requires_action` or `processing`, and nothing is settled.
 *
 * A shopper whose time to act ran out before they came back never acted in time: their payment is
 * voided at its gateway first, as the store's, before anything the order holds is released, as the
 * reconciliation run voids it (SettlePlacement::voidEndedAction()); a provider that had approved
 * meanwhile answers with the approval, which is settled as an approval.
 *
 * A payment that does not exist and one of another cart are answered alike, so a stranger learns
 * nothing. The store's schema gate is asked before the first read.
 *
 * @since 0.2.0
 */
final class ResumePayment {

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param TransactionManager $tx         The unit of work, asked whether the store takes writes before the first read.
	 * @param CartService        $carts      The order the request's cart placed.
	 * @param PaymentService     $payments   The payment, and its provider's answer.
	 * @param SettlePlacement    $settlement The settlement every answer goes through.
	 * @param Orders             $orders     The order's statuses, for a payment not settled now.
	 */
	public function __construct(
		private TransactionManager $tx,
		private CartService $carts,
		private PaymentService $payments,
		private SettlePlacement $settlement,
		private Orders $orders
	) {
	}

	/**
	 * Performs `checkout.resume_payment`.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `store.unavailable` while the schema gate is closed, before any read; `cart.not_found`
	 *                        when the request's cart token names no cart, or one that placed nothing;
	 *                        `payment.intent_not_found` when the payment does not exist or is not that cart's
	 *                        placement's; `checkout.gateway_unavailable`, naming the order, when the provider gives
	 *                        no answer or can no longer be used; what the settlement refuses.
	 *
	 * @param array<string, mixed> $input The prepared input: intent_uuid.
	 * @param Actor                $actor Who resumes: the shopper.
	 * @return array{intent_uuid: string, outcome: string, status: string, payment_status: string} The payment's
	 *         identifier, what the resume came to, and the order's status and payment status.
	 */
	public function resume( array $input, Actor $actor ): array {
		// Before the first read: a column the reads name may belong to a migration still outstanding.
		$this->tx->refuseWhileClosed();

		$uuid    = (string) $input['intent_uuid'];
		$orderId = $this->carts->presentedOrder() ?? CodedException::raise( CartError::NotFound );
		$intent  = $this->payments->intentRef( $uuid );

		if ( null === $intent || $intent->orderId !== $orderId ) {
			CodedException::raise( PaymentError::IntentNotFound, array( 'intent_uuid' => $uuid ) );
		}

		if ( ! in_array( $intent->status, IntentStatus::awaitingResult(), true ) ) {
			return $this->answer( $uuid, PlacementOutcome::Duplicate, $this->statusesNow( $orderId ) );
		}

		$voided = $this->voidedIfEnded( $intent );

		if ( null !== $voided ) {
			return $voided;
		}

		$answer = $this->asked( fn(): ?GatewayResult => $this->payments->queryGateway( $intent ), $intent );

		// The provider still waits, for the shopper or for itself: nothing is settled, and the client is told to wait.
		if ( null === $answer || ! in_array( $answer->outcome, Outcome::moneyFacts(), true ) ) {
			return $this->answer( $uuid, self::waiting( $intent, $answer ), $this->statusesNow( $orderId ) );
		}

		$settled = $this->settlement->apply( $answer, $actor );

		return $this->answer( $uuid, $settled->outcome, $this->settlement->statusesOf( $settled ) );
	}

	/**
	 * Voids a payment whose shopper's time to act ran out before they came back, as the reconciliation run does, and settles the void.
	 *
	 * The void is the store's, asked before anything is released (SettlePlacement::voidEndedAction()).
	 * A provider that refuses to cancel changes nothing: the payment still waits, and the run asks
	 * again. A payment another path settled meanwhile, or kept for a person, stands as it is.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.gateway_unavailable`, naming the order; what the settlement refuses.
	 *
	 * @param IntentRef $intent The payment, waiting.
	 * @return array{intent_uuid: string, outcome: string, status: string, payment_status: string}|null The answer;
	 *         null when the shopper's time to act has not run out, and nothing was asked.
	 */
	private function voidedIfEnded( IntentRef $intent ): ?array {
		try {
			$settled = $this->asked( fn(): ?SettledPlacement => $this->settlement->voidEndedAction( $intent ), $intent );
		} catch ( CodedException $refused ) {
			$outcome = match ( $refused->errorCode() ) {
				PaymentError::OperationDeclined                         => PlacementOutcome::RequiresAction,
				PaymentError::NotVoidable, PaymentError::Unreconciled => PlacementOutcome::Duplicate,
				default                                                 => throw $refused,
			};

			return $this->answer( $intent->uuid, $outcome, $this->statusesNow( $intent->orderId ) );
		}

		return null === $settled ? null : $this->answer( $intent->uuid, $settled->outcome, $this->settlement->statusesOf( $settled ) );
	}

	/**
	 * Returns what a payment the provider has not settled waits for: the shopper, or the provider.
	 *
	 * @since 0.2.0
	 *
	 * @param IntentRef          $intent The payment, as read.
	 * @param GatewayResult|null $answer The provider's answer when it gave one: a request to act, or still deciding.
	 * @return PlacementOutcome `requires_action` or `processing`.
	 */
	private static function waiting( IntentRef $intent, ?GatewayResult $answer ): PlacementOutcome {
		$forShopper = null === $answer ? IntentStatus::RequiresAction === $intent->status : Outcome::RequiresAction === $answer->outcome;

		return $forShopper ? PlacementOutcome::RequiresAction : PlacementOutcome::Processing;
	}

	/**
	 * Asks the provider, and refuses as a placement does when it cannot answer or can no longer be used.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.gateway_unavailable`, naming the order; any other refusal of the payment path.
	 *
	 * @template T
	 * @param \Closure  $ask    Asks the provider.
	 * @param IntentRef $intent The payment.
	 * @return mixed What the provider answered.
	 *
	 * @phpstan-param \Closure(): T $ask
	 * @phpstan-return T
	 */
	private function asked( \Closure $ask, IntentRef $intent ): mixed {
		try {
			return $ask();
		} catch ( GatewayUnavailable $unanswered ) {
			$this->refuseUnavailable( $intent, $unanswered );
		} catch ( CodedException $refused ) {
			if ( ! in_array( $refused->errorCode(), array( PaymentError::GatewayUnavailable, PaymentError::OperationUnsupported ), true ) ) {
				throw $refused;
			}

			$this->refuseUnavailable( $intent, $refused );
		}
	}

	/**
	 * Refuses a resume whose provider gave no answer, or can no longer be used, as a placement is refused: the order waits as it is.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always: `checkout.gateway_unavailable`, naming the order, with the cause as the previous exception.
	 *
	 * @param IntentRef  $intent The payment.
	 * @param \Throwable $cause  Why.
	 */
	private function refuseUnavailable( IntentRef $intent, \Throwable $cause ): never {
		throw CodedException::because( CheckoutError::GatewayUnavailable, array(), $cause, array( 'order_uuid' => $this->statusesNow( $intent->orderId )['uuid'] ) );
	}

	/**
	 * Reads the order's identifier and statuses as they stand.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException When the order is gone: a cart's placed order is never deleted while the cart lives.
	 *
	 * @param int $orderId The order.
	 * @return array{uuid: string, status: OrderStatus, payment_status: PaymentStatus, money_reconciled_at: string|null} Its
	 *         identifier and statuses, and its last clearance, which the answer does not name.
	 */
	private function statusesNow( int $orderId ): array {
		return $this->orders->statusOf( $orderId ) ?? throw new \LogicException( sprintf( 'Order %d, which a live cart placed, is gone.', $orderId ) );
	}

	/**
	 * Builds the answer.
	 *
	 * @since 0.2.0
	 *
	 * @param string                                                    $uuid     The payment's identifier.
	 * @param PlacementOutcome                                          $outcome  What the resume came to.
	 * @param array{status: OrderStatus, payment_status: PaymentStatus} $statuses The order's statuses.
	 * @return array{intent_uuid: string, outcome: string, status: string, payment_status: string} The answer, by wire name.
	 */
	private function answer( string $uuid, PlacementOutcome $outcome, array $statuses ): array {
		return array(
			'intent_uuid'    => $uuid,
			'outcome'        => $outcome->value,
			'status'         => $statuses['status']->value,
			'payment_status' => $statuses['payment_status']->value,
		);
	}
}
