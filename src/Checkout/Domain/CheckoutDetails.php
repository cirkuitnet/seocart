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
 * The shopper's checkout choices: the two addresses and the shipping and payment methods, each null until given, and the values of the checkout fields that are no address member.
 *
 * Owns one fact: what a checkout session write replaces, what a method key may be, how many
 * values of the other checkout fields a session holds and in what document, and which details an
 * order cannot be placed without. A write sends all five, and one it leaves out is cleared. The
 * other fields' values (the order note, an extension's fields) are kept by field key, each a
 * non-empty string: an empty value is not given. A method key is a provider's name for a method,
 * such as `flat`: lower-case ASCII letters and digits with dots, colons, hyphens and underscores,
 * which is all its column can store unchanged.
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
	 * The most values of the other checkout fields a write may send.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_FIELD_VALUES = 32;

	/**
	 * The longest document of those values a session keeps, in bytes, as fieldValuesDocument() writes it.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const FIELD_VALUES_MAX_BYTES = 32768;

	/**
	 * Holds the details.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The values of the checkout fields that are no address member.
	 *
	 * @throws \InvalidArgumentException When a method key is not one (isMethodKey()), or a field's value is not a non-empty string.
	 *
	 * @param Address|null $billingAddress    The address the payment is billed to, or null.
	 * @param Address|null $shippingAddress   Where the order ships, or null.
	 * @param string|null  $shippingMethodKey The shipping method chosen, or null for the cheapest one quoted.
	 * @param string|null  $paymentMethodKey  The payment method chosen, or null.
	 * @param string[]     $fieldValues       Optional. The values of the checkout fields that are no
	 *                                        address member, each non-empty, by field key. Default none.
	 *
	 * @phpstan-param array<string, string> $fieldValues
	 */
	public function __construct(
		public ?Address $billingAddress,
		public ?Address $shippingAddress,
		public ?string $shippingMethodKey,
		public ?string $paymentMethodKey,
		public array $fieldValues = array()
	) {
		foreach ( $fieldValues as $value ) {
			if ( '' === $value ) {
				throw new \InvalidArgumentException( 'A checkout field\'s value is a non-empty string, kept by the field\'s key.' );
			}
		}

		foreach ( array( $shippingMethodKey, $paymentMethodKey ) as $key ) {
			if ( null !== $key && ! self::isMethodKey( $key ) ) {
				throw new \InvalidArgumentException( sprintf( 'A method key is 1 to %d lower-case ASCII letters, digits, dots, colons, hyphens and underscores.', self::METHOD_KEY_MAX_LENGTH ) );
			}
		}
	}

	/**
	 * Lists the details an order cannot be placed without and these lack: both addresses and a payment method.
	 *
	 * The shipping method may be left out: the cheapest rate quoted is the one charged. Which fields
	 * an address needs, the email address on the billing one among them, is the checkout's rules'
	 * to say (CheckoutRules::forPlacement()).
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The billing address's email address is the checkout's rules' to ask for.
	 *
	 * @return list<string> The missing details, by wire name: `billing_address`, `shipping_address`
	 *                      and `payment_method_key`; none when the details are all given.
	 */
	public function missingForPlacement(): array {
		$missing = array();

		if ( null === $this->billingAddress ) {
			$missing[] = 'billing_address';
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
	 * Returns the document a session keeps the values of the other checkout fields in: a JSON object of each field's key and its value, its characters written as they are.
	 *
	 * The session write measures a write's values by it, against FIELD_VALUES_MAX_BYTES, and the
	 * session's store writes it.
	 *
	 * @since 0.2.0
	 *
	 * @throws \JsonException When a value is not valid UTF-8, which the checkout's rules refuse first.
	 *
	 * @param string[] $values The values, by field key.
	 * @return string The document, or an empty string for none.
	 *
	 * @phpstan-param array<string, string> $values
	 */
	public static function fieldValuesDocument( array $values ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Domain code calls no WordPress function; a value the rules accepted is valid UTF-8, and anything else throws.
		return array() === $values ? '' : json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
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
