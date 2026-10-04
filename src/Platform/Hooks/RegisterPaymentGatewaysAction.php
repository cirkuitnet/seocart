<?php
/**
 * RegisterPaymentGatewaysAction: the action a gateway plugin registers its payment gateway on
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Hooks;

use SEOCart\Contracts\Payment\GatewayRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Fires when the store first needs its payment gateways, so a gateway plugin can register its gateway.
 *
 * The listener receives the gateway registry, checks the contract version it was written
 * against, asks for its context and registers its gateway; a gateway the registry refuses is
 * logged and left out, never fatally. The action never fires on a request that needs no gateway,
 * so a gateway plugin costs an idle request only its own add_action() call.
 *
 * @since 0.2.0
 */
final class RegisterPaymentGatewaysAction implements ActionDeclaration {

	/**
	 * The action's name, as the payment contract declares it for gateway plugins.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const NAME = GatewayRegistry::ACTION;

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
		return 'Once per request, the first time something needs a payment gateway: a checkout, a placement, a capture, a refund or a reconciliation run; never on a request that needs none.';
	}

	/**
	 * Returns the argument a listener receives: the gateway registry.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> The registry's interface, keyed `registry`.
	 */
	public static function arguments(): array {
		return array( 'registry' => GatewayRegistry::class );
	}
}
