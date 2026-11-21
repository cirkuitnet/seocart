<?php
/**
 * CheckoutFieldRegistry: where an extension registers a checkout field
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Checkout;

defined( 'ABSPATH' ) || exit;

/**
 * The registry an extension is handed when the checkout first needs its fields.
 *
 * Owns one fact: how an extension's field joins the checkout. The plugin fires the action ACTION
 * at most once a request, the first time something needs the checkout's fields (a session write,
 * a placement, the checkout form, the store's field list, an order's field values), never on a
 * request that needs none, with this registry as its one argument. An extension checks the
 * contract and registers its fields:
 *
 *     add_action( 'seocart_register_checkout_fields', static function ( $registry ) {
 *         if ( $registry->supportsContract( '0.2.0' ) ) {
 *             $registry->register( 'acme', new CheckoutField( key: 'po_number', … ) );
 *         }
 *     } );
 *
 * A field is stored under the key `{extensionId}_{key}`, `acme_po_number` above, which every
 * surface names it by: the form, the store's field list, the session write's `custom_fields` and
 * the order. Its value is personal data, as every checkout value is, and its presence is the
 * store's choice among those it allows, as for SEOCart's own fields.
 *
 * Registering is cheap and never fatal: a field the registry refuses is logged once, listed by
 * `wp seocart doctor`, and left out; the first registration of a key wins. Refused: a 21st field,
 * a field of the `address` section, a type that is not text, textarea, select or checkbox, a key
 * that is one of SEOCart's own fields or a member of the session write, a stored key that starts
 * `seocart_` or `custom_`, a key registered already, and a registration after the action ran.
 * An extension's field has no autocomplete token: only SEOCart's own fields name one.
 *
 * Experimental until SEOCart 1.0: a method may still be added.
 *
 * @since 0.2.0
 *
 * @api
 */
interface CheckoutFieldRegistry {

	/**
	 * The action the plugin fires to have checkout fields registered; its one argument is the registry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACTION = 'seocart_register_checkout_fields';

	/**
	 * The version of the checkout field contract this plugin implements: this interface and CheckoutField.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CONTRACT_VERSION = '0.2.0';

	/**
	 * The most fields extensions may register in a store, together.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	public const MAX_FIELDS = 20;

	/**
	 * Tells whether this plugin supports an extension written against a version of the checkout field contract.
	 *
	 * Before 1.0 the major and minor versions must equal CONTRACT_VERSION's; from 1.0, the major
	 * versions must be equal and the extension's minor no later than the plugin's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $version The version the extension was written against, `major.minor` or `major.minor.patch`.
	 * @return bool True when the plugin supports it.
	 */
	public function supportsContract( string $version ): bool;

	/**
	 * Registers a field, while the action runs, under the key `{extensionId}_{key}`.
	 *
	 * A field the registry refuses is logged once and left out, never an error.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $extensionId The extension's id: lower-case snake_case, such as `acme`.
	 * @param CheckoutField $field       The field, its key without the extension's id.
	 */
	public function register( string $extensionId, CheckoutField $field ): void;
}
