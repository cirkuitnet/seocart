<?php
/**
 * GatewayRegistry: where a gateway plugin registers its gateway
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Contracts\Payment;

use SEOCart\Contracts\ExtensionContext;

defined( 'ABSPATH' ) || exit;

/**
 * The registry a gateway plugin is handed when the plugin first needs its gateways.
 *
 * Owns one fact: how a gateway joins the store. The plugin fires the action ACTION once per
 * request, the first time something needs a gateway (a placement, a capture, a refund, a
 * reconciliation run), never on a request that needs none, with this registry as its one
 * argument. A gateway plugin checks the contract, asks for its context and registers its gateway:
 *
 *     add_action( 'seocart_register_payment_gateways', static function ( $registry ) {
 *         if ( $registry->supportsContract( '0.2.0' ) ) {
 *             $registry->register( new Gateway( $registry->context( 'example' ) ) );
 *         }
 *     } );
 *
 * The plugin's main file names the action as a plain string: WordPress loads a gateway plugin
 * before this one, so nothing of this plugin exists yet when that file runs, and naming nothing of
 * it keeps an idle request from loading this plugin's files for it. ACTION is the one declaration
 * of the name, which the hooks reference documents.
 *
 * Registering is cheap and never fatal: a gateway the registry refuses is logged and left out.
 *
 * @since 0.2.0
 *
 * @api
 */
interface GatewayRegistry {

	/**
	 * The action the plugin fires to have gateways registered; its one argument is the registry.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const ACTION = 'seocart_register_payment_gateways';

	/**
	 * Tells whether this plugin supports a gateway written against a version of the payment contract.
	 *
	 * Before 1.0 the major and minor versions must equal PaymentGateway::CONTRACT_VERSION's; from
	 * 1.0, the major versions must be equal and the gateway's minor no later than the plugin's.
	 *
	 * @since 0.2.0
	 *
	 * @param string $writtenAgainst The version, `major.minor` or `major.minor.patch`.
	 * @return bool True when a gateway written against it can be registered.
	 */
	public function supportsContract( string $writtenAgainst ): bool;

	/**
	 * Returns the context a gateway is built with: its logger, clock, settings and outbound client.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When the id is not a gateway id (GatewayDescriptor::ID_PATTERN).
	 *
	 * @param string $gatewayId The id the gateway describes itself with.
	 * @return ExtensionContext The context, one per id.
	 */
	public function context( string $gatewayId ): ExtensionContext;

	/**
	 * Registers a gateway, while the action runs.
	 *
	 * A gateway is refused, with a line in the plugin's log and never an error, when its id is
	 * registered already, it was written against a contract the plugin does not support, its
	 * descriptor or its settings are not well formed, or it is registered after the action ran.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 */
	public function register( PaymentGateway $gateway ): void;
}
