<?php
/**
 * CartService: reads a shopper's cart and changes its lines, each change behind the cart's version
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Cart\Application;

use SEOCart\Cart\Domain\Cart;
use SEOCart\Cart\Domain\CartLine;
use SEOCart\Cart\Domain\CartRepository;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Domain\LineIdentity;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\DataRegistry\RetentionCatalog;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\RateLimit;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Pricing\Application\Calculation;
use SEOCart\Pricing\Application\CalculationRequest;
use SEOCart\Pricing\Application\Calculator;
use SEOCart\Pricing\Application\LineRequest;
use SEOCart\Pricing\Application\UnpricedLine;
use SEOCart\Promotion\Application\PromotionError;
use SEOCart\Promotion\Application\PromotionResolver;
use SEOCart\Promotion\Domain\Promotion;
use SEOCart\Support\Address;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;
use SEOCart\Support\Locale;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML. Coded errors go through CodedException::raise().

/**
 * The cart module's application service: every read and change of a cart goes through it.
 *
 * Owns one fact: how a cart changes. The cart a request means is the live cart whose token the
 * request carries; there is no cart row until a write adds the first line, so a read never
 * creates one. A converted cart is finished: to a read, and to a write based on no cart, its
 * token names no cart, so a shopper who has bought starts a new cart.
 *
 * Every change of an existing cart is one transaction that begins with the compare-and-swap on
 * the version the client based it on. One row changed: the cart was open, live and at that
 * version, it is now at the next one, its life is extended by the writer's lifetime
 * (lifetimeSeconds()), and the write goes on in the same transaction. No row changed: one locking
 * read classifies the refusal as `cart.not_found`, `cart.not_open` or `cart.version_stale`, and
 * nothing is changed. So a write replayed with the version it was first sent with is refused,
 * which is what makes a batch of lines safe to send twice. The lines the answer carries are read
 * in the same transaction, after the change, so the version and the lines of an answer always
 * belong together.
 *
 * A token is issued only by a write: the one that creates its cart, and every one that extends a
 * cart's life afterwards, each time with the cart's new lifetime, so the client keeps the token
 * exactly as long as the cart lives.
 *
 * Every answer carries the cart's totals, which the calculation works out from its lines and its
 * promotion codes outside any transaction; the cart stores selections, never prices. A write that
 * starts a cart prices its lines before it creates anything, so a calculation that fails leaves no
 * cart behind whose token the client never received. Any other answer is priced once its
 * transaction has ended. A write refused as stale carries the totals of the cart as it is now,
 * worked out after the rollback.
 *
 * A promotion code joins a cart only once its promotion applies to the cart, which the promotion
 * module decides before any transaction; the code's later fate is the calculation's, which leaves
 * out, and traces, a code that no longer applies. A cart holds at most Cart::MAX_CODES codes.
 * Trying codes is how a stranger would look for valid ones, so every code tried is counted,
 * against its cart and against its client, before it is looked at, and a refused code always gets
 * the same answer: past their caps every code is refused for the hour, valid or not.
 *
 * Order placement claims a cart, binds its order to it and settles it through the three methods
 * below that run only inside the placement's own transaction.
 *
 * @since 0.1.0
 */
final class CartService {

	/**
	 * What the cap on new carts counts, per client and day.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CREATION_BUCKET = 'cart.create';

	/**
	 * The most carts one client may start in a window.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CREATION_LIMIT = 50;

	/**
	 * The window the cap on new carts counts in: a day.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const CREATION_WINDOW = 86400;

	/**
	 * The statements.
	 *
	 * @since 0.1.0
	 *
	 * @var CartRepository
	 */
	private CartRepository $carts;

	/**
	 * The unit of work.
	 *
	 * @since 0.1.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Reads the token the request carries, and hands a cart's token to the client.
	 *
	 * @since 0.1.0
	 *
	 * @var CartTokens
	 */
	private CartTokens $tokens;

	/**
	 * Counts the carts each client starts, and the promotion codes tried.
	 *
	 * @since 0.1.0
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limiter;

	/**
	 * Names the client.
	 *
	 * @since 0.1.0
	 *
	 * @var ClientIdentities
	 */
	private ClientIdentities $identities;

	/**
	 * Returns where a cart's order ships and the shipping method chosen, as the cart's checkout has them.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(int): array{destination: Address|null, shipping_method_key: string|null}
	 */
	private \Closure $delivery;

	/**
	 * Returns the currency a new cart is in: the store's base currency.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Currency
	 */
	private \Closure $currency;

	/**
	 * Returns the locale a new cart is started in.
	 *
	 * @since 0.1.0
	 *
	 * @var \Closure(): Locale
	 */
	private \Closure $locale;

	/**
	 * Works out the totals of a cart's lines.
	 *
	 * @since 0.1.0
	 *
	 * @var Calculator
	 */
	private Calculator $calculator;

	/**
	 * Decides whether a promotion code applies to a cart.
	 *
	 * @since 0.1.0
	 *
	 * @var PromotionResolver
	 */
	private PromotionResolver $promotions;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param CartRepository     $carts      The statements.
	 * @param TransactionManager $tx         The unit of work.
	 * @param CartTokens         $tokens     Reads and issues cart tokens.
	 * @param RateLimiter        $limiter    Counts the carts each client starts, and the codes tried.
	 * @param ClientIdentities   $identities Names the client, and a cart found by its token.
	 * @param \Closure           $delivery   Returns, for a cart's id, where its order ships and the
	 *                                       shipping method chosen, as its checkout has them: each
	 *                                       null until the checkout knows it.
	 * @param \Closure           $currency   Returns the currency a new cart is in.
	 * @param \Closure           $locale     Returns the locale a new cart is started in.
	 * @param Calculator         $calculator Works out the totals of a cart's lines.
	 * @param PromotionResolver  $promotions Decides whether a promotion code applies to a cart.
	 *
	 * @phpstan-param \Closure(int): array{destination: Address|null, shipping_method_key: string|null} $delivery
	 * @phpstan-param \Closure(): Currency $currency
	 * @phpstan-param \Closure(): Locale   $locale
	 */
	public function __construct( CartRepository $carts, TransactionManager $tx, CartTokens $tokens, RateLimiter $limiter, ClientIdentities $identities, \Closure $delivery, \Closure $currency, \Closure $locale, Calculator $calculator, PromotionResolver $promotions ) {
		$this->carts      = $carts;
		$this->tx         = $tx;
		$this->tokens     = $tokens;
		$this->limiter    = $limiter;
		$this->identities = $identities;
		$this->delivery   = $delivery;
		$this->currency   = $currency;
		$this->locale     = $locale;
		$this->calculator = $calculator;
		$this->promotions = $promotions;
	}

	/**
	 * Returns the cap on the carts one client may start.
	 *
	 * @since 0.1.0
	 *
	 * @return RateLimit CREATION_LIMIT carts per CREATION_WINDOW.
	 */
	public static function creationLimit(): RateLimit {
		return new RateLimit( self::CREATION_BUCKET, self::CREATION_LIMIT, self::CREATION_WINDOW );
	}

	/**
	 * Returns how long a cart lives after a write: its writer's kind's period of the carts retention policy.
	 *
	 * A visitor who is not logged in writes a guest's cart, anyone else a logged-in customer's. The
	 * write that last extended a cart decides how long it lives, and so how long its token is kept.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor Who writes.
	 * @return int Seconds.
	 */
	public static function lifetimeSeconds( Actor $actor ): int {
		$kind   = 0 === $actor->userId() ? 'guest' : 'logged_in';
		$period = new \DateInterval( ( new RetentionCatalog() )->defaults( Cart::RETENTION )[ $kind ] );

		return ( new \DateTimeImmutable( '@0' ) )->add( $period )->getTimestamp();
	}

	/**
	 * Returns the cart the request's token names, while the shopper can still use it.
	 *
	 * @since 0.1.0
	 *
	 * @return Cart|null The live cart, open or placing; or null when the request carries no token,
	 *                   or its cart does not exist, has expired or has been converted.
	 */
	public function current(): ?Cart {
		$token = $this->tokens->presented();
		$cart  = null === $token ? null : $this->carts->findByTokenHash( $token->hash() );

		return null === $cart || CartStatus::Converted === $cart->status ? null : $cart;
	}

	/**
	 * Adds lines to the request's cart, or starts a cart with them.
	 *
	 * The lines are combined by identity first. With an expected version of 0 and no live cart, or
	 * a converted one, the lines start a new cart: they are priced, the cart is counted against the
	 * client's cap, inserted at version 1 with its lines in one transaction, and its token is
	 * issued with the response. Otherwise the lines are added to the cart behind the
	 * compare-and-swap, in one statement whatever their number: a line the cart has already gains
	 * the units, up to CartLine::MAX_QUANTITY. Either way the cart then lives the writer's lifetime.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `cart.not_found` when an expected version
	 *         above 0 names no live cart; `cart.version_stale`, `cart.not_open` or `cart.not_found`
	 *         when the compare-and-swap refuses; `cart.too_many_lines` when the cart would hold more
	 *         than Cart::MAX_LINES lines; `cart.creation_limited` when the client may start no more
	 *         carts today; the codes the calculation of a new cart's lines raises; a database error
	 *         the transaction could not retry. An \InvalidArgumentException when there is no line or
	 *         the expected version is negative.
	 *
	 * @param CartLine[] $lines           The lines.
	 * @param int        $expectedVersion The version the client read: 0 when it has no cart.
	 * @param Actor      $actor           Who adds them: the client the cap on new carts counts, and
	 *                                    whose lifetime the cart then lives.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	public function add( array $lines, int $expectedVersion, Actor $actor ): Cart {
		return $this->addPricingNewCart( $lines, $expectedVersion, $actor )['cart'];
	}

	/**
	 * Sets the quantity of one of the request's cart's lines; 0 removes the line.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `cart.not_found` when the request names no
	 *         live cart; `cart.version_stale`, `cart.not_open` or `cart.not_found` when the
	 *         compare-and-swap refuses; `cart.line_not_found` when the cart has no such line; a
	 *         database error the transaction could not retry. An \InvalidArgumentException when the
	 *         quantity is not 0 to CartLine::MAX_QUANTITY.
	 *
	 * @param LineIdentity $identity        The line.
	 * @param int          $quantity        The units, or 0 to remove the line.
	 * @param int          $expectedVersion The version the client read.
	 * @param Actor        $actor           Who changes it: whose lifetime the cart then lives.
	 * @return Cart The cart after the change.
	 */
	public function changeQuantity( LineIdentity $identity, int $quantity, int $expectedVersion, Actor $actor ): Cart {
		if ( $quantity < 0 || $quantity > CartLine::MAX_QUANTITY ) {
			throw new \InvalidArgumentException( sprintf( 'A line holds 0 to %d units; %d was given.', CartLine::MAX_QUANTITY, $quantity ) );
		}

		$presented = $this->presentedRow() ?? CodedException::raise( CartError::NotFound );

		return $this->change(
			$presented,
			$expectedVersion,
			$actor,
			function ( int $cartId ) use ( $identity, $quantity ): void {
				$found = 0 === $quantity
					? $this->carts->removeLine( $cartId, $identity )
					: $this->carts->setQuantity( $cartId, $identity, $quantity );

				if ( ! $found ) {
					CodedException::raise( CartError::LineNotFound, array( 'line_identity' => $identity->value() ) );
				}
			}
		);
	}

	/**
	 * Applies a promotion code to the request's cart, if its promotion applies to the cart now.
	 *
	 * The cart must be one the request's token names; a token that names none is refused before
	 * anything is counted, so a made-up token never starts a counter. A cart that holds
	 * Cart::MAX_CODES codes takes no other: the code is refused with `cart.too_many_codes` before it
	 * is counted or looked at, which tells nothing about it; a code the cart holds already is not
	 * another. Every other code is counted against the cart and the client before it is looked at
	 * (countCodeTried()); past either cap it is refused with `store_api.rate_limited`. Then a code
	 * not written as a code is (Promotion::CODE_PATTERN), or whose promotion does not apply to the
	 * cart now, which the promotion module decides outside any transaction, is refused with
	 * `promotion.code_invalid`, whatever the reason. A code that applies joins the end of the cart's
	 * list in the compare-and-swap; a code the list holds already leaves it as it is.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.not_found` when the request names no live cart;
	 *                        `cart.too_many_codes` when the cart holds as many codes as it may;
	 *                        `store_api.rate_limited` past a cap; `promotion.code_invalid` when the
	 *                        code does not apply; `cart.version_stale`, `cart.not_open` or
	 *                        `cart.not_found` when the compare-and-swap refuses; a database error the
	 *                        transaction could not retry.
	 *
	 * @param string $code            The code, trimmed and upper-cased.
	 * @param int    $expectedVersion The version the client read.
	 * @param Actor  $actor           Who applies it: the client the codes tried are counted for, and
	 *                                whose lifetime the cart then lives.
	 * @return Cart The cart after the change.
	 */
	public function applyPromotionCode( string $code, int $expectedVersion, Actor $actor ): Cart {
		$presented = $this->presentedRow() ?? CodedException::raise( CartError::NotFound );
		$codes     = $presented['cart']->promotionCodes;
		$held      = in_array( $code, $codes, true );

		if ( ! $held && count( $codes ) >= Cart::MAX_CODES ) {
			CodedException::raise( CartError::TooManyCodes, array( 'max_codes' => Cart::MAX_CODES ) );
		}

		$this->countCodeTried( $presented['token'], $actor );

		if ( ! Promotion::isCode( $code ) ) {
			CodedException::raise( PromotionError::CodeInvalid );
		}

		$this->promotions->require( $code, $presented['cart']->currency );

		return $this->changeCodes( $presented, $expectedVersion, $actor, $held ? $codes : array( ...$codes, $code ) );
	}

	/**
	 * Removes a promotion code from the request's cart, in the compare-and-swap; a code the cart does not hold leaves its list as it is.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.not_found` when the request names no live cart;
	 *                        `cart.version_stale`, `cart.not_open` or `cart.not_found` when the
	 *                        compare-and-swap refuses; a database error the transaction could not retry.
	 *
	 * @param string $code            The code, trimmed and upper-cased.
	 * @param int    $expectedVersion The version the client read.
	 * @param Actor  $actor           Who removes it: whose lifetime the cart then lives.
	 * @return Cart The cart after the change.
	 */
	public function removePromotionCode( string $code, int $expectedVersion, Actor $actor ): Cart {
		$presented = $this->presentedRow() ?? CodedException::raise( CartError::NotFound );
		$codes     = array_values( array_filter( $presented['cart']->promotionCodes, static fn( string $applied ): bool => $applied !== $code ) );

		return $this->changeCodes( $presented, $expectedVersion, $actor, $codes );
	}

	/**
	 * Performs `cart.get_cart`: the request's cart by wire name, or an empty cart at version 0.
	 *
	 * A read never writes: with no live cart, or a converted one, it answers an empty cart, with
	 * zero totals, and creates nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param array<string, mixed> $input The prepared input: nothing.
	 * @param Actor                $actor Who reads.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart, keyed by wire name.
	 */
	public function getCart( array $input, Actor $actor ): array {
		unset( $input, $actor );

		return $this->priced( $this->current() );
	}

	/**
	 * Performs `cart.add_lines`: adds the lines and answers the cart by wire name.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes add() and the calculation raise.
	 *
	 * @param array<string, mixed> $input The prepared input: lines, each with variant_id and quantity, and optionally cart_version.
	 * @param Actor                $actor Who adds them.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function addLines( array $input, Actor $actor ): array {
		$lines = array();

		foreach ( (array) $input['lines'] as $line ) {
			$lines[] = CartLine::of( (int) $line['variant_id'], (int) $line['quantity'] );
		}

		$added = $this->addPricingNewCart( $lines, (int) ( $input['cart_version'] ?? 0 ), $actor );

		return $this->priced( $added['cart'], $added['calculation'] );
	}

	/**
	 * Performs `cart.update_line`: sets a line's quantity, 0 removing it, and answers the cart by wire name.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.line_not_found` when the identity is not one; the codes changeQuantity() and the calculation raise.
	 *
	 * @param array<string, mixed> $input The prepared input: line_identity, quantity and cart_version.
	 * @param Actor                $actor Who changes it.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function updateLine( array $input, Actor $actor ): array {
		$sent     = (string) $input['line_identity'];
		$identity = LineIdentity::fromString( $sent ) ?? CodedException::raise( CartError::LineNotFound, array( 'line_identity' => $sent ) );

		return $this->priced( $this->changeQuantity( $identity, (int) $input['quantity'], (int) $input['cart_version'], $actor ) );
	}

	/**
	 * Performs `cart.apply_code`: applies a promotion code, trimmed and upper-cased, and answers the cart by wire name.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes applyPromotionCode() and the calculation raise.
	 *
	 * @param array<string, mixed> $input The prepared input: code and cart_version.
	 * @param Actor                $actor Who applies it.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function applyCode( array $input, Actor $actor ): array {
		return $this->priced( $this->applyPromotionCode( self::enteredCode( (string) $input['code'] ), (int) $input['cart_version'], $actor ) );
	}

	/**
	 * Performs `cart.remove_code`: removes a promotion code, trimmed and upper-cased, and answers the cart by wire name.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes removePromotionCode() and the calculation raise.
	 *
	 * @param array<string, mixed> $input The prepared input: code and cart_version.
	 * @param Actor                $actor Who removes it.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function removeCode( array $input, Actor $actor ): array {
		return $this->priced( $this->removePromotionCode( self::enteredCode( (string) $input['code'] ), (int) $input['cart_version'], $actor ) );
	}

	/**
	 * Claims an open cart for an order placement: its compare-and-swap, which also moves it to placing.
	 *
	 * Runs only inside the placement's transaction, before anything else the placement writes.
	 * Until the settlement moves the cart on, every write to it is refused with `cart.not_open`.
	 * The claim clears the order a cart opened again after a failed payment still names, so the
	 * placement binds its own. Like every write, it extends the cart's life by the placing
	 * customer's lifetime, and issues the token the request presented again for as long; the
	 * token reaches the client only if the placement's answer is a success.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 * @throws CodedException  `cart.not_found`, `cart.not_open` or `cart.version_stale` when the claim refuses.
	 *
	 * @param int   $cartId          The cart.
	 * @param int   $expectedVersion The version the placement was based on.
	 * @param Actor $actor           Who places the order: whose lifetime the cart then lives.
	 * @return int The cart's version after the claim.
	 */
	public function claimForPlacement( int $cartId, int $expectedVersion, Actor $actor ): int {
		$this->requireCallersTransaction( __FUNCTION__ );

		$lifetime = self::lifetimeSeconds( $actor );

		if ( ! $this->carts->claimForPlacement( $cartId, $expectedVersion, $lifetime ) ) {
			$this->refuse( $cartId );
		}

		$token = $this->tokens->presented();

		if ( null !== $token ) {
			$this->tokens->issue( $token, $lifetime );
		}

		return $expectedVersion + 1;
	}

	/**
	 * Records the order a placing cart produced. Runs only inside the placement's transaction, after the claim.
	 *
	 * A cart's order is bound once per claim: a second binding is refused, whatever its order.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or when the cart is not placing or names an
	 *                         order already: the placement did not claim it in this transaction.
	 *
	 * @param int $cartId  The cart.
	 * @param int $orderId The order.
	 */
	public function bindOrder( int $cartId, int $orderId ): void {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( ! $this->carts->bindOrder( $cartId, $orderId ) ) {
			throw new \LogicException( sprintf( 'Cart %d is not placing an order, or has one bound already, so order %d cannot be bound to it: claim the cart in the same transaction first.', $cartId, $orderId ) );
		}
	}

	/**
	 * Settles a placing cart once its order's payment result is known. Runs only inside the settlement's transaction.
	 *
	 * An accepted order converts the cart, which is then finished. Any other result opens it
	 * again, still naming the order, so the shopper can try again from the same cart.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param int  $cartId    The cart.
	 * @param int  $orderId   The order the cart is placing.
	 * @param bool $converted True when the order was accepted.
	 * @return bool True when the cart was placing that order; false when it was not, or is gone,
	 *              which the settlement records rather than fails on, since the money is settled.
	 */
	public function settle( int $cartId, int $orderId, bool $converted ): bool {
		$this->requireCallersTransaction( __FUNCTION__ );

		return $this->carts->settle( $cartId, $orderId, $converted ? CartStatus::Converted : CartStatus::Open );
	}

	/**
	 * Changes the request's cart with another module's write, behind the cart's version, and answers the cart by wire name.
	 *
	 * The one door for a write another module makes that must ride the cart's version, such as
	 * the checkout's details. It is a cart write like any other: the compare-and-swap moves the
	 * version on, a refusal changes nothing, and the cart then lives the writer's lifetime and its
	 * token is issued again. The closure is given the cart's id and runs inside the cart's
	 * transaction, after the compare-and-swap and only when it matched. It sends its own module's
	 * statements and nothing else: no outbound call, and no calculation, which never runs inside
	 * a transaction. The answer is priced once the transaction has ended, so its totals see what
	 * the closure wrote.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.not_found` when the request names no live cart;
	 *                        `cart.version_stale`, `cart.not_open` or `cart.not_found` when the
	 *                        compare-and-swap refuses; what the closure raises; the codes the
	 *                        calculation raises.
	 *
	 * @param int      $expectedVersion The version the client based the write on.
	 * @param Actor    $actor           Who writes: whose lifetime the cart then lives.
	 * @param \Closure $write           Writes the other module's rows, given the cart's id.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 *
	 * @phpstan-param \Closure(int): void $write
	 */
	public function changeWith( int $expectedVersion, Actor $actor, \Closure $write ): array {
		$presented = $this->presentedRow() ?? CodedException::raise( CartError::NotFound );

		return $this->priced( $this->change( $presented, $expectedVersion, $actor, $write ) );
	}

	/**
	 * Returns the token the request carries and the row of the live cart it names, without its lines, which a write reads back once it has changed them.
	 *
	 * @since 0.1.0
	 *
	 * @return array{token: CartToken, cart: Cart}|null The token and the cart, in any status, its
	 *                                                  lines left empty; or null when there is none.
	 */
	private function presentedRow(): ?array {
		$token = $this->tokens->presented();
		$cart  = null === $token ? null : $this->carts->findRowByTokenHash( $token->hash() );

		return null === $token || null === $cart ? null : array(
			'token' => $token,
			'cart'  => $cart,
		);
	}

	/**
	 * Does what add() does, and also returns the calculation a new cart's lines were priced with before it was created, which its answer reuses.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException What add() throws.
	 *
	 * @param CartLine[] $lines           The lines.
	 * @param int        $expectedVersion The version the client read: 0 when it has no cart.
	 * @param Actor      $actor           Who adds them.
	 * @return array{cart: Cart, calculation: Calculation|null} The cart after the change, and the
	 *         calculation of a new cart; null for an existing cart, which is priced once its
	 *         transaction has ended.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	private function addPricingNewCart( array $lines, int $expectedVersion, Actor $actor ): array {
		$lines = CartLine::merge( $lines );

		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'Adding to a cart takes at least one line.' );
		}

		if ( $expectedVersion < 0 ) {
			throw new \InvalidArgumentException( 'A cart version is 0 or more.' );
		}

		$presented = $this->presentedRow();

		// A converted cart is finished: a write based on no cart starts a new one, as it would with no token.
		if ( null === $presented || ( 0 === $expectedVersion && CartStatus::Converted === $presented['cart']->status ) ) {
			if ( 0 !== $expectedVersion ) {
				CodedException::raise( CartError::NotFound );
			}

			return $this->start( $lines, $actor );
		}

		$cart = $this->change(
			$presented,
			$expectedVersion,
			$actor,
			function ( int $cartId ) use ( $lines ): void {
				$this->carts->addLines( $cartId, $lines );
			}
		);

		return array(
			'cart'        => $cart,
			'calculation' => null,
		);
	}

	/**
	 * Starts a cart with its first lines, and hands its token to the client.
	 *
	 * The lines are priced first, outside any transaction, and nothing is created unless that
	 * succeeds: a calculation that fails leaves no cart, issues no token and spends nothing of the
	 * client's cap. The new cart's lines are exactly these lines, in this order, which is the order
	 * of their ids, so this calculation is the new cart's.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises; `cart.creation_limited` over the
	 *                        client's cap; a database error.
	 *
	 * @param CartLine[] $lines The lines, one per identity.
	 * @param Actor      $actor Who starts it.
	 * @return array{cart: Cart, calculation: Calculation} The new cart, and its calculation.
	 *
	 * @phpstan-param non-empty-list<CartLine> $lines
	 */
	private function start( array $lines, Actor $actor ): array {
		$currency    = ( $this->currency )();
		$calculation = $this->priceLines( $currency, $lines, array() );
		$limit       = self::creationLimit();

		// The client is its trusted address and customer id: never a token, which the client chooses and could change on every request.
		if ( $this->limiter->hit( $limit->bucket(), $this->identities->of( $actor->userId() ), $limit->windowSeconds() ) > $limit->limit() ) {
			CodedException::raise( CartError::CreationLimited );
		}

		$token    = CartToken::generate();
		$locale   = ( $this->locale )();
		$lifetime = self::lifetimeSeconds( $actor );

		$cart = $this->tx->transaction(
			function () use ( $token, $currency, $locale, $lines, $lifetime ): Cart {
				$cartId = $this->carts->insert( $token->hash(), $currency, $locale, $lifetime );

				$this->carts->addLines( $cartId, $lines );

				return new Cart( $cartId, 1, CartStatus::Open, null, $currency, $locale, array(), $this->linesAfterWrite( $cartId ) );
			},
			RetryPolicy::deadlocks()
		);

		$this->tokens->issue( $token, $lifetime );

		return array(
			'cart'        => $cart,
			'calculation' => $calculation,
		);
	}

	/**
	 * Changes an existing cart: the compare-and-swap, the write, then the counts and the lines, in one transaction; then issues its token again for its new life.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the compare-and-swap's refusal, the write or the count raise; a
	 *                        stale refusal with the cart's current version and totals.
	 *
	 * @param array{token: CartToken, cart: Cart} $presented       The token the request carries, and its cart's row as read before the transaction.
	 * @param int                                 $expectedVersion The version the client based the write on.
	 * @param Actor                               $actor           Who writes: whose lifetime the cart then lives.
	 * @param \Closure                            $write           Changes the cart's lines, given its id.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param \Closure(int): void $write
	 */
	private function change( array $presented, int $expectedVersion, Actor $actor, \Closure $write ): Cart {
		$cart     = $presented['cart'];
		$lifetime = self::lifetimeSeconds( $actor );

		return $this->commitChange(
			$presented['token'],
			$lifetime,
			function () use ( $cart, $expectedVersion, $lifetime, $write ): Cart {
				if ( ! $this->carts->compareAndSwap( $cart->id, $expectedVersion, $lifetime ) ) {
					$this->refuse( $cart->id );
				}

				$write( $cart->id );

				return $cart->after( $expectedVersion + 1, $this->linesAfterWrite( $cart->id ) );
			}
		);
	}

	/**
	 * Changes an existing cart's promotion codes: the compare-and-swap that writes the new list, then the lines, in one transaction; then issues its token again for its new life.
	 *
	 * The new list was made from the list read with the cart before the transaction, so it may
	 * replace only that list: the write must be based on the version it was read at, and the
	 * compare-and-swap finds the cart still at that version. Anything else is refused as the
	 * compare-and-swap's refusals are, and changes nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the compare-and-swap's refusal raises; a stale refusal with the
	 *                        cart's current version and totals.
	 *
	 * @param array{token: CartToken, cart: Cart} $presented       The token the request carries, and its cart's row as read before the transaction.
	 * @param int                                 $expectedVersion The version the client based the write on.
	 * @param Actor                               $actor           Who writes: whose lifetime the cart then lives.
	 * @param string[]                            $codes           The codes after the change, in the order they were applied.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param list<string> $codes
	 */
	private function changeCodes( array $presented, int $expectedVersion, Actor $actor, array $codes ): Cart {
		$cart     = $presented['cart'];
		$lifetime = self::lifetimeSeconds( $actor );

		return $this->commitChange(
			$presented['token'],
			$lifetime,
			function () use ( $cart, $expectedVersion, $lifetime, $codes ): Cart {
				if ( $expectedVersion !== $cart->version || ! $this->carts->swapPromotionCodes( $cart->id, $expectedVersion, $lifetime, $codes ) ) {
					$this->refuse( $cart->id );
				}

				return $cart->withPromotionCodes( $expectedVersion + 1, $codes, $this->carts->lines( $cart->id ) );
			}
		);
	}

	/**
	 * Runs a change of a cart in one transaction, and then issues the cart's token again for the life the change gave it.
	 *
	 * A refusal as stale is raised again with the cart's current version and totals, worked out
	 * after the rollback.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the change raises; a stale refusal with the cart's current version and totals.
	 *
	 * @param CartToken $token    The token the request carries.
	 * @param int       $lifetime How long the cart lives from this write, in seconds.
	 * @param \Closure  $change   The change, which begins with a compare-and-swap and returns the cart after it.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param \Closure(): Cart $change
	 */
	private function commitChange( CartToken $token, int $lifetime, \Closure $change ): Cart {
		try {
			$changed = $this->tx->transaction( $change, RetryPolicy::deadlocks() );
		} catch ( CodedException $refused ) {
			if ( CartError::VersionStale === $refused->errorCode() ) {
				$this->refuseAsStale( $refused );
			}

			throw $refused;
		}

		// The cart now lives $lifetime from this write, and the client keeps its token as long.
		$this->tokens->issue( $token, $lifetime );

		return $changed;
	}

	/**
	 * Counts a promotion code tried, against the cart's cap and the client's, and refuses it when either count is past its cap.
	 *
	 * Each counter is counted first, and the count its one statement returns, this code included,
	 * is what is compared. So of codes tried at once only as many as a cap has room for go on to be
	 * looked at, however their requests interleave; a look at the counts before counting would let
	 * every one of them through. A code refused for a cap is counted too, which changes nothing: the
	 * cap stays reached until its window ends.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `store_api.rate_limited` when a count is past its cap.
	 *
	 * @param CartToken $token The token of the cart the request's token was found to name.
	 * @param Actor     $actor Who applies the code.
	 */
	private function countCodeTried( CartToken $token, Actor $actor ): void {
		$counters = array(
			array( PromotionCodeLimits::perCart(), $this->identities->ofCart( $token ) ),
			array( PromotionCodeLimits::perClient(), $this->identities->of( $actor->userId() ) ),
		);
		$over     = false;

		foreach ( $counters as list( $limit, $identity ) ) {
			$over = $this->limiter->hit( $limit->bucket(), $identity, $limit->windowSeconds() ) > $limit->limit() || $over;
		}

		if ( $over ) {
			CodedException::raise( StoreApiError::RateLimited );
		}
	}

	/**
	 * Returns a code as a customer entered it, the way promotions store their codes: without surrounding spaces, its letters upper-cased.
	 *
	 * Only ASCII letters change case; a code is compared exactly as stored otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @param string $entered The code entered.
	 * @return string The code to look for.
	 */
	private static function enteredCode( string $entered ): string {
		return strtoupper( trim( $entered ) );
	}

	/**
	 * Raises a stale refusal again with the cart as it is now: its version and its totals, read and worked out after the rollback.
	 *
	 * The version is the one read with the totals, so the two always belong together, even when
	 * another write came between the refusal and this read.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: `cart.version_stale` with the current version and totals; or
	 *                        `cart.not_found` when the cart has expired, or been converted, since.
	 *
	 * @param CodedException $refused The refusal the compare-and-swap's diagnosis raised.
	 * @return never
	 */
	private function refuseAsStale( CodedException $refused ): never {
		$cart = $this->current() ?? CodedException::raise( CartError::NotFound );

		throw CodedException::because( CartError::VersionStale, array( 'current_version' => $cart->version ), $refused, array( 'totals' => $this->calculate( $cart )->totals->toArray() ) );
	}

	/**
	 * Recounts a cart's lines into its row, refusing more than Cart::MAX_LINES, and reads them back.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.too_many_lines` when the cart holds more lines than it may.
	 *
	 * @param int $cartId The cart just written, in this transaction.
	 * @return list<CartLine> Its lines, in the order they were added.
	 */
	private function linesAfterWrite( int $cartId ): array {
		if ( ! $this->carts->project( $cartId, Cart::MAX_LINES ) ) {
			CodedException::raise( CartError::TooManyLines, array( 'max_lines' => Cart::MAX_LINES ) );
		}

		return $this->carts->lines( $cartId );
	}

	/**
	 * Raises the error a refused compare-and-swap means, from one locking read of the cart.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: `cart.not_found` for a cart that is gone or expired,
	 *                        `cart.not_open` for one being or already placed, `cart.version_stale`
	 *                        otherwise, with the version the cart is at.
	 *
	 * @param int $cartId The cart.
	 * @return never
	 */
	private function refuse( int $cartId ): never {
		$state = $this->carts->diagnose( $cartId );

		if ( null === $state || ! $state['live'] ) {
			CodedException::raise( CartError::NotFound );
		}

		if ( CartStatus::Open !== $state['status'] ) {
			CodedException::raise( CartError::NotOpen, array( 'status' => $state['status']->value ) );
		}

		CodedException::raise( CartError::VersionStale, array( 'current_version' => $state['version'] ) );
	}

	/**
	 * Refuses to run a placement's step outside the placement's transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction.
	 *
	 * @param string $method The method called.
	 */
	private function requireCallersTransaction( string $method ): void {
		if ( 0 === $this->tx->depth() ) {
			throw new \LogicException( sprintf( 'CartService::%s() runs inside the placement\'s transaction: the cart must change together with the order.', $method ) );
		}
	}

	/**
	 * Works out a cart's totals: its lines in the order they were added, each keyed by its identity, shipped where its checkout says.
	 *
	 * Runs outside any transaction, as the calculation requires. The cart's checkout gives the
	 * destination and the shipping method chosen, so once a shipping address is known the totals
	 * carry its shipping. With no cart it prices no line, in the currency a new cart would have,
	 * which comes to zero totals, and asks the checkout nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param Cart|null $cart The cart, or null for none.
	 * @return Calculation The totals, and the lines that could not be priced.
	 */
	private function calculate( ?Cart $cart ): Calculation {
		if ( null === $cart ) {
			return $this->priceLines( ( $this->currency )(), array(), array() );
		}

		$delivery = ( $this->delivery )( $cart->id );

		return $this->priceLines( $cart->currency, $cart->lines, $cart->promotionCodes, $delivery['destination'], $delivery['shipping_method_key'] );
	}

	/**
	 * Works out the totals of lines in a currency, with promotion codes: each line keyed by its identity, in the order given.
	 *
	 * Runs outside any transaction, as the calculation requires.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param Currency     $currency          The currency.
	 * @param CartLine[]   $lines             The lines, in the cart's order.
	 * @param string[]     $promotionCodes    The promotion codes applied.
	 * @param Address|null $destination       Optional. Where the order ships. Default null, not known yet.
	 * @param string|null  $shippingMethodKey Optional. The shipping method chosen. Default null, the cheapest.
	 * @return Calculation The totals, and the lines that could not be priced.
	 *
	 * @phpstan-param list<CartLine> $lines
	 * @phpstan-param list<string>   $promotionCodes
	 */
	private function priceLines( Currency $currency, array $lines, array $promotionCodes, ?Address $destination = null, ?string $shippingMethodKey = null ): Calculation {
		$requests = array();

		foreach ( $lines as $line ) {
			$requests[] = new LineRequest( $line->identity->value(), $line->variantId, $line->quantity );
		}

		return $this->calculator->calculate( new CalculationRequest( $currency, $requests, $destination, $promotionCodes, $shippingMethodKey ) );
	}

	/**
	 * Writes a cart by wire name, with its totals: the calculation's own, and the lines it could not price.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param Cart|null        $cart        The cart, or null for none.
	 * @param Calculation|null $calculation Optional. The cart's calculation, when its lines were
	 *                                      priced already. Default null, work it out now.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The version, 0 for no cart, the lines, the promotion codes, the totals and the unpriced lines.
	 */
	private function priced( ?Cart $cart, ?Calculation $calculation = null ): array {
		$calculation = $calculation ?? $this->calculate( $cart );
		$lines       = array();

		foreach ( null === $cart ? array() : $cart->lines as $line ) {
			$lines[] = array(
				'line_identity' => $line->identity->value(),
				'variant_id'    => $line->variantId,
				'quantity'      => $line->quantity,
			);
		}

		return array(
			'version'         => null === $cart ? 0 : $cart->version,
			'lines'           => $lines,
			'promotion_codes' => array_map( static fn( string $code ): array => array( 'code' => $code ), null === $cart ? array() : $cart->promotionCodes ),
			'totals'          => $calculation->totals->toArray(),
			'unpriced_lines'  => array_map(
				static fn( UnpricedLine $unpriced ): array => array(
					'line_identity' => $unpriced->key,
					'variant_id'    => $unpriced->variantId,
					'reason'        => $unpriced->reason,
				),
				$calculation->unpricedLines
			),
		);
	}
}
