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
 * The fixture extension `acme`: a purchase order number (text, with its own check, which throws on a number it cannot read), a delivery slot (select) and gift wrapping (checkbox), registered on `seocart_register_checkout_fields` through the public contract alone.
 *
 * Owns one fact: what a third party's registration looks like, which the tests walk through every
 * surface and the extension guide quotes. The registration is what an extension's main file
 * holds; listen() and forget() stand for activating and deactivating it. relabel() changes its
 * words, as a new translation or a new version of the extension would.
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
	 * The words relabel() changed, each by the text the registration translates.
	 *
	 * @var array<string, string>
	 */
	private static array $words = array();

	/**
	 * Activates the extension: its main file's add_action() call.
	 *
	 * @since 0.2.0
	 */
	public static function listen(): void {
		add_action( 'seocart_register_checkout_fields', array( self::class, 'register' ) );
	}

	/**
	 * Deactivates the extension: its fields are no longer registered.
	 *
	 * @since 0.2.0
	 */
	public static function forget(): void {
		remove_action( 'seocart_register_checkout_fields', array( self::class, 'register' ) );
		remove_filter( 'gettext', array( self::class, 'translate' ) );

		self::$words = array();
	}

	/**
	 * Changes the extension's words, as a new translation or a new version of it would: each text it translates becomes another.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $words The new words, by the text the registration translates.
	 */
	public static function relabel( array $words ): void {
		self::$words = $words;

		add_filter( 'gettext', array( self::class, 'translate' ), 10, 3 );
	}

	/**
	 * Answers the changed words of the extension's text domain.
	 *
	 * @since 0.2.0
	 *
	 * @param string $translation The translation so far.
	 * @param string $text        The text translated.
	 * @param string $domain      Its text domain.
	 * @return string The translation.
	 */
	public static function translate( string $translation, string $text, string $domain ): string {
		return 'acme-checkout' === $domain ? ( self::$words[ $text ] ?? $translation ) : $translation;
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
				check: static function ( string $value ): ?string {
					if ( str_contains( $value, '?' ) ) {
						// A check may throw: SEOCart refuses the value and logs the field and the exception's class, never this message.
						throw new \UnexpectedValueException( sprintf( 'Cannot read the purchase order number %s.', $value ) );
					}

					return str_starts_with( $value, 'PO-' ) ? null : __( 'A purchase order number starts with PO-.', 'acme-checkout' );
				}
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
					'morning'   => static fn(): string => __( 'Morning', 'acme-checkout' ),
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
