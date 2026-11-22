<?php
/**
 * AccountOrderRead: answers a customer's read of one of their own orders, with its addresses
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Interfaces\StoreApi;

use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Address;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * The service behind `order.get_own`.
 *
 * Owns one fact: what the owner's read answers. OrderAccessPolicy decides with the owner test
 * alone: the service presents no key, whatever the request carries, so a key opens no address.
 * The policy's read of the order holds its addresses already, so the answer costs no read of its
 * own: the status read's members (OrderStatusRead::statusOf()), then each address as the checkout
 * names its members. It writes nothing and sets no cookie.
 *
 * @since 0.2.0
 */
final class AccountOrderRead {

	/**
	 * Decides who may see an order, and reads it.
	 *
	 * @since 0.2.0
	 *
	 * @var OrderAccessPolicy
	 */
	private OrderAccessPolicy $policy;

	/**
	 * Names an address's members on the wire, as the checkout names them.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(Address): array<string, string>
	 */
	private \Closure $address;

	/**
	 * Creates the service. Sends nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param OrderAccessPolicy $policy  Decides who may see an order, and reads it.
	 * @param \Closure          $address Names an address's members on the wire, as the checkout names them.
	 *
	 * @phpstan-param \Closure(Address): array<string, string> $address
	 */
	public function __construct( OrderAccessPolicy $policy, \Closure $address ) {
		$this->policy  = $policy;
		$this->address = $address;
	}

	/**
	 * Returns one of the actor's own orders: its number, statuses, totals and lines, and its addresses.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `order.not_found` for every refusal: no such order, a guest's order,
	 *                        another customer's, and every order for an actor who is no customer.
	 *
	 * @param array<string, mixed> $input The prepared input: the order's uuid.
	 * @param Actor                $actor The user WordPress authenticated the request as, 0 for a visitor.
	 * @return array<string, mixed> The order, keyed by wire name.
	 */
	public function read( array $input, Actor $actor ): array {
		$order = $this->policy->authorize( (string) $input['uuid'], $actor, null );

		return OrderStatusRead::statusOf( $order ) + array(
			'billing_address'  => ( $this->address )( $order->billingAddress ),
			'shipping_address' => null === $order->shippingAddress ? null : ( $this->address )( $order->shippingAddress ),
		);
	}
}
