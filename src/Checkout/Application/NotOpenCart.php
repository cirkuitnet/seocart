<?php
/**
 * NotOpenCart: refuses a write to a cart that is placing or has placed an order, naming the order
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Checkout\Application;

use SEOCart\Cart\Application\CartError;
use SEOCart\Cart\Domain\Cart;
use SEOCart\Order\Application\Orders;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout's answer to a write on a cart that is no longer open.
 *
 * Owns one fact: how the checkout names the order of a cart it cannot change. The cart's own
 * refusal, `cart.not_open`, names only the cart's status, because the cart knows its order by an
 * internal id that never leaves the store. The checkout may read the order, so its refusal also
 * names it the way a shopper's client reads it: by its uuid and its status.
 *
 * @since 0.1.0
 */
final class NotOpenCart {

	/**
	 * Cannot be called: the refusal is a static function.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Refuses a write to a cart that is placing or has placed an order, naming the order and its status.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException Always: `cart.not_open`, with the cart's status, and in its details the
	 *                        order's uuid and status when the cart names an order that exists.
	 *
	 * @param Cart   $cart   The cart, as read: not open.
	 * @param Orders $orders Reads the order's uuid and status.
	 * @return never
	 */
	public static function refuse( Cart $cart, Orders $orders ): never {
		$order   = null === $cart->orderId ? null : $orders->statusOf( $cart->orderId );
		$details = null === $order ? array() : array(
			'order_uuid'   => $order['uuid'],
			'order_status' => $order['status']->value,
		);

		CodedException::raise( CartError::NotOpen, array( 'status' => $cart->status->value ), $details );
	}
}
