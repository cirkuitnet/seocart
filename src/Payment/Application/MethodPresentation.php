<?php
/**
 * MethodPresentation: what a shopper is shown of a payment method
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

defined( 'ABSPATH' ) || exit;

/**
 * A payment method as a shopper is shown it: its id, its name, what they are told to do to pay, and whether they pay when the order is delivered.
 *
 * Owns one fact: what a confirmation, an email or a fulfilment screen reads of a payment method.
 * Nothing in it is a secret.
 *
 * @since 0.2.0
 */
final readonly class MethodPresentation {

	/**
	 * Records the presentation.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id             The gateway's id.
	 * @param string $label          Its name for shoppers, translated.
	 * @param string $instructions   What the shopper is told to do to pay, plain text; empty for a provider's method.
	 * @param bool   $paysOnDelivery Whether the shopper pays when the order is delivered, so it may be dispatched before it is paid.
	 */
	public function __construct(
		public string $id,
		public string $label,
		public string $instructions,
		public bool $paysOnDelivery
	) {
	}
}
