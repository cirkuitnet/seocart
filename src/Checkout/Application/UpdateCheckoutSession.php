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
use SEOCart\Checkout\Domain\Fields\Carrier;
use SEOCart\Payment\Application\Gateways;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Pricing\Application\Calculation;
use SEOCart\Pricing\Application\RoutingScope;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A coded error is shaped and escaped by the error translator, never printed here.

/**
 * Performs `checkout.update_session`: replaces the details of the request's cart's checkout, and answers with them, the cart's version and its totals.
 *
 * Owns one fact: how a checkout write reaches the store. The details are checked first, every
 * value against the checkout's fields in force (CheckoutRules::validate()), then written through
 * the cart's one door for another module's write (CartService::changeWith()): the cart's
 * compare-and-swap and the session's upsert are one transaction, so the details change exactly
 * when the cart's version moves on, and a write based on an older version changes neither. Moving
 * the version on makes the session's quotes stale, and the upsert drops them too. The cart's
 * totals are then worked out after the transaction, for the shipping address and method just
 * written, with the plan of where the order's units are held (PlannedPricing). A plan no location
 * can give is refused with the router's code, as a destination with no shipping rate is.
 *
 * A payment method is the id of a payment gateway the store has, set up for the mode it takes new
 * payments in. Whether it can take this cart's payment, by its currency and total, is checked when
 * the order is placed, once the total is known. So is an order limit: the answer says where the
 * cart's goods stand against the limits of its currency, measured on the totals it answers, and a
 * write is never refused for one, so a shopper below a minimum can still save an address.
 *
 * @since 0.1.0
 * @since 0.2.0 Checks the payment method against the store's gateways. Checks every value against
 *              the checkout's fields, and answers where the cart stands against the order limits.
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
	 * The checkout's pricing with its plan, which asks once more after reclaiming expired holds.
	 *
	 * @since 0.2.0
	 *
	 * @var PlannedPricing
	 */
	private PlannedPricing $pricing;

	/**
	 * The checkout's rules, which judge every value before anything is written.
	 *
	 * @since 0.2.0
	 *
	 * @var CheckoutRules
	 */
	private CheckoutRules $rules;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The gateways, the planned pricing and the checkout's rules were added.
	 *
	 * @param CartService      $carts    The cart.
	 * @param CheckoutSessions $sessions The stored sessions.
	 * @param Gateways         $gateways The store's payment gateways.
	 * @param PlannedPricing   $pricing  The checkout's pricing with its plan.
	 * @param CheckoutRules    $rules    The checkout's rules: its fields in force and its order limits.
	 */
	public function __construct( CartService $carts, CheckoutSessions $sessions, Gateways $gateways, PlannedPricing $pricing, CheckoutRules $rules ) {
		$this->carts    = $carts;
		$this->sessions = $sessions;
		$this->gateways = $gateways;
		$this->pricing  = $pricing;
		$this->rules    = $rules;
	}

	/**
	 * Performs `checkout.update_session`.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Checks every value against the checkout's fields, and answers the order limit.
	 *              Writes and answers the values of the custom fields.
	 *
	 * @throws CodedException `checkout.invalid_fields` with every value the checkout's fields do not
	 *                        accept, or `checkout.invalid_method_key` when a method key is not one,
	 *                        or the payment method is not a gateway the store has set up for its
	 *                        mode, before anything is written; `cart.not_found`,
	 *                        `cart.version_stale` or `cart.not_open` when the cart's
	 *                        compare-and-swap refuses; the codes the calculation raises, its plan's
	 *                        `stock.routing_refused` and `stock.no_fulfilling_location` among them.
	 *
	 * @param array<string, mixed> $input The prepared input: cart_version, and optionally
	 *                                    billing_address, shipping_address, shipping_method_key,
	 *                                    payment_method_key and custom_fields.
	 * @param Actor                $actor Who writes: whose lifetime the cart then lives.
	 * @return array{checkout_session: array<string, mixed>, version: int, totals: array<string, mixed>, delivery: array{requires_transfer: bool, transfer_days: int|null}|null, order_limit: array<string, int|string|null>|null} The session,
	 *         the cart's version, its totals, what its plan says of delivery, by wire name: whether units must be
	 *         brought together and the extra days the shopper is told that adds, null when no plan was made; and
	 *         where its goods stand against the order limits of its currency, null when it has none (OrderLimits::verdict()).
	 */
	public function update( array $input, Actor $actor ): array {
		$this->rules->validate( $input );

		$details = new CheckoutDetails(
			self::address( Carrier::BILLING, $input ),
			self::address( Carrier::SHIPPING, $input ),
			self::methodKey( 'shipping_method_key', $input ),
			$this->paymentMethod( $input ),
			CheckoutRules::customValues( $input )
		);

		$calculation = null;
		$cart        = $this->carts->changeWith(
			(int) $input['cart_version'],
			$actor,
			function ( int $cartId ) use ( $details ): void {
				$this->sessions->save( $cartId, $details );
			},
			RoutingScope::checkout(),
			// The answer's totals are this calculation's: the order limit is measured on the same one.
			function ( \Closure $price ) use ( &$calculation ): Calculation {
				$calculation = $this->pricing->price( $price );

				return $calculation;
			}
		);

		return array(
			'checkout_session' => array(
				'billing_address'     => null === $details->billingAddress ? null : AddressDocument::of( $details->billingAddress ),
				'shipping_address'    => null === $details->shippingAddress ? null : AddressDocument::of( $details->shippingAddress ),
				'shipping_method_key' => $details->shippingMethodKey,
				'payment_method_key'  => $details->paymentMethodKey,
				'custom_fields'       => CheckoutRules::customEntries( $details->fieldValues ),
			),
			'version'          => $cart['version'],
			'totals'           => $cart['totals'],
			'delivery'         => $cart['routing'],
			'order_limit'      => null === $calculation ? null : OrderLimits::verdict( $calculation->totals, $this->rules->orderLimits() ),
		);
	}

	/**
	 * Reads one of the input's addresses, which CheckoutRules::validate() has judged.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The values are judged by the checkout's rules before.
	 *
	 * @param string               $field The input field: Carrier::BILLING or Carrier::SHIPPING.
	 * @param array<string, mixed> $input The prepared input.
	 * @return Address|null The address, or null when the input leaves it out.
	 */
	private static function address( string $field, array $input ): ?Address {
		return isset( $input[ $field ] ) ? AddressDocument::toAddress( (array) $input[ $field ] ) : null;
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
