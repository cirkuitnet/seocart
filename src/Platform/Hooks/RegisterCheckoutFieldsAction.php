<?php
/**
 * RegisterCheckoutFieldsAction: the action an extension registers its checkout fields on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

use SEOCart\Contracts\Checkout\CheckoutFieldRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Fires when the checkout first needs its fields, so an extension can register its own.
 *
 * The listener receives the checkout field registry, checks the contract version it was written
 * against and registers its fields; a field the registry refuses is logged and left out, never
 * fatally. The action never fires on a request that needs no checkout field, so an extension
 * costs an idle request only its own add_action() call.
 *
 * @since 0.2.0
 */
final class RegisterCheckoutFieldsAction implements ActionDeclaration {

	/**
	 * The action's name, as the checkout field contract declares it for extensions.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = CheckoutFieldRegistry::ACTION;

	/**
	 * Returns the action's name.
	 *
	 * @since 0.2.0
	 *
	 * @return string NAME.
	 */
	public static function name(): string {
		return self::NAME;
	}

	/**
	 * Says when the action fires.
	 *
	 * @since 0.2.0
	 *
	 * @return string One sentence.
	 */
	public static function firesWhen(): string {
		return 'At most once per request, the first time something needs the checkout\'s fields: a session write, a placement, the checkout form, the store\'s field list or an order\'s field values; never on a request that needs none.';
	}

	/**
	 * Returns the argument a listener receives: the checkout field registry.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The registry's interface, keyed `registry`.
	 */
	public static function arguments(): array {
		return array( 'registry' => CheckoutFieldRegistry::class );
	}
}
