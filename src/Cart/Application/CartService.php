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
 * creates one, and a token is issued only by the write that creates its cart.
 *
 * Every change of an existing cart is one transaction that begins with the compare-and-swap on
 * the version the client based it on. One row changed: the cart was open, live and at that
 * version, it is now at the next one, its life is extended, and the write goes on in the same
 * transaction. No row changed: one locking read classifies the refusal as `cart.not_found`,
 * `cart.not_open` or `cart.version_stale`, and nothing is changed. So a write replayed with the
 * version it was first sent with is refused, which is what makes a batch of lines safe to send
 * twice. The lines the answer carries are read in the same transaction, after the change, so the
 * version and the lines of an answer always belong together.
 *
 * Every answer carries the cart's totals, which the calculation works out from its lines when
 * the answer is built, after any transaction has ended; the cart stores selections, never prices.
 * A write refused as stale carries the totals of the cart as it is now, worked out after the
 * rollback.
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
	 * Reads the token the request carries, and hands a new cart's token to the client.
	 *
	 * @since 0.1.0
	 *
	 * @var CartTokens
	 */
	private CartTokens $tokens;

	/**
	 * Counts the carts each client starts.
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
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param CartRepository     $carts      The statements.
	 * @param TransactionManager $tx         The unit of work.
	 * @param CartTokens         $tokens     Reads and issues cart tokens.
	 * @param RateLimiter        $limiter    Counts the carts each client starts.
	 * @param ClientIdentities   $identities Names the client.
	 * @param \Closure           $currency   Returns the currency a new cart is in.
	 * @param \Closure           $locale     Returns the locale a new cart is started in.
	 * @param Calculator         $calculator Works out the totals of a cart's lines.
	 *
	 * @phpstan-param \Closure(): Currency $currency
	 * @phpstan-param \Closure(): Locale   $locale
	 */
	public function __construct( CartRepository $carts, TransactionManager $tx, CartTokens $tokens, RateLimiter $limiter, ClientIdentities $identities, \Closure $currency, \Closure $locale, Calculator $calculator ) {
		$this->carts      = $carts;
		$this->tx         = $tx;
		$this->tokens     = $tokens;
		$this->limiter    = $limiter;
		$this->identities = $identities;
		$this->currency   = $currency;
		$this->locale     = $locale;
		$this->calculator = $calculator;
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
	 * Returns the cart the request's token names.
	 *
	 * @since 0.1.0
	 *
	 * @return Cart|null The live cart, or null when the request carries no token, or its cart does
	 *                   not exist or has expired.
	 */
	public function current(): ?Cart {
		$token = $this->tokens->presented();

		return null === $token ? null : $this->carts->findByTokenHash( $token->hash() );
	}

	/**
	 * Adds lines to the request's cart, or starts a cart with them.
	 *
	 * The lines are combined by identity first. With no live cart and an expected version of 0,
	 * the lines start a new cart: it is counted against the client's cap, inserted at version 1
	 * with its lines in one transaction, and its token is issued with the response. Otherwise the
	 * lines are added to the cart behind the compare-and-swap, in one statement whatever their
	 * number: a line the cart has already gains the units, up to CartLine::MAX_QUANTITY.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException|\InvalidArgumentException `cart.not_found` when an expected version
	 *         above 0 names no live cart; `cart.version_stale`, `cart.not_open` or `cart.not_found`
	 *         when the compare-and-swap refuses; `cart.too_many_lines` when the cart would hold more
	 *         than Cart::MAX_LINES lines; `cart.creation_limited` when the client may start no more
	 *         carts today; a database error the transaction could not retry. An
	 *         \InvalidArgumentException when there is no line or the expected version is negative.
	 *
	 * @param CartLine[] $lines           The lines.
	 * @param int        $expectedVersion The version the client read: 0 when it has no cart.
	 * @param Actor      $actor           Who adds them, for the cap on new carts.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param list<CartLine> $lines
	 */
	public function add( array $lines, int $expectedVersion, Actor $actor ): Cart {
		$lines = CartLine::merge( $lines );

		if ( array() === $lines ) {
			throw new \InvalidArgumentException( 'Adding to a cart takes at least one line.' );
		}

		if ( $expectedVersion < 0 ) {
			throw new \InvalidArgumentException( 'A cart version is 0 or more.' );
		}

		$cart = $this->presentedRow();

		if ( null === $cart ) {
			if ( 0 !== $expectedVersion ) {
				CodedException::raise( CartError::NotFound );
			}

			return $this->start( $lines, $actor );
		}

		return $this->change(
			$cart,
			$expectedVersion,
			function ( int $cartId ) use ( $lines ): void {
				$this->carts->addLines( $cartId, $lines );
			}
		);
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
	 * @return Cart The cart after the change.
	 */
	public function changeQuantity( LineIdentity $identity, int $quantity, int $expectedVersion ): Cart {
		if ( $quantity < 0 || $quantity > CartLine::MAX_QUANTITY ) {
			throw new \InvalidArgumentException( sprintf( 'A line holds 0 to %d units; %d was given.', CartLine::MAX_QUANTITY, $quantity ) );
		}

		$cart = $this->presentedRow() ?? CodedException::raise( CartError::NotFound );

		return $this->change(
			$cart,
			$expectedVersion,
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
	 * Performs `cart.get_cart`: the request's cart by wire name, or an empty cart at version 0.
	 *
	 * A read never writes: with no live cart it answers an empty cart, with zero totals, and
	 * creates nothing.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param array<string, mixed> $input The prepared input: nothing.
	 * @param Actor                $actor Who reads.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart, keyed by wire name.
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
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function addLines( array $input, Actor $actor ): array {
		$lines = array();

		foreach ( (array) $input['lines'] as $line ) {
			$lines[] = CartLine::of( (int) $line['variant_id'], (int) $line['quantity'] );
		}

		return $this->priced( $this->add( $lines, (int) ( $input['cart_version'] ?? 0 ), $actor ) );
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
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the change.
	 */
	public function updateLine( array $input, Actor $actor ): array {
		unset( $actor );

		$sent     = (string) $input['line_identity'];
		$identity = LineIdentity::fromString( $sent ) ?? CodedException::raise( CartError::LineNotFound, array( 'line_identity' => $sent ) );

		return $this->priced( $this->changeQuantity( $identity, (int) $input['quantity'], (int) $input['cart_version'] ) );
	}

	/**
	 * Claims an open cart for an order placement: its compare-and-swap, which also moves it to placing.
	 *
	 * Runs only inside the placement's transaction, before anything else the placement writes.
	 * Until the settlement moves the cart on, every write to it is refused with `cart.not_open`.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, before any statement.
	 * @throws CodedException  `cart.not_found`, `cart.not_open` or `cart.version_stale` when the claim refuses.
	 *
	 * @param int $cartId          The cart.
	 * @param int $expectedVersion The version the placement was based on.
	 * @return int The cart's version after the claim.
	 */
	public function claimForPlacement( int $cartId, int $expectedVersion ): int {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( ! $this->carts->claimForPlacement( $cartId, $expectedVersion, self::ttlSeconds() ) ) {
			$this->refuse( $cartId );
		}

		return $expectedVersion + 1;
	}

	/**
	 * Records the order a placing cart produced. Runs only inside the placement's transaction, after the claim.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException Outside a transaction, or when the cart is not placing: the placement
	 *                         did not claim it in this transaction.
	 *
	 * @param int $cartId  The cart.
	 * @param int $orderId The order.
	 */
	public function bindOrder( int $cartId, int $orderId ): void {
		$this->requireCallersTransaction( __FUNCTION__ );

		if ( ! $this->carts->bindOrder( $cartId, $orderId ) ) {
			throw new \LogicException( sprintf( 'Cart %d is not placing an order, so order %d cannot be bound to it: claim the cart in the same transaction first.', $cartId, $orderId ) );
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
	 * Returns the row of the cart the request's token names, without its lines, which a write reads back once it has changed them.
	 *
	 * @since 0.1.0
	 *
	 * @return Cart|null The live cart, its lines left empty, or null when there is none.
	 */
	private function presentedRow(): ?Cart {
		$token = $this->tokens->presented();

		return null === $token ? null : $this->carts->findRowByTokenHash( $token->hash() );
	}

	/**
	 * Starts a cart with its first lines, and hands its token to the client.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `cart.creation_limited` over the client's cap; a database error.
	 *
	 * @param CartLine[] $lines The lines, one per identity.
	 * @param Actor      $actor Who starts it.
	 * @return Cart The new cart.
	 *
	 * @phpstan-param non-empty-list<CartLine> $lines
	 */
	private function start( array $lines, Actor $actor ): Cart {
		$limit = self::creationLimit();

		// The client is its trusted address and customer id: never a token, which the client chooses and could change on every request.
		if ( $this->limiter->hit( $limit->bucket(), $this->identities->of( $actor->userId() ), $limit->windowSeconds() ) > $limit->limit() ) {
			CodedException::raise( CartError::CreationLimited );
		}

		$token    = CartToken::generate();
		$currency = ( $this->currency )();
		$locale   = ( $this->locale )();

		$cart = $this->tx->transaction(
			function () use ( $token, $currency, $locale, $lines ): Cart {
				$cartId = $this->carts->insert( $token->hash(), $currency, $locale, self::ttlSeconds() );

				$this->carts->addLines( $cartId, $lines );

				return new Cart( $cartId, 1, CartStatus::Open, null, $currency, $locale, array(), $this->linesAfterWrite( $cartId ) );
			},
			RetryPolicy::deadlocks()
		);

		$this->tokens->issue( $token );

		return $cart;
	}

	/**
	 * Changes an existing cart: the compare-and-swap, the write, then the counts and the lines, in one transaction.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException What the compare-and-swap's refusal, the write or the count raise; a
	 *                        stale refusal with the cart's current version and totals.
	 *
	 * @param Cart     $cart            The cart's row, as read before the transaction.
	 * @param int      $expectedVersion The version the client based the write on.
	 * @param \Closure $write           Changes the cart's lines, given its id.
	 * @return Cart The cart after the change.
	 *
	 * @phpstan-param \Closure(int): void $write
	 */
	private function change( Cart $cart, int $expectedVersion, \Closure $write ): Cart {
		try {
			return $this->tx->transaction(
				function () use ( $cart, $expectedVersion, $write ): Cart {
					if ( ! $this->carts->compareAndSwap( $cart->id, $expectedVersion, self::ttlSeconds() ) ) {
						$this->refuse( $cart->id );
					}

					$write( $cart->id );

					return $cart->after( $expectedVersion + 1, $this->linesAfterWrite( $cart->id ) );
				},
				RetryPolicy::deadlocks()
			);
		} catch ( CodedException $refused ) {
			if ( CartError::VersionStale === $refused->errorCode() ) {
				$this->refuseAsStale( $refused );
			}

			throw $refused;
		}
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
	 *                        `cart.not_found` when the cart has expired since.
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
	 * Returns how long a cart lives after its last write: the guest period of its retention policy.
	 *
	 * @since 0.1.0
	 *
	 * @return int Seconds.
	 */
	private static function ttlSeconds(): int {
		$period = new \DateInterval( ( new RetentionCatalog() )->defaults( Cart::RETENTION )['guest'] );

		return ( new \DateTimeImmutable( '@0' ) )->add( $period )->getTimestamp();
	}

	/**
	 * Works out a cart's totals: its lines in the order they were added, each keyed by its identity.
	 *
	 * Runs outside any transaction, as the calculation requires. With no cart it prices no line,
	 * in the currency a new cart would have, which comes to zero totals.
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
			return $this->calculator->calculate( new CalculationRequest( ( $this->currency )(), array() ) );
		}

		$lines = array();

		foreach ( $cart->lines as $line ) {
			$lines[] = new LineRequest( $line->identity->value(), $line->variantId, $line->quantity );
		}

		return $this->calculator->calculate( new CalculationRequest( $cart->currency, $lines, null, $cart->promotionCodes ) );
	}

	/**
	 * Writes a cart by wire name, with its totals: the calculation's own, and the lines it could not price.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException The codes the calculation raises.
	 *
	 * @param Cart|null $cart The cart, or null for none.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The version, 0 for no cart, the lines, the totals and the unpriced lines.
	 */
	private function priced( ?Cart $cart ): array {
		$calculation = $this->calculate( $cart );
		$lines       = array();

		foreach ( null === $cart ? array() : $cart->lines as $line ) {
			$lines[] = array(
				'line_identity' => $line->identity->value(),
				'variant_id'    => $line->variantId,
				'quantity'      => $line->quantity,
			);
		}

		return array(
			'version'        => null === $cart ? 0 : $cart->version,
			'lines'          => $lines,
			'totals'         => $calculation->totals->toArray(),
			'unpriced_lines' => array_map(
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
