<?php
/**
 * CheckoutDetails: what a shopper tells the checkout before placing an order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- These exceptions report a caller's programming error to the developer; they are never HTML.

/**
 * The shopper's checkout choices: the two addresses and the shipping and payment methods, each null until given.
 *
 * Owns one fact: what a checkout session write replaces, what a method key may be, and which
 * details an order cannot be placed without. A write sends all four, and one it leaves out is
 * cleared. A method key is a provider's name for a method, such as `flat`: lower-case ASCII
 * letters and digits with dots, colons, hyphens and underscores, which is all its column can
 * store unchanged.
 *
 * @since 0.1.0
 */
final readonly class CheckoutDetails {

	/**
	 * The longest method key, in characters.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	public const METHOD_KEY_MAX_LENGTH = 64;

	/**
	 * What a method key is.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const METHOD_KEY_PATTERN = '/^[a-z0-9_.:-]{1,' . self::METHOD_KEY_MAX_LENGTH . '}\z/';

	/**
	 * Holds the details.
	 *
	 * @since 0.1.0
	 *
	 * @throws \InvalidArgumentException When a method key is not one: isMethodKey().
	 *
	 * @param Address|null $billingAddress    The address the payment is billed to, or null.
	 * @param Address|null $shippingAddress   Where the order ships, or null.
	 * @param string|null  $shippingMethodKey The shipping method chosen, or null for the cheapest one quoted.
	 * @param string|null  $paymentMethodKey  The payment method chosen, or null.
	 */
	public function __construct(
		public ?Address $billingAddress,
		public ?Address $shippingAddress,
		public ?string $shippingMethodKey,
		public ?string $paymentMethodKey
	) {
		foreach ( array( $shippingMethodKey, $paymentMethodKey ) as $key ) {
			if ( null !== $key && ! self::isMethodKey( $key ) ) {
				throw new \InvalidArgumentException( sprintf( 'A method key is 1 to %d lower-case ASCII letters, digits, dots, colons, hyphens and underscores.', self::METHOD_KEY_MAX_LENGTH ) );
			}
		}
	}

	/**
	 * Lists what an order cannot be placed without and the details lack: both addresses, an e-mail address on the billing one, and a payment method.
	 *
	 * The shipping method may be left out: the cheapest rate quoted is the one charged.
	 *
	 * @since 0.1.0
	 *
	 * @return list<string> The missing fields, by wire name: `billing_address`, `billing_address.email`,
	 *                      `shipping_address` and `payment_method_key`; none when an order can be placed.
	 */
	public function missingForPlacement(): array {
		$missing = array();

		if ( null === $this->billingAddress ) {
			$missing[] = 'billing_address';
		} elseif ( '' === trim( $this->billingAddress->email() ) ) {
			$missing[] = 'billing_address.email';
		}

		if ( null === $this->shippingAddress ) {
			$missing[] = 'shipping_address';
		}

		if ( null === $this->paymentMethodKey ) {
			$missing[] = 'payment_method_key';
		}

		return $missing;
	}

	/**
	 * Tells whether a text is a method key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The text.
	 * @return bool True when it matches METHOD_KEY_PATTERN.
	 */
	public static function isMethodKey( string $key ): bool {
		return 1 === preg_match( self::METHOD_KEY_PATTERN, $key );
	}
}
