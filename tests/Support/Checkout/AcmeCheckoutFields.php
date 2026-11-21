<?php
/**
 * AcmeCheckoutFields: an extension that registers its checkout fields, as a third party would
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Checkout;

use SEOCart\Contracts\Checkout\CheckoutField;
use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;
use SEOCart\Contracts\Checkout\CheckoutFieldType;
use SEOCart\Contracts\Checkout\Presence;
use SEOCart\Contracts\Checkout\Section;

// phpcs:disable WordPress.WP.I18n.TextDomainMismatch -- The fixture stands for a third party's extension, which translates its words in its own text domain.

/**
 * The fixture extension `acme`: a purchase order number (text, with its own check), a delivery slot (select) and gift wrapping (checkbox), registered on `seocart_register_checkout_fields` through the public contract alone.
 *
 * Owns one fact: what a third party's registration looks like, which the tests walk through every
 * surface and the extension guide quotes. The registration is what an extension's main file
 * holds; listen() and forget() stand for activating and deactivating it. Its option labels are
 * changed with relabel(), as a new version of the extension would change them.
 *
 * @since 0.2.0
 */
final class AcmeCheckoutFields {

	/**
	 * The extension's id: its fields are stored as `acme_{key}`.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ID = 'acme';

	/**
	 * The version of the checkout field contract the extension was written against.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRACT = '0.2.0';

	/**
	 * The stored key of the purchase order number.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PO_NUMBER = 'acme_po_number';

	/**
	 * The stored key of the delivery slot.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const DELIVERY_SLOT = 'acme_delivery_slot';

	/**
	 * The stored key of the gift wrapping.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const GIFT_WRAP = 'acme_gift_wrap';

	/**
	 * The sentence the purchase order number's check answers for a number it refuses.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const PO_REFUSAL = 'A purchase order number starts with PO-.';

	/**
	 * The label of the morning slot, as relabel() last set it.
	 *
	 * @var string
	 */
	private static string $morning = 'Morning';

	/**
	 * Activates the extension: its main file's add_action() call.
	 *
	 * @since 0.2.0
	 */
	public static function listen(): void {
		self::$morning = 'Morning';

		add_action( 'seocart_register_checkout_fields', array( self::class, 'register' ) );
	}

	/**
	 * Deactivates the extension: its fields are no longer registered.
	 *
	 * @since 0.2.0
	 */
	public static function forget(): void {
		remove_action( 'seocart_register_checkout_fields', array( self::class, 'register' ) );
	}

	/**
	 * Changes the morning slot's label, as a new version of the extension would.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The new label.
	 */
	public static function relabel( string $label ): void {
		self::$morning = $label;
	}

	/**
	 * Registers the extension's fields, when the store supports the contract it was written against.
	 *
	 * @since 0.2.0
	 *
	 * @param CheckoutFieldRegistry $registry The checkout field registry.
	 */
	public static function register( CheckoutFieldRegistry $registry ): void {
		if ( ! $registry->supportsContract( self::CONTRACT ) ) {
			return;
		}

		$registry->register(
			self::ID,
			new CheckoutField(
				key: 'po_number',
				section: Section::Order,
				type: CheckoutFieldType::Text,
				label: static fn(): string => __( 'Purchase order number', 'acme-checkout' ),
				maxLength: 20,
				presences: array( Presence::Optional, Presence::Required, Presence::Hidden ),
				help: static fn(): string => __( 'For an order your company pays by invoice.', 'acme-checkout' ),
				check: static fn( string $value ): ?string => str_starts_with( $value, 'PO-' ) ? null : __( 'A purchase order number starts with PO-.', 'acme-checkout' )
			)
		);
		$registry->register(
			self::ID,
			new CheckoutField(
				key: 'delivery_slot',
				section: Section::Order,
				type: CheckoutFieldType::Select,
				label: static fn(): string => __( 'Delivery slot', 'acme-checkout' ),
				maxLength: 20,
				presences: array( Presence::Optional, Presence::Required, Presence::Hidden ),
				options: array(
					'morning'   => static fn(): string => self::$morning,
					'afternoon' => static fn(): string => __( 'Afternoon', 'acme-checkout' ),
				)
			)
		);
		$registry->register(
			self::ID,
			new CheckoutField(
				key: 'gift_wrap',
				section: Section::Order,
				type: CheckoutFieldType::Checkbox,
				label: static fn(): string => __( 'Wrap it as a gift', 'acme-checkout' ),
				maxLength: 1,
				presences: array( Presence::Optional, Presence::Hidden )
			)
		);
	}
}
