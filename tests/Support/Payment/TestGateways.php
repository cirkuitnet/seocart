<?php
/**
 * TestGateways: a gateway registry over one gateway, for the tests that build the payment services by hand
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support\Payment;

use SEOCart\Contracts\Payment\PaymentGateway;
use SEOCart\Payment\Application\GatewayContext;
use SEOCart\Payment\Application\Gateways;

/**
 * Builds the registry the payment services find their gateways in, holding one gateway with no settings.
 *
 * Owns one fact: what the hand-built payment tests replace of the registry. The gateway given is
 * registered as the stand-in is, first; a gateway without settings needs no context, so asking for
 * one is a mistake of the test, and so is a registration the registry refuses, which fails the test
 * at once.
 *
 * @since 0.2.0
 */
final class TestGateways {

	/**
	 * Builds the registry.
	 *
	 * @since 0.2.0
	 *
	 * @param PaymentGateway $gateway The gateway, such as the stub, or a double wrapping it.
	 * @return Gateways The registry.
	 */
	public static function of( PaymentGateway $gateway ): Gateways {
		return new Gateways(
			$gateway,
			static function ( string $gatewayId ): GatewayContext {
				throw new \LogicException( sprintf( 'A test registry gives no context, and the gateway %s was asked for one.', $gatewayId ) );
			},
			static function ( string $code, array $context ): void {
				throw new \LogicException( sprintf( 'The test registry reported %1$s: %2$s', $code, (string) wp_json_encode( $context ) ) );
			},
			static function (): void {
			}
		);
	}
}
