<?php
/**
 * PlaceOrder: places an order from the request's cart in two units of work, with the gateway called between them
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Application\Operations\IdempotencyKey;
use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Application\StoreApiError;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Catalog\Application\Query\Sellability;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Checkout\Domain\CheckoutSession;
use SEOCart\Checkout\Domain\FrozenQuotes;
use SEOCart\Checkout\Domain\IdempotencyClaim;
use SEOCart\Checkout\Domain\PlacementOutcome;
use SEOCart\Checkout\Domain\SettledPlacement;
use SEOCart\Contracts\Payment\GatewayUnavailable;
use SEOCart\Contracts\Payment\Mode;
use SEOCart\Contracts\Payment\NextAction;
use SEOCart\Inventory\Application\StockService;
use SEOCart\Inventory\Domain\HoldLine;
use SEOCart\Order\Application\ActorCustomers;
use SEOCart\Order\Application\Orders;
use SEOCart\Order\Domain\OrderStatus;
use SEOCart\Order\Domain\PaymentStatus;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Payment\Application\PaymentError;
use SEOCart\Payment\Application\PaymentService;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Pricing\Application\Calculation;
use SEOCart\Pricing\Domain\Source;
use SEOCart\Promotion\Application\PromotionUsage;
use SEOCart\Promotion\Application\UsageClaim;
use SEOCart\Support\Error\CodedException;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a programming error to the developer; they are never HTML. Coded errors go through CodedException.

/**
 * Performs `checkout.place_order`: places an order from the request's cart, has the gateway authorize its payment, and settles it.
 *
 * Owns one fact: the order in which a placement does its work. Before anything is written, a
 * request whose key an earlier request placed its order with is answered as that one was; then
 * the cart, its checkout and its totals are checked, and the cart is priced from what the store
 * holds, never from the client: the client's grand total must be the one priced. The payment
 * method chosen must still be able to take that total, by its gateway's capability matrix and the
 * gateway's own rules, before anything is written; the payment is created in the mode the gateway
 * takes new payments in now. Then two units of
 * work, each at READ COMMITTED and run again whole on a deadlock, with the gateway called between
 * them, outside any transaction:
 *
 * 1. the first, in the lock order every placement keeps (the cart, the key, the stock items in
 *    ascending order, the order's number and rows, the promotions in ascending order, the intent):
 *    the cart's claim, which moves it to placing; the key's claim; the sale decision, read as last
 *    committed, so a product being saved now is refused; the stock hold; the order, written from
 *    the totals; the promotions' uses; the payment intent, for the order's total, when anything is
 *    due; the order's binding to the cart; the quotes the totals were priced with, kept on the
 *    session; and the key's completion, with the answer a retry gets. Any refusal rolls all of it
 *    back.
 * 2. the second is SettlePlacement's: the gateway's result applied through the one money path,
 *    and the stock, the promotions and the cart settled by what it came to. An order with nothing
 *    due has no intent and the gateway is not asked: the second unit settles it as paid.
 *
 * The first unit's answer is kept with the key, so a client that lost its answer and sends the same
 * request again gets it back, its access key included; the key is kept sealed with the cart's
 * token and the request's idempotency key, which the store never keeps (KeptAnswer). The kept
 * answer says the order is `pending` until the second unit writes what it came to. When the
 * gateway cannot be reached the order waits as placed, and the reconciliation job asks the gateway
 * later; a decline releases everything the order held, opens the cart again, and is refused
 * with the order's uuid; so does a gateway that, once the order is placed, can no longer be used or
 * no longer declares the payment, before anything is sent to it. A cart is locked after DECLINES
 * declines within an hour, which is how card testing is slowed down; the cap is the cart's, so
 * other shoppers behind the same address are never locked out by one, and it is counted only for a
 * cart the request's token was found to name.
 *
 * @since 0.1.0
 * @since 0.2.0 Checks the payment method can take the payment, and records the payment's mode.
 */
final class PlaceOrder {

	/**
	 * How long a key is kept after it is claimed, in seconds: thirty days.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const KEY_TTL_SECONDS = 2592000;

	/**
	 * How long the stock of a placed order is held for its payment, in seconds.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const HOLD_TTL_SECONDS = 900;

	/**
	 * What the cap on declined payments counts, per cart.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const DECLINES_BUCKET = 'checkout.declines';

	/**
	 * The most declined payments a cart may have in a window before its placements are refused.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DECLINES = 3;

	/**
	 * The window the declines are counted in: an hour.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const DECLINES_WINDOW = 3600;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param CartService        $carts       The cart: its read, its claim, its binding and its pricing.
	 * @param CartTokens         $tokens      The cart token the request carries, which scopes its keys.
	 * @param CheckoutSessions   $sessions    The checkout sessions.
	 * @param IdempotencyKeys    $keys        The idempotency keys.
	 * @param Sellability        $sellability The sale decision.
	 * @param StockService       $stock       The stock hold.
	 * @param Orders             $orders      The order, and its status for an answer that names it.
	 * @param ActorCustomers     $customers   The customer an order belongs to.
	 * @param PromotionUsage     $usage       The promotions' uses.
	 * @param PaymentService     $payments    The intent and the gateway's authorization.
	 * @param SettlePlacement    $settlement  The second unit of work.
	 * @param TransactionManager $tx          The unit of work.
	 * @param RateLimiter        $limiter     Counts each cart's declined payments.
	 * @param ClientIdentities   $identities  Names the cart a count is kept for.
	 * @param Gateways           $gateways    The store's payment gateways, which say whether the payment method can take the payment.
	 */
	public function __construct(
		private CartService $carts,
		private CartTokens $tokens,
		private CheckoutSessions $sessions,
		private IdempotencyKeys $keys,
		private Sellability $sellability,
		private StockService $stock,
		private Orders $orders,
		private ActorCustomers $customers,
		private PromotionUsage $usage,
		private PaymentService $payments,
		private SettlePlacement $settlement,
		private TransactionManager $tx,
		private RateLimiter $limiter,
		private ClientIdentities $identities,
		private Gateways $gateways
	) {
	}

	/**
	 * Returns the cap on a client's declined payments.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit DECLINES declines per cart per DECLINES_WINDOW.
	 */
	public static function declineLimit(): RateLimit {
		return new RateLimit( self::DECLINES_BUCKET, self::DECLINES, self::DECLINES_WINDOW );
	}

	/**
	 * Performs `checkout.place_order`.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.idempotency_key_missing`, also for a key longer than
	 *                        IdempotencyKey::MAX_LENGTH bytes; a replay's refusals,
	 *                        `checkout.idempotency_key_reused` and `checkout.placement_in_progress`;
	 *                        `cart.not_found`; `store_api.rate_limited` after too many declines of the cart;
	 *                        `cart.not_open` (naming the order being placed) and
	 *                        `cart.version_stale`; `checkout.cart_empty`,
	 *                        `checkout.session_incomplete`, `checkout.line_unsellable`,
	 *                        `checkout.totals_changed` and `checkout.payment_method_unavailable`,
	 *                        before anything is written; `stock.insufficient` and
	 *                        `promotion.limit_reached`, which roll the placement back;
	 *                        `checkout.gateway_unavailable`, `checkout.payment_method_unavailable`
	 *                        once the order is written and released, and `checkout.payment_declined`,
	 *                        naming the order; the codes the calculation raises.
	 *
	 * @param array<string, mixed> $input The prepared input: idempotency_key (from the request's
	 *                                    header), cart_version, grand_total_minor, currency and
	 *                                    payment_data.
	 * @param Actor                $actor Who places the order.
	 * @return array<string, mixed> The placement, by wire name: the order's uuid, number and access
	 *                              key, the cart's version, the outcome, the order's status and
	 *                              payment status, and what the shopper must do while they must act.
	 */
	public function place( array $input, Actor $actor ): array {
		$key = (string) ( $input['idempotency_key'] ?? '' );

		if ( '' === $key ) {
			CodedException::raise( CheckoutError::IdempotencyKeyMissing );
		}

		$token = $this->tokens->presented() ?? CodedException::raise( CartError::NotFound );

		// The header reaches here unchecked by the field's schema, which counts characters: a key the
		// hash does not take, longer than its bytes allow, is refused as a missing one is.
		if ( ! IdempotencyKey::accepts( $key ) ) {
			CodedException::raise( CheckoutError::IdempotencyKeyMissing );
		}

		$keyHash     = IdempotencyClaim::keyHash( $token->hash(), $key );
		$fingerprint = self::fingerprint( $input );
		$replay      = $this->keys->replay( IdempotencyClaim::PLACE_ORDER_SCOPE, $keyHash, $fingerprint );

		if ( null !== $replay ) {
			return KeptAnswer::open( (string) $replay->responseJson, $token, $key );
		}

		$cart = $this->openCart( (int) $input['cart_version'] );

		$this->refuseAfterDeclines( $token );

		$session     = $this->sessions->find( $cart->id );
		$calculation = $this->priced( $cart, $session, $input );
		$mode        = $this->paymentMode( $session, $calculation );

		try {
			$placed = $this->tx->transaction(
				fn(): array => $this->placeInside( $cart, $session, $calculation, $mode, $keyHash, $fingerprint, $token, $key, $actor ),
				RetryPolicy::deadlocks(),
				Isolation::ReadCommitted
			);
		} catch ( CodedException $refused ) {
			return $this->answerRefusedClaim( $refused, $keyHash, $fingerprint, $token, $key );
		}

		return $this->paid( $placed, (array) ( $input['payment_data'] ?? array() ), $token, $key, $actor );
	}

	/**
	 * Runs the first unit of work: the cart's claim, the key, the sale, the hold, the order, the uses, the intent, the binding, the quotes and the key's completion.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\LogicException What any step refuses; the whole unit rolls back. A \LogicException
	 *         when the key turns out placed at the version just claimed, which the cart's claim rules out.
	 *
	 * @param Cart            $cart          The cart, as read before the transaction.
	 * @param CheckoutSession $session       Its checkout.
	 * @param Calculation     $calculation   Its totals, priced before the transaction.
	 * @param Mode|null       $mode          The mode the payment is created in; null when nothing is due.
	 * @param string          $keyHash       The key's hash.
	 * @param string          $fingerprint   The request's fingerprint.
	 * @param CartToken       $token         The cart's token, which the request presented.
	 * @param string          $key           The idempotency key the request sent.
	 * @param Actor           $actor         Who places the order.
	 * @return array{record: array<string, mixed>, order_id: int, intent_uuid: string|null} The answer, the order, and the intent
	 *                                                                                      to authorize: null when nothing is due.
	 */
	private function placeInside( Cart $cart, CheckoutSession $session, Calculation $calculation, ?Mode $mode, string $keyHash, string $fingerprint, CartToken $token, #[\SensitiveParameter] string $key, Actor $actor ): array {
		$version = $this->carts->claimForPlacement( $cart->id, $cart->version, $actor );
		$claim   = $this->keys->claim( IdempotencyClaim::PLACE_ORDER_SCOPE, $keyHash, $fingerprint, self::KEY_TTL_SECONDS );

		if ( ! $claim->owned ) {
			// The cart's claim serialises the requests of one key: one that placed its order moved the cart on first.
			throw new \LogicException( 'An idempotency key of this cart was placed with at the version just claimed, which the cart\'s claim rules out.' );
		}

		$customer  = $this->customers->customerOf( $actor );
		$sold      = $this->saleOf( $cart );
		$hold      = $this->stock->hold( array_map( static fn( CartLine $line ): HoldLine => new HoldLine( $line->variantId, $line->quantity ), $cart->lines ), self::HOLD_TTL_SECONDS, $cart->id );
		$order     = $this->orders->insert( OrderDocument::of( $calculation->totals, $cart->locale, $session->details, $sold, $hold->holdGroup, $customer ), $actor );
		$summary   = $calculation->totals->summary;
		$tokenHash = $token->hash();

		$this->usage->claim(
			array_map(
				static function ( array $promotion ) use ( $calculation, $order, $customer, $tokenHash ): UsageClaim {
					$discount = $calculation->totals->discountOf( Source::promotion( $promotion['uuid'] ) );

					return new UsageClaim( $promotion['id'], $order->id, $discount->amount, $discount->base, $customer, $tokenHash );
				},
				$calculation->appliedPromotions()
			)
		);

		// With nothing due there is no mode and nothing to authorize: no intent, and the second unit settles the order as paid.
		$intent = null === $mode ? null : $this->payments->createIntent( $order->id, (string) $session->details->paymentMethodKey, $mode, $summary->grand, $summary->baseGrand, $order->conversionContextId );

		$this->carts->bindOrder( $cart->id, $order->id );
		$this->sessions->storeQuotes( $cart->id, $version, new FrozenQuotes( $calculation->selectedShippingRate(), $calculation->taxQuoteFingerprint() ) );

		$record = array(
			'order_uuid'     => $order->uuid,
			'order_number'   => $order->orderNumber,
			'order_key'      => $order->accessKey,
			'cart_version'   => $version,
			'outcome'        => PlacementOutcome::Pending->value,
			'status'         => OrderStatus::PendingPayment->value,
			'payment_status' => PaymentStatus::Unpaid->value,
			'next_action'    => null,
		);

		$this->keys->complete( $claim->id, $order->id, KeptAnswer::seal( $record, $token, $key ) );

		return array(
			'record'      => $record,
			'order_id'    => $order->id,
			'intent_uuid' => $intent?->uuid,
		);
	}

	/**
	 * Has the gateway authorize the placed order's payment, outside any transaction, settles the result, and answers.
	 *
	 * An order with nothing due has no intent: the gateway is not asked, and the second unit
	 * settles it as paid. The gateway is given the address it sends a shopper it asks to act back
	 * to (ReturnUrls), which carries the payment's identifier alone; what it asks of the shopper is
	 * answered, and kept sealed with the answer for a retry while the shopper must act.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Gives the gateway the return address, and answers what the shopper must do.
	 *
	 * @throws CodedException `checkout.gateway_unavailable` when the gateway gives no answer, the
	 *                        order waiting as placed; `checkout.payment_method_unavailable` when the
	 *                        gateway can no longer be used for the payment, or no longer declares it,
	 *                        and nothing was sent, the order released; and `checkout.payment_declined`
	 *                        when it declines; each names the order.
	 *
	 * @param array{record: array<string, mixed>, order_id: int, intent_uuid: string|null} $placed      What the first unit of work placed.
	 * @param array<string, mixed>                                                         $paymentData What the client sent for the gateway.
	 * @param CartToken                                                                    $token       The token of the cart placed, which a decline is counted for.
	 * @param string                                                                       $key         The idempotency key the request sent, which seals what the shopper must do.
	 * @param Actor                                                                        $actor       Who places the order.
	 * @return array<string, mixed> The answer.
	 */
	private function paid( array $placed, array $paymentData, CartToken $token, #[\SensitiveParameter] string $key, Actor $actor ): array {
		$record = $placed['record'];
		$order  = array( 'order_uuid' => $record['order_uuid'] );

		if ( null === $placed['intent_uuid'] ) {
			return $this->settledAnswer( $record, $this->settlement->settleNothingDue( $placed['order_id'], $actor ) );
		}

		try {
			$result = $this->payments->authorize( $placed['intent_uuid'], $paymentData, (string) $record['order_uuid'], (string) $record['order_number'], ReturnUrls::for( $placed['intent_uuid'] ) );
		} catch ( GatewayUnavailable $unavailable ) {
			// The request may have reached the provider: the order waits as placed, and reconciliation asks.
			throw CodedException::because( CheckoutError::GatewayUnavailable, array(), $unavailable, $order );
		} catch ( CodedException $refused ) {
			if ( ! in_array( $refused->errorCode(), array( PaymentError::GatewayUnavailable, PaymentError::OperationUnsupported ), true ) ) {
				throw $refused;
			}

			$this->refuseUnsent( $placed['intent_uuid'], $refused, $order, $actor );
		}

		$sealed  = null === $result->nextAction ? null : KeptAnswer::sealAction( $result->nextAction, (string) $record['order_uuid'], $token, $key );
		$settled = $this->settlement->apply( $result, $actor, null, $sealed );

		if ( PlacementOutcome::Declined === $settled->outcome ) {
			$limit = self::declineLimit();

			$this->limiter->hit( $limit->bucket(), $this->identities->ofCart( $token ), $limit->windowSeconds() );

			CodedException::raise( CheckoutError::PaymentDeclined, array(), $order );
		}

		return $this->settledAnswer( $record, $settled, $result->nextAction );
	}

	/**
	 * Releases a placement whose authorization the store refused to send, and refuses the placement as one the payment method cannot take.
	 *
	 * The gateway was found able to take the payment before the order was placed, but by the time
	 * it was to be asked, it could not be used, or its matrix no longer declared the payment: the
	 * refusal came before any request was built, so the provider never saw the intent, and nothing
	 * will ever answer for it. The authorization is declined here, without a provider object, and
	 * settled as a decline: the order fails, and its hold, its promotion uses and its cart are given
	 * back. It is the store's refusal, not the card's, so the cart's count of declines is not added to.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException Always: `checkout.payment_method_unavailable`, naming the payment method and the order,
	 *                        with the refusal as the previous exception.
	 *
	 * @param string                $intentUuid The intent.
	 * @param CodedException        $refused    Why it was not sent.
	 * @param array<string, string> $order      The order's details: its uuid.
	 * @param Actor                 $actor      Who places the order.
	 */
	private function refuseUnsent( string $intentUuid, CodedException $refused, array $order, Actor $actor ): never {
		$unsent = $this->payments->unsent( $intentUuid );

		$this->settlement->apply( $unsent, $actor );

		throw CodedException::because( CheckoutError::PaymentMethodUnavailable, array( 'payment_method_key' => $unsent->provider ), $refused, $order );
	}

	/**
	 * Reads the request's live cart, refusing one that cannot be placed now.
	 *
	 * The checks are made again, where they decide, by the cart's claim in the first unit of work;
	 * here they only answer at once.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.not_found`; `cart.not_open`, naming the order being placed;
	 *                        `cart.version_stale`, with the cart's version and totals now;
	 *                        `checkout.cart_empty`.
	 *
	 * @param int $expectedVersion The version the client based the placement on.
	 * @return Cart The cart, with its lines.
	 */
	private function openCart( int $expectedVersion ): Cart {
		$cart = $this->carts->current() ?? CodedException::raise( CartError::NotFound );

		if ( CartStatus::Open !== $cart->status ) {
			$this->refuseNotOpen( $cart );
		}

		if ( $expectedVersion !== $cart->version ) {
			CodedException::raise( CartError::VersionStale, array( 'current_version' => $cart->version ), array( 'totals' => $this->carts->calculation( $cart )->totals->toArray() ) );
		}

		if ( array() === $cart->lines ) {
			CodedException::raise( CheckoutError::CartEmpty );
		}

		return $cart;
	}

	/**
	 * Prices the cart as the store holds it, and refuses a placement it cannot make at the totals the client agreed to.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.session_incomplete` with the fields missing;
	 *                        `checkout.line_unsellable` for a line with no price in the cart's
	 *                        currency; `checkout.totals_changed` with the cart's version and totals.
	 *
	 * @param Cart                 $cart    The cart.
	 * @param CheckoutSession|null $session Its checkout, or null when it has none.
	 * @param array<string, mixed> $input   The request's grand total and currency.
	 * @return Calculation The totals the order is placed with.
	 *
	 * @phpstan-assert CheckoutSession $session
	 */
	private function priced( Cart $cart, ?CheckoutSession $session, array $input ): Calculation {
		$missing = null === $session ? array( 'billing_address', 'shipping_address', 'payment_method_key' ) : $session->details->missingForPlacement();

		if ( array() !== $missing || null === $session ) {
			CodedException::raise( CheckoutError::SessionIncomplete, array(), array( 'missing' => $missing ) );
		}

		// Priced with the session just read: the cart's claim, at the version read with it, refuses a session changed since.
		$calculation = $this->carts->calculation( $cart, CheckoutSession::deliveryOf( $session ) );

		foreach ( $calculation->unpricedLines as $unpriced ) {
			CodedException::raise(
				CheckoutError::LineUnsellable,
				array(
					'variant_id' => $unpriced->variantId,
					'reason'     => $unpriced->reason,
				)
			);
		}

		$grand = $calculation->totals->summary->grand;

		if ( $grand->minorUnits() !== (int) $input['grand_total_minor'] || $grand->currency()->code() !== strtoupper( (string) $input['currency'] ) ) {
			CodedException::raise(
				CheckoutError::TotalsChanged,
				array(),
				array(
					'version' => $cart->version,
					'totals'  => $calculation->totals->toArray(),
				)
			);
		}

		return $calculation;
	}

	/**
	 * Returns the mode the order's payment is created in, once the payment method is found able to take it now; nothing is written before.
	 *
	 * The method was checked when it was chosen, without the total; here the gateway must still be
	 * registered and set up for its mode, its capability matrix must allow the order's currency for
	 * its account's country, and the gateway itself must agree to the total. An order with nothing
	 * due asks no gateway.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.payment_method_unavailable`, naming the method, with the
	 *                        detail `reason: disabled` when an operator switched its gateway off.
	 *
	 * @param CheckoutSession $session     The checkout, with the payment method and the billing address.
	 * @param Calculation     $calculation The totals the order is placed with.
	 * @return Mode|null The mode; null when nothing is due.
	 */
	private function paymentMode( CheckoutSession $session, Calculation $calculation ): ?Mode {
		$grand = $calculation->totals->summary->grand;

		if ( $grand->isZero() ) {
			return null;
		}

		$method = (string) $session->details->paymentMethodKey;

		return $this->gateways->availableMode( $method, $grand, $session->details->billingAddress?->country(), OrderDocument::CHANNEL->value )
			?? CodedException::raise( CheckoutError::PaymentMethodUnavailable, array( 'payment_method_key' => $method ), $this->gateways->isEnabled( $method ) ? array() : array( 'reason' => 'disabled' ) );
	}

	/**
	 * Decides the sale of every line from the catalog as last committed, and returns the facts each was judged on.
	 *
	 * Runs in the first unit of work, at READ COMMITTED, so the read sees a product whose save has
	 * begun since the transaction did, and refuses it; it locks nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.line_unsellable` for the first line whose verdict is not sellable.
	 *
	 * @param Cart $cart The cart.
	 * @return array<int, \SEOCart\Catalog\Domain\SellabilityFacts> The facts, by variant id.
	 */
	private function saleOf( Cart $cart ): array {
		if ( array() === $cart->lines ) {
			CodedException::raise( CheckoutError::CartEmpty );
		}

		$sold = array();

		foreach ( $this->sellability->forSale( array_map( static fn( CartLine $line ): int => $line->variantId, $cart->lines ), false, $cart->locale ) as $variantId => $sale ) {
			if ( ! $sale['verdict']->sells() || null === $sale['facts'] ) {
				CodedException::raise(
					CheckoutError::LineUnsellable,
					array(
						'variant_id' => $variantId,
						'reason'     => $sale['verdict']->value,
					)
				);
			}

			$sold[ $variantId ] = $sale['facts'];
		}

		return $sold;
	}

	/**
	 * Answers a placement whose first unit of work was refused: as the earlier request with the key, when the refusal was the cart's because that request placed its order; otherwise with the refusal.
	 *
	 * A request racing another with the same key waits for it on the cart, and finds the cart
	 * moved on once it commits; the key then tells what the other request answered.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The refusal, or the key's own; `cart.not_open` naming the order the cart is placing.
	 *
	 * @param CodedException $refused     The refusal.
	 * @param string         $keyHash     The key's hash.
	 * @param string         $fingerprint The request's fingerprint.
	 * @param CartToken      $token       The cart token the request presented.
	 * @param string         $key         The idempotency key the request sent.
	 * @return array<string, mixed> The earlier request's answer.
	 */
	private function answerRefusedClaim( CodedException $refused, string $keyHash, string $fingerprint, CartToken $token, #[\SensitiveParameter] string $key ): array {
		if ( ! in_array( $refused->errorCode(), array( CartError::NotOpen, CartError::VersionStale, CartError::NotFound ), true ) ) {
			throw $refused;
		}

		$replay = $this->keys->replay( IdempotencyClaim::PLACE_ORDER_SCOPE, $keyHash, $fingerprint );

		if ( null !== $replay ) {
			return KeptAnswer::open( (string) $replay->responseJson, $token, $key );
		}

		$cart = CartError::NotOpen === $refused->errorCode() ? $this->carts->current() : null;

		if ( null !== $cart && CartStatus::Open !== $cart->status ) {
			$this->refuseNotOpen( $cart );
		}

		throw $refused;
	}

	/**
	 * Refuses a write to a cart that is placing or has placed an order, naming the order and its status.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: `cart.not_open`.
	 *
	 * @param Cart $cart The cart.
	 * @return never
	 */
	private function refuseNotOpen( Cart $cart ): never {
		NotOpenCart::refuse( $cart, $this->orders );
	}

	/**
	 * Refuses a cart that had too many payments declined lately, before anything is written.
	 *
	 * Called once the request's token was found to name the cart, so a token that names none is
	 * never counted. The count is read, never added to: only a decline adds to it.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `store_api.rate_limited`.
	 *
	 * @param CartToken $token The token of the cart the request names.
	 */
	private function refuseAfterDeclines( CartToken $token ): void {
		$limit = self::declineLimit();

		if ( $this->limiter->peek( $limit->bucket(), $this->identities->ofCart( $token ), $limit->windowSeconds() ) >= $limit->limit() ) {
			CodedException::raise( StoreApiError::RateLimited );
		}
	}

	/**
	 * Returns the fingerprint of a placement request: what a retry of it must send again.
	 *
	 * The key is the cart's already, so the request is its cart's version, the grand total and the
	 * currency the client agreed to, and what it sent for the gateway.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return string The SHA-256 of the request's canonical form, in lower-case hexadecimal.
	 */
	private static function fingerprint( array $input ): string {
		$paymentData = (array) ( $input['payment_data'] ?? array() );

		ksort( $paymentData );

		return IdempotencyKey::fingerprint(
			array(
				'cart_version'      => (int) $input['cart_version'],
				'grand_total_minor' => (int) $input['grand_total_minor'],
				'currency'          => strtoupper( (string) $input['currency'] ),
				'payment_data'      => $paymentData,
			)
		);
	}

	/**
	 * Returns the answer of a settled placement: the kept record, with the outcome and the statuses after the settlement, and what the shopper must do while they must act.
	 *
	 * The order's status is the settlement's when it changed it; otherwise, as for an answer the
	 * gateway already delivered by another path, the order's status now, read
	 * (SettlePlacement::statusesOf()).
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Answers what the shopper must do.
	 *
	 * @param array<string, mixed> $record     The record the key keeps.
	 * @param SettledPlacement     $settled    What the settlement came to.
	 * @param NextAction|null      $nextAction Optional. What the gateway asked the shopper to do. Default null.
	 * @return array<string, mixed> The answer.
	 */
	private function settledAnswer( array $record, SettledPlacement $settled, ?NextAction $nextAction = null ): array {
		$statuses = $this->settlement->statusesOf( $settled );

		return array_merge(
			$record,
			array(
				'outcome'        => $settled->outcome->value,
				'status'         => $statuses['status']->value,
				'payment_status' => $statuses['payment_status']->value,
				'next_action'    => PlacementOutcome::RequiresAction === $settled->outcome && null !== $nextAction ? KeptAnswer::actionOf( $nextAction ) : null,
			)
		);
	}
}
