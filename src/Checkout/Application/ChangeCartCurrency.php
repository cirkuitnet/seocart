<?php
/**
 * ChangeCartCurrency: switches the request's cart to another currency the store sells in, behind the cart's version
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Application\CartService;
use SEOCart\Cart\Domain\CartStatus;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Order\Application\Orders;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Pricing\Application\PresentmentCurrencies;
use SEOCart\Support\Currency;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Performs `checkout.change_currency`: switches the request's cart to another currency, and answers the cart priced in it.
 *
 * Owns one fact: what a switch of a cart's currency changes. The currency must be one the store
 * sells in now: its base currency, or an enabled currency with a rate in the current
 * exchange-rate version. That is decided first, by one read the cart's calculation then reuses,
 * and any other currency, or text that is no currency code, gets the same refusal before the cart
 * is read or anything is written. Then, in the cart's one transaction
 * (CartService::changeCurrency()), the cart's compare-and-swap writes the currency, and the
 * session's quotes are dropped, since a rate quoted in one currency is no rate in another; the
 * shipping method chosen stays, as the shopper's preference.
 *
 * Nothing else is touched. An open cart has no stock held and no payment intent: only a placement
 * makes them, and a cart placing an order refuses the switch, naming the order. The answer is the
 * cart priced in its new currency once the transaction has ended: a price authored in that
 * currency is used, a base price is converted where the currency allows it, a line with neither is
 * reported unpriced, and a promotion code whose fixed amount is in another currency stays on the
 * cart but takes nothing off.
 *
 * @since 0.1.0
 */
final class ChangeCartCurrency {

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param CartService           $carts        The cart, whose version the switch rides.
	 * @param CheckoutSessions      $sessions     The stored sessions, whose quotes the switch drops.
	 * @param PresentmentCurrencies $currencies   Tells whether the store sells in a currency now.
	 * @param Orders                $orders       Names the order of a cart that is not open.
	 * @param \Closure              $baseCurrency Returns the store's base currency.
	 *
	 * @phpstan-param \Closure(): Currency $baseCurrency
	 */
	public function __construct(
		private CartService $carts,
		private CheckoutSessions $sessions,
		private PresentmentCurrencies $currencies,
		private Orders $orders,
		private \Closure $baseCurrency
	) {
	}

	/**
	 * Performs `checkout.change_currency`.
	 *
	 * A switch to the currency the cart is in already is accepted like any other: the version moves
	 * on and the session's quotes are dropped, which the version's move makes stale anyway; nothing
	 * else changes.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.currency_not_enabled` before anything is read or written;
	 *                        `cart.not_found`; `store_api.rate_limited` past the cart's cap on
	 *                        switches; `cart.version_stale` with the cart's version and totals now;
	 *                        `cart.not_open`, naming the order the cart is placing; the codes the
	 *                        calculation raises.
	 *
	 * @param array<string, mixed> $input The prepared input: cart_version and currency.
	 * @param Actor                $actor Who switches: whose lifetime the cart then lives.
	 * @return array{version: int, lines: list<array{line_identity: string, variant_id: int, quantity: int}>, promotion_codes: list<array{code: string}>, totals: array<string, mixed>, unpriced_lines: list<array{line_identity: string, variant_id: int, reason: string}>} The cart after the switch, priced in its new currency.
	 */
	public function change( array $input, Actor $actor ): array {
		$currency = $this->sold( (string) $input['currency'] ) ?? CodedException::raise( CheckoutError::CurrencyNotEnabled );

		try {
			return $this->carts->changeCurrency(
				$currency,
				(int) $input['cart_version'],
				$actor,
				function ( int $cartId ): void {
					$this->sessions->invalidateQuotes( $cartId );
				}
			);
		} catch ( CodedException $refused ) {
			if ( CartError::NotOpen === $refused->errorCode() ) {
				$this->refuseNamingTheOrder( $refused );
			}

			throw $refused;
		}
	}

	/**
	 * Returns the currency a code names, when the store sells in it now.
	 *
	 * @since 0.1.0
	 *
	 * @param string $code The code the client sent.
	 * @return Currency|null The currency; null for a code that is not an ISO 4217 code written in
	 *                       upper case, or a currency that is not enabled or has no current rate.
	 */
	private function sold( string $code ): ?Currency {
		try {
			$currency = Currency::of( $code );
		} catch ( CodedException ) {
			return null;
		}

		return null === $this->currencies->find( ( $this->baseCurrency )(), $currency ) ? null : $currency;
	}

	/**
	 * Refuses the switch of a cart that is not open again, naming the order it is placing.
	 *
	 * The cart is read again after the refusal: one that has since been converted reads as no cart,
	 * and one opened again by a declined payment no longer refuses, so either gets the cart's own
	 * refusal as it was.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: `cart.not_open`, naming the order when the cart still refuses.
	 *
	 * @param CodedException $refused The cart's refusal.
	 * @return never
	 */
	private function refuseNamingTheOrder( CodedException $refused ): never {
		$cart = $this->carts->current();

		if ( null === $cart || CartStatus::Open === $cart->status ) {
			throw $refused;
		}

		NotOpenCart::refuse( $cart, $this->orders );
	}
}
