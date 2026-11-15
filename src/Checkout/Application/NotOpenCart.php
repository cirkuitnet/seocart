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
use SEOCart\Order\Application\ActorCustomers;
use SEOCart\Order\Application\Orders;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout's answer to a write on a cart that is no longer open.
 *
 * Owns one fact: how the checkout names the order of a cart it cannot change. The cart's own
 * refusal, `cart.not_open`, names only the cart's status, because the cart knows its order by an
 * internal id that never leaves the store. The checkout may read the order, so its refusal also
 * names it the way a shopper's client reads it, by its uuid and its status, but only to someone
 * who may read that order: anyone holding the cart's token, for an order without a customer, as
 * its key's holder is; and the order's customer, for an order that has one. Anyone else holding
 * the token, another account signed in on the same browser or a visitor, is told the cart's
 * status alone. Whom to name is decided from the order's row, read anyway; the requester's
 * customer is asked only when the requester is the user who placed the order.
 *
 * @since 0.1.0
 * @since 0.2.0 Names a customer's order to that customer alone.
 */
final class NotOpenCart {

	/**
	 * Cannot be called: the refusal is a static function.
	 *
	 * @since 0.1.0
	 */
	private function __construct() {}

	/**
	 * Refuses a write to a cart that is placing or has placed an order, naming the order and its status to someone who may read it.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Names a customer's order to that customer alone.
	 *
	 * @throws CodedException Always: `cart.not_open`, with the cart's status, and in its details the
	 *                        order's uuid and status when the cart names an order that exists and the
	 *                        requester may read it.
	 *
	 * @param Cart           $cart      The cart, as read: not open.
	 * @param Orders         $orders    Reads the order's uuid, status, customer and placer.
	 * @param Actor          $actor     Who sent the write.
	 * @param ActorCustomers $customers Says which customer the requester is.
	 * @return never
	 */
	public static function refuse( Cart $cart, Orders $orders, Actor $actor, ActorCustomers $customers ): never {
		$order   = null === $cart->orderId ? null : $orders->statusOf( $cart->orderId );
		$details = null === $order || ! self::mayName( $order, $actor, $customers ) ? array() : array(
			'order_uuid'   => $order['uuid'],
			'order_status' => $order['status']->value,
		);

		CodedException::raise( CartError::NotOpen, array( 'status' => $cart->status->value ), $details );
	}

	/**
	 * Tells whether the requester may be told which order the cart is placing: anyone, for an order without a customer; its customer, for one with a customer.
	 *
	 * The requester's customer is asked only when they are the user who placed the order: nobody
	 * else can be its customer.
	 *
	 * @since 0.2.0
	 *
	 * @param array{customer_id: int|null, actor_id: int|null} $order     The order's customer and the user who placed it.
	 * @param Actor                                            $actor     Who sent the write.
	 * @param ActorCustomers                                   $customers Says which customer the requester is.
	 * @return bool True when the order may be named to the requester.
	 */
	private static function mayName( array $order, Actor $actor, ActorCustomers $customers ): bool {
		if ( null === $order['customer_id'] ) {
			return true;
		}

		return null === $actor->systemName()
			&& $actor->userId() >= 1
			&& $actor->userId() === $order['actor_id']
			&& $order['customer_id'] === $customers->customerOf( $actor );
	}
}
