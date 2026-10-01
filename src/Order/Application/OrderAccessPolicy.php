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
 * Showing an order costs five reads: the access check's one, then the order's four, whatever the
 * number of its lines. A refusal costs the access check's read alone.
 *
 * @since 0.1.0
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
		$access = $this->orders->findForAccess( $orderUuid );

		if ( null === $access || ! $this->grants( $access, $actor, $key ) ) {
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
	 * Tells whether the actor may see the order: as its customer, or with its key.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderAccess $access The order's customer and key.
	 * @param Actor       $actor  Who asks.
	 * @param string|null $key    The presented key, or null.
	 * @return bool True when either test passes.
	 */
	private function grants( OrderAccess $access, Actor $actor, ?string $key ): bool {
		return $this->isCustomer( $access, $actor ) || $this->keyOpens( $access, $key );
	}

	/**
	 * Tells whether the actor is the customer the order belongs to.
	 *
	 * An order without a customer belongs to nobody: an actor who is no customer is not its owner,
	 * although both have none.
	 *
	 * @since 0.1.0
	 *
	 * @param OrderAccess $access The order's customer.
	 * @param Actor       $actor  Who asks.
	 * @return bool True when the order has a customer and the actor is that customer.
	 */
	private function isCustomer( OrderAccess $access, Actor $actor ): bool {
		return null !== $access->customerId && $access->customerId === $this->customers->customerOf( $actor );
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
