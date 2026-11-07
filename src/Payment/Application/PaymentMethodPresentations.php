<?php
/**
 * PaymentMethodPresentations: what a shopper is shown of each payment method
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

// Before the imports: Plugin Check looks for this guard only in the first 50 lines of a namespaced file.
defined( 'ABSPATH' ) || exit;

use SEOCart\Contracts\Payment\Mode;
use SEOCart\Payment\Domain\OfflineMethod;
use SEOCart\Support\Error\CodedException;

/**
 * Reads how a registered gateway is shown to a shopper: its name, and for an offline payment method the instructions the merchant wrote.
 *
 * Owns one fact: where a payment method's name and instructions for shoppers come from. A
 * provider's method is shown by its descriptor's label and has no instructions. An offline payment
 * method is shown by the name the merchant configured, or its own name when none is, with the
 * instructions the merchant configured, from its one mode's settings: one read of its settings
 * document, which the object cache then keeps for the request, as availability read it already.
 * Read by the placement's answer; a confirmation email reads the same.
 *
 * @since 0.2.0
 */
final class PaymentMethodPresentations {

	/**
	 * Creates the reader. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways $gateways The registry, which holds each gateway's descriptor and settings.
	 */
	public function __construct( private Gateways $gateways ) {
	}

	/**
	 * Returns how a registered gateway is shown to a shopper.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `payment.gateway_unavailable` with the reason `not_registered`.
	 *
	 * @param string $gatewayId The gateway's id.
	 * @return MethodPresentation The presentation.
	 */
	public function of( string $gatewayId ): MethodPresentation {
		$descriptor = $this->gateways->descriptor( $gatewayId );
		$method     = OfflineMethod::tryFrom( $gatewayId );

		if ( null === $method ) {
			return new MethodPresentation( $gatewayId, $descriptor->label(), '', false );
		}

		// An offline payment method has one mode, live, whose settings are the ones the merchant writes.
		$settings = $this->gateways->context( $gatewayId )->settings( Mode::Live );
		$label    = (string) $settings->value( OfflineMethod::LABEL );

		return new MethodPresentation( $gatewayId, '' === $label ? $descriptor->label() : $label, (string) $settings->value( OfflineMethod::INSTRUCTIONS ), $method->paysOnDelivery() );
	}
}
