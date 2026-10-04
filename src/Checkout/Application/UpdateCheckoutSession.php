<?php
/**
 * UpdateCheckoutSession: writes a cart's checkout details, behind the cart's version
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Application\CartService;
use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Checkout\Domain\CheckoutDetails;
use SEOCart\Checkout\Domain\CheckoutError;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A coded error is shaped and escaped by the error translator, never printed here.

/**
 * Performs `checkout.update_session`: replaces the details of the request's cart's checkout, and answers with them, the cart's version and its totals.
 *
 * Owns one fact: how a checkout write reaches the store. The details are checked first, then
 * written through the cart's one door for another module's write (CartService::changeWith()):
 * the cart's compare-and-swap and the session's upsert are one transaction, so the details
 * change exactly when the cart's version moves on, and a write based on an older version changes
 * neither. Moving the version on makes the session's quotes stale, and the upsert drops them too.
 * The cart's totals are then worked out after the transaction, for the shipping address and
 * method just written.
 *
 * A payment method is the id of a payment gateway the store has, set up for the mode it takes new
 * payments in. Whether it can take this cart's payment, by its currency and total, is checked when
 * the order is placed, once the total is known.
 *
 * @since 0.1.0
 * @since 0.2.0 Checks the payment method against the store's gateways.
 */
final class UpdateCheckoutSession {

	/**
	 * The cart, whose version every write rides.
	 *
	 * @since 0.1.0
	 *
	 * @var CartService
	 */
	private CartService $carts;

	/**
	 * The stored sessions.
	 *
	 * @since 0.1.0
	 *
	 * @var CheckoutSessions
	 */
	private CheckoutSessions $sessions;

	/**
	 * The store's payment gateways, which a payment method must be one of.
	 *
	 * @since 0.2.0
	 *
	 * @var Gateways
	 */
	private Gateways $gateways;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateways were added.
	 *
	 * @param CartService      $carts    The cart.
	 * @param CheckoutSessions $sessions The stored sessions.
	 * @param Gateways         $gateways The store's payment gateways.
	 */
	public function __construct( CartService $carts, CheckoutSessions $sessions, Gateways $gateways ) {
		$this->carts    = $carts;
		$this->sessions = $sessions;
		$this->gateways = $gateways;
	}

	/**
	 * Performs `checkout.update_session`.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.invalid_address` when an address has a field the checkout
	 *                        cannot use, or `checkout.invalid_method_key` when a method key is not
	 *                        one, or the payment method is not a gateway the store has set up for
	 *                        its mode, before anything is written; `cart.not_found`,
	 *                        `cart.version_stale` or `cart.not_open` when the cart's
	 *                        compare-and-swap refuses; the codes the calculation raises.
	 *
	 * @param array<string, mixed> $input The prepared input: cart_version, and optionally
	 *                                    billing_address, shipping_address, shipping_method_key
	 *                                    and payment_method_key.
	 * @param Actor                $actor Who writes: whose lifetime the cart then lives.
	 * @return array{checkout_session: array<string, mixed>, version: int, totals: array<string, mixed>} The session, the cart's version and its totals, by wire name.
	 */
	public function update( array $input, Actor $actor ): array {
		$details = new CheckoutDetails(
			self::address( 'billing_address', $input ),
			self::address( 'shipping_address', $input ),
			self::methodKey( 'shipping_method_key', $input ),
			$this->paymentMethod( $input )
		);

		$cart = $this->carts->changeWith(
			(int) $input['cart_version'],
			$actor,
			function ( int $cartId ) use ( $details ): void {
				$this->sessions->save( $cartId, $details );
			}
		);

		return array(
			'checkout_session' => array(
				'billing_address'     => null === $details->billingAddress ? null : AddressDocument::of( $details->billingAddress ),
				'shipping_address'    => null === $details->shippingAddress ? null : AddressDocument::of( $details->shippingAddress ),
				'shipping_method_key' => $details->shippingMethodKey,
				'payment_method_key'  => $details->paymentMethodKey,
			),
			'version'          => $cart['version'],
			'totals'           => $cart['totals'],
		);
	}

	/**
	 * Reads one of the input's addresses, refusing a field the checkout cannot use.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.invalid_address`, naming the field: a country that is not
	 *                        two upper-case letters, or an e-mail address that is not one.
	 *
	 * @param string               $field The input field: billing_address or shipping_address.
	 * @param array<string, mixed> $input The prepared input.
	 * @return Address|null The address, or null when the input leaves it out.
	 */
	private static function address( string $field, array $input ): ?Address {
		if ( ! isset( $input[ $field ] ) ) {
			return null;
		}

		$document = (array) $input[ $field ];
		$email    = (string) ( $document['email'] ?? '' );

		if ( '' !== $email && false === is_email( $email ) ) {
			CodedException::raise( CheckoutError::InvalidAddress, array( 'field' => $field . '.email' ) );
		}

		try {
			return AddressDocument::toAddress( $document );
		} catch ( \InvalidArgumentException $refused ) {
			// The schema has made every field a string, so what Address refuses is its country.
			throw CodedException::because( CheckoutError::InvalidAddress, array( 'field' => $field . '.country' ), $refused );
		}
	}

	/**
	 * Reads the input's payment method, refusing one that is not a gateway the store has set up for the mode it takes new payments in.
	 *
	 * Reads the gateway's settings, when it has any; the stand-in has none.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `checkout.invalid_method_key`, naming the field.
	 *
	 * @param array<string, mixed> $input The prepared input.
	 * @return string|null The gateway's id, or null when the input leaves it out.
	 */
	private function paymentMethod( array $input ): ?string {
		$key = self::methodKey( 'payment_method_key', $input );

		if ( null !== $key && null === $this->gateways->configuredMode( $key ) ) {
			CodedException::raise( CheckoutError::InvalidMethodKey, array( 'field' => 'payment_method_key' ) );
		}

		return $key;
	}

	/**
	 * Reads one of the input's method keys, refusing one that is not a method key: an empty one is none.
	 *
	 * The column a key is stored in keeps ASCII only, and the database would store anything else
	 * changed, so the key is refused here instead.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `checkout.invalid_method_key`, naming the field.
	 *
	 * @param string               $field The input field: shipping_method_key or payment_method_key.
	 * @param array<string, mixed> $input The prepared input.
	 * @return string|null The key, or null when the input leaves it out.
	 */
	private static function methodKey( string $field, array $input ): ?string {
		$key = (string) ( $input[ $field ] ?? '' );

		if ( '' === $key ) {
			return null;
		}

		if ( ! CheckoutDetails::isMethodKey( $key ) ) {
			CodedException::raise( CheckoutError::InvalidMethodKey, array( 'field' => $field ) );
		}

		return $key;
	}
}
