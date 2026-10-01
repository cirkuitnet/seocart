<?php
/**
 * CheckoutSession: a cart's checkout, as stored
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Domain;

use SEOCart\Support\Address;

defined( 'ABSPATH' ) || exit;

/**
 * A cart's checkout session: the shopper's details, and the quotes frozen at a version of the cart.
 *
 * Owns one fact: when a session's quotes may still be used. They were taken at
 * quotedAtCartVersion; every accepted write moves the cart's version on, so once the cart is at
 * any other version the quotes are stale and the next calculation quotes again. A session whose
 * quotes were invalidated, by a write of its details or a change of the cart's currency, is at
 * version 0 and has none.
 *
 * @since 0.1.0
 */
final readonly class CheckoutSession {

	/**
	 * Holds the session.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $cartId              The cart the session belongs to.
	 * @param CheckoutDetails   $details             The shopper's addresses and methods.
	 * @param int               $quotedAtCartVersion The cart's version the quotes were taken at; 0 when they were invalidated.
	 * @param FrozenQuotes|null $quotes              The quotes, or null when there are none.
	 */
	public function __construct(
		public int $cartId,
		public CheckoutDetails $details,
		public int $quotedAtCartVersion,
		public ?FrozenQuotes $quotes
	) {
	}

	/**
	 * Returns what a cart's calculation needs of its session: where the order ships, and the shipping method chosen.
	 *
	 * @since 0.1.0
	 *
	 * @param CheckoutSession|null $session The cart's session, or null when it has none.
	 * @return array{destination: Address|null, shipping_method_key: string|null} Each null until the shopper gives it.
	 */
	public static function deliveryOf( ?self $session ): array {
		return array(
			'destination'         => $session?->details->shippingAddress,
			'shipping_method_key' => $session?->details->shippingMethodKey,
		);
	}

	/**
	 * Tells whether the session's quotes may be used for the cart at a version.
	 *
	 * @since 0.1.0
	 *
	 * @param int $cartVersion The cart's version now.
	 * @return bool True when the session has quotes, taken at exactly that version.
	 */
	public function quotesAreCurrentAt( int $cartVersion ): bool {
		return null !== $this->quotes && $cartVersion === $this->quotedAtCartVersion;
	}
}
