<?php
/**
 * Carrier: where a checkout field's value travels on the wire
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain\Fields;

use SEOCart\Checkout\Domain\AddressDocument;
use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\Section;

defined( 'ABSPATH' ) || exit;

/**
 * The member of the session write that carries a field's value: each address, the billing address alone, or the custom fields.
 *
 * Owns one fact: where a field's value is sent, read from its declaration. An `address` field is
 * a member of both addresses. A field of another section whose key names an address member, the
 * email address, is a member of the billing address alone, where the order and the payment read
 * it. Every other field rides in the custom fields. The validation, the placement's rules and the
 * form the storefront renders all name a value by the paths this gives.
 *
 * @since 0.2.0
 */
enum Carrier: string {

	/**
	 * A member of the billing and of the shipping address.
	 *
	 * @since 0.2.0
	 */
	case Address = 'address';

	/**
	 * A member of the billing address alone.
	 *
	 * @since 0.2.0
	 */
	case BillingAddress = 'billing_address';

	/**
	 * An entry of the custom fields.
	 *
	 * @since 0.2.0
	 */
	case CustomFields = 'custom_fields';

	/**
	 * The wire name of the billing address.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const BILLING = 'billing_address';

	/**
	 * The wire name of the shipping address.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SHIPPING = 'shipping_address';

	/**
	 * Returns the carrier of a field.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return self Address for an `address` field, BillingAddress for another field named as an
	 *              address member, CustomFields for any other.
	 */
	public static function of( CheckoutField $field ): self {
		if ( Section::Address === $field->section ) {
			return self::Address;
		}

		return isset( AddressDocument::FIELDS[ $field->key ] ) ? self::BillingAddress : self::CustomFields;
	}

	/**
	 * Returns the addresses that carry a field as one of their members, by wire name.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutField $field The field.
	 * @return list<string> Both addresses, the billing address, or none for a custom field.
	 */
	public static function addressesOf( CheckoutField $field ): array {
		return match ( self::of( $field ) ) {
			self::Address        => array( self::BILLING, self::SHIPPING ),
			self::BillingAddress => array( self::BILLING ),
			self::CustomFields   => array(),
		};
	}
}
