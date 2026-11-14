<?php
/**
 * PaymentIslands: the scripts of the payment islands a page that shows the checkout's payment methods loads
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Interfaces\Storefront;

use SEOCart\Payment\Application\Gateways;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- This exception reports a gateway plugin's mistake to its developer; it is never HTML.

/**
 * Enqueues a gateway plugin's island script on a page that offers its payment method: what a listener of seocart_enqueue_payment_islands receives.
 *
 * Owns one fact: how an island's script is loaded. It is a classic script, deferred and printed in
 * the footer, so it runs after the page is parsed and registers with the checkout's payment
 * registry whether it runs before or after the checkout's own module; the registry takes it either
 * way. A gateway the page does not offer loads nothing, so a store's pages carry the scripts of
 * the methods they show and no other. An id no gateway registered with is refused, since an island
 * for it could never be shown and a mistyped id would otherwise fail without a word.
 *
 * The page that shows the payment methods fires the action with one of these, naming the methods it
 * offers (EnqueuePaymentIslandsAction).
 *
 * @since 0.2.0
 */
final class PaymentIslands {

	/**
	 * Creates the islands of one page. Enqueues nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param Gateways $gateways The store's payment gateways, which say what ids are registered.
	 * @param array    $offered  The ids of the payment methods the page offers.
	 *
	 * @phpstan-param list<string> $offered
	 */
	public function __construct( private Gateways $gateways, private array $offered ) {
	}

	/**
	 * Enqueues a gateway's island script, when the page offers its payment method.
	 *
	 * @since 0.2.0
	 *
	 * @throws \InvalidArgumentException When no gateway registered with the id.
	 *
	 * @param string $gatewayId The gateway's id, as it registered.
	 * @param string $handle    The script's handle, unique to the plugin, such as `my-gateway-island`.
	 * @param string $src       The script's URL, such as plugins_url( 'assets/island.js', __FILE__ ).
	 * @param string $version   The script's version, such as the plugin's, which the URL carries.
	 */
	public function add( string $gatewayId, string $handle, string $src, string $version ): void {
		if ( ! in_array( $gatewayId, $this->gateways->ids(), true ) ) {
			throw new \InvalidArgumentException( sprintf( 'No payment gateway "%s" is registered, so the page has no island of it to load: pass the id the gateway registered with.', $gatewayId ) );
		}

		if ( ! in_array( $gatewayId, $this->offered, true ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			$src,
			array(),
			$version,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
