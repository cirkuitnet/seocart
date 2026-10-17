<?php
/**
 * DeclarationRegistry: a gateway registry that keeps the gateways registered with it, outside WordPress
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Conformance\Payment;

use SEOCart\Contracts\ExtensionContext;
use SEOCart\Contracts\Payment\GatewayRegistry;
use SEOCart\Contracts\Payment\PaymentGateway;

/**
 * The registry an extension's registration listener is run against when only its gateways' declarations are wanted: the conformance suite's unit rules and the capabilities page.
 *
 * Owns one fact: which gateways a listener registers. It checks nothing (the plugin's own registry
 * does, under WordPress) and hands each gateway an InertContext.
 *
 * @since 0.2.0
 */
final class DeclarationRegistry implements GatewayRegistry {

	/**
	 * The gateways registered, in order.
	 *
	 * @since 0.2.0
	 *
	 * @var list<PaymentGateway>
	 */
	private array $gateways = array();

	/**
	 * Runs registration listeners and returns what they registered.
	 *
	 * @since 0.2.0
	 *
	 * @param callable ...$listeners The listeners an extension added to GatewayRegistry::ACTION.
	 * @return list<PaymentGateway> The gateways, in the order they were registered.
	 */
	public static function registeredBy( callable ...$listeners ): array {
		$registry = new self();

		foreach ( $listeners as $listener ) {
			$listener( $registry );
		}

		return $registry->gateways;
	}

	/**
	 * Answers yes: the plugin's own registry judges the version, under WordPress.
	 *
	 * @since 0.2.0
	 *
	 * @param string $writtenAgainst The version.
	 * @return bool True.
	 */
	public function supportsContract( string $writtenAgainst ): bool {
		unset( $writtenAgainst );

		return true;
	}

	/**
	 * Returns a context that names the gateway and refuses everything else.
	 *
	 * @since 0.2.0
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return ExtensionContext The context.
	 */
	public function context( string $gatewayId ): ExtensionContext {
		return new InertContext( $gatewayId );
	}

	/**
	 * Keeps a gateway.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway.
	 */
	public function register( PaymentGateway $gateway ): void {
		$this->gateways[] = $gateway;
	}
}
