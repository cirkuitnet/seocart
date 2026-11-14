<?php
/**
 * OrderAccessPolicy: who may see an order on a storefront
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Order\Domain\AccessKeys;
use SEOCart\Order\Domain\OrderAccess;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderSummary;
use SEOCart\Order\Domain\OrderView;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an actor may see an order without a capability, and reads the order for them.
 *
 * Owns one fact: who may see an order on a storefront. Access is granted when the order belongs
 * to a customer and the actor is that customer, or when the actor presents the order's access key
 * and the key has not expired. Nothing else grants it. Being logged in exempts nobody from either
 * test, and an order that belongs to no customer belongs to no actor, a visitor included. Every
 * storefront surface that shows an order asks this one policy, so there is no logged-in path to
 * drift apart from a guest's.
 *
 * Every refusal is the same `order.not_found`: no such order, another customer's order, a wrong
 * key and an expired key look alike, so an answer never confirms that an order exists. Whether a
 * key has expired is the database clock's answer, read with the order's customer and the key's
 * hash. The presented key is compared with that hash only by AccessKeys::verify(), in constant
 * time.
 *
 * The actor's customer is learned before the order is read, whatever the order turns out to be,
 * so what a request costs never says whether an order exists or whom it belongs to. Showing an
 * order costs six reads for a logged-in actor: the actor's customer, the access check, then the
 * order's four, whatever the number of its lines; a refusal costs the first two. A visitor is no
 * customer and is asked nothing: five reads, and one for a refusal.
 *
 * The same owner test lists a customer's own orders (ownOrders()): a key opens one order, never a
 * list, so an actor who is no customer is listed nothing.
 *
 * @since 0.1.0
 * @since 0.2.0 Learns the actor's customer before the order; lists a customer's own orders.
 */
final class OrderAccessPolicy {

	/**
	 * The order statements.
	 *
	 * @since 0.1.0
	 *
	 * @var OrderRepository
	 */
	private OrderRepository $orders;

	/**
	 * Checks a presented key against an order's hash.
	 *
	 * @since 0.1.0
	 *
	 * @var AccessKeys
	 */
	private AccessKeys $keys;

	/**
	 * Says which customer an actor is.
	 *
	 * @since 0.1.0
	 *
	 * @var ActorCustomers
	 */
	private ActorCustomers $customers;

	/**
	 * Creates the policy. Sends nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderRepository $orders    The order statements.
	 * @param AccessKeys      $keys      Checks a presented key against an order's hash.
	 * @param ActorCustomers  $customers Says which customer an actor is.
	 */
	public function __construct( OrderRepository $orders, AccessKeys $keys, ActorCustomers $customers ) {
		$this->orders    = $orders;
		$this->keys      = $keys;
		$this->customers = $customers;
	}

	/**
	 * Returns an order to an actor who may see it, and refuses everyone else alike.
	 *
	 * @since 0.1.0
	 *
	 * @throws CodedException `order.not_found` for every refusal: no order has the uuid, the actor
	 *                        is not its customer and presented no key that opens it, or the key
	 *                        has expired.
	 *
	 * @param string      $orderUuid The order's public identifier.
	 * @param Actor       $actor     Who asks.
	 * @param string|null $key       The access key the actor presented, or null for none.
	 * @return OrderView The order.
	 */
	public function authorize( string $orderUuid, Actor $actor, ?string $key ): OrderView {
		$customer = $this->customers->customerOf( $actor );
		$access   = $this->orders->findForAccess( $orderUuid );

		if ( null === $access || ! $this->grants( $access, $customer, $key ) ) {
			CodedException::raise( OrderError::NotFound );
		}

		$order = $this->orders->findByUuid( $orderUuid );

		// An order deleted between the two reads is refused like any other.
		if ( null === $order ) {
			CodedException::raise( OrderError::NotFound );
		}

		return $order;
	}

	/**
	 * Returns a page of the orders of the customer an actor is, newest first, leaving out the declined attempts; a customer's orders and nobody else's.
	 *
	 * An actor who is no customer, a visitor or anyone presenting a key, is listed nothing, and no
	 * order is read. A customer costs two reads: their customer, and the page.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor   Who asks.
	 * @param int   $page    The page, from 1.
	 * @param int   $perPage How many orders a page holds, from 1.
	 * @return array{orders: list<OrderSummary>, has_more: bool} The page, and whether another follows it.
	 */
	public function ownOrders( Actor $actor, int $page, int $perPage ): array {
		$customer = $this->customers->customerOf( $actor );

		if ( null === $customer ) {
			return array(
				'orders'   => array(),
				'has_more' => false,
			);
		}

		// One more than the page holds tells whether another page follows, in the same read.
		$orders = $this->orders->ownOrders( $customer, ( max( 1, $page ) - 1 ) * $perPage, $perPage + 1 );

		return array(
			'orders'   => array_slice( $orders, 0, $perPage ),
			'has_more' => count( $orders ) > $perPage,
		);
	}

	/**
	 * Tells whether the actor may see the order: as its customer, or with its key.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Takes the actor's customer, learned before the order was read.
	 *
	 * @param OrderAccess $access   The order's customer and key.
	 * @param int|null    $customer The customer the actor is, or null.
	 * @param string|null $key      The presented key, or null.
	 * @return bool True when either test passes.
	 */
	private function grants( OrderAccess $access, ?int $customer, ?string $key ): bool {
		return self::isCustomer( $access, $customer ) || $this->keyOpens( $access, $key );
	}

	/**
	 * Tells whether the actor is the customer the order belongs to.
	 *
	 * An order without a customer belongs to nobody: an actor who is no customer is not its owner,
	 * although both have none.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Compares the customer the actor was found to be.
	 *
	 * @param OrderAccess $access   The order's customer.
	 * @param int|null    $customer The customer the actor is, or null.
	 * @return bool True when the order has a customer and the actor is that customer.
	 */
	private static function isCustomer( OrderAccess $access, ?int $customer ): bool {
		return null !== $access->customerId && $access->customerId === $customer;
	}

	/**
	 * Tells whether a presented key opens the order: it is the order's key, and it has not expired.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderAccess $access The order's key hash and whether the key has expired.
	 * @param string|null $key    The presented key, or null.
	 * @return bool True when the key verifies against the order's hash and is still in its time.
	 */
	private function keyOpens( OrderAccess $access, ?string $key ): bool {
		return null !== $key
			&& null !== $access->accessKeyHash
			&& $this->keys->verify( $key, $access->accessKeyHash )
			&& ! $access->keyExpired;
	}
}
