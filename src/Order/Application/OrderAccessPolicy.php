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
use SEOCart\Order\Domain\ClaimableOrder;
use SEOCart\Order\Domain\Machine;
use SEOCart\Order\Domain\OrderAccess;
use SEOCart\Order\Domain\OrderRepository;
use SEOCart\Order\Domain\OrderSummary;
use SEOCart\Order\Domain\OrderView;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Isolation;
use SEOCart\Platform\Database\RetryPolicy;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an actor may see an order without a capability, and reads the order for them; and decides who may make a guest's order theirs.
 *
 * Owns one fact: who may see an order on a storefront. Access is granted when the order belongs
 * to a customer and the actor is that customer, or when the actor presents the order's access key
 * and the key has not expired. Nothing else grants it. Being logged in exempts nobody from either
 * test, and an order that belongs to no customer belongs to no actor, a visitor included. Every
 * storefront surface that shows an order asks this one policy, so there is no logged-in path to
 * drift apart from a guest's.
 *
 * It also owns the one way a guest's order becomes a customer's (claim()): a signed-in person who
 * presents the order's unexpired key, and whose account's email address is the one the order was
 * placed with, has the order made their customer record's, by one conditional update, with an
 * audit row. The address is never enough alone, and neither is the key: the key proves the
 * browser that placed the order, the address that the account is the shopper's. A claim reads the
 * actor's customer and the order whatever the outcome, and every refusal is `order.not_found`
 * after those two reads, so neither the answer nor the work tells which test failed.
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
 * A claim costs: nothing for an actor who is no person; for a person, first the schema gate's
 * answer, which sends nothing (the installation record it reads is loaded with WordPress's
 * options), and while the gate is closed `store.unavailable` before any read; then two reads for
 * a refusal and for the owner's repeat, which writes nothing; and for a claim, those two and one
 * unit of work at READ COMMITTED, in the placement's lock order (the customer's record, then the
 * order): the record (ActorCustomers::customerFor(): one read, one insert more for a first
 * record, one conditional unlink more for a stale link), the conditional update, and the audit
 * row; five statements for a customer with a record, six at a first record, seven with a stale
 * link. The loser of two claims at once reads the order again where a winner writes its audit
 * row, and rolls back: five statements for a customer with a record.
 *
 * @since 0.1.0
 * @since 0.2.0 Learns the actor's customer before the order; lists a customer's own orders; decides
 *              a claim of a guest's order.
 */
final class OrderAccessPolicy {

	/**
	 * The reason of the order event a claim writes.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLAIMED = 'customer_claimed';

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
	 * The unit of work of a claim.
	 *
	 * @since 0.2.0
	 *
	 * @var TransactionManager
	 */
	private TransactionManager $tx;

	/**
	 * Records a claim among the order's events.
	 *
	 * @since 0.2.0
	 *
	 * @var Orders
	 */
	private Orders $events;

	/**
	 * Returns a WordPress user's email address, or null when there is no such user.
	 *
	 * @since 0.2.0
	 *
	 * @var \Closure(int): ?string
	 */
	private \Closure $emailOf;

	/**
	 * Creates the policy. Sends nothing.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 The unit of work, the order's events and the account's email address, for a claim.
	 *
	 * @param OrderRepository    $orders    The order statements.
	 * @param AccessKeys         $keys      Checks a presented key against an order's hash.
	 * @param ActorCustomers     $customers Says which customer an actor is, and creates a first record.
	 * @param TransactionManager $tx        The unit of work of a claim.
	 * @param Orders             $events    Records a claim among the order's events.
	 * @param \Closure           $emailOf   Returns a WordPress user's email address, or null when there is no such user.
	 *
	 * @phpstan-param \Closure(int): ?string $emailOf
	 */
	public function __construct( OrderRepository $orders, AccessKeys $keys, ActorCustomers $customers, TransactionManager $tx, Orders $events, \Closure $emailOf ) {
		$this->orders    = $orders;
		$this->keys      = $keys;
		$this->customers = $customers;
		$this->tx        = $tx;
		$this->events    = $events;
		$this->emailOf   = $emailOf;
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
	 * Makes a guest's order the customer record of the signed-in person who presents its key, when their account's email address is the order's; answers the owner's repeat as done.
	 *
	 * In order: an actor who is no person is refused, with no statement; while the schema gate is
	 * closed, a person is refused `store.unavailable`, before anything is read, the owner's repeat
	 * included; then the actor's customer is read, then the order, whatever either turns out to
	 * be. An order already the actor's
	 * customer's is answered as done, with nothing written. Otherwise the order is claimed only
	 * when it exists, has no customer, was placed by a visitor, the key opens it, and the account's
	 * email address is the order's, compared trimmed and in ASCII lower case, in constant time.
	 * Every other outcome is `order.not_found`.
	 *
	 * The claim is one unit of work: the actor's record (created at a first claim), the
	 * conditional update, and the audit row naming the order by its uuid. When the update changes
	 * no row, another request claimed the order first: the order is read again, the claim is done
	 * when the order is now the actor's customer's, and otherwise the unit rolls back, a record it
	 * created with it, and the claim is refused.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `order.not_found` for every refusal; `store.unavailable` for a person while
	 *                        the schema gate is closed, before any read.
	 *
	 * @param string      $orderUuid The order's public identifier.
	 * @param Actor       $actor     Who claims.
	 * @param string|null $key       The access key the actor presented, or null for none.
	 */
	public function claim( string $orderUuid, Actor $actor, ?string $key ): void {
		if ( ! $actor->isPerson() ) {
			CodedException::raise( OrderError::NotFound );
		}

		// A claim's first statements are reads: the gate is asked before them, not only by the unit of work.
		$this->tx->refuseWhileClosed();

		$customer = $this->customers->customerOf( $actor );
		$order    = $this->orders->findForClaim( $orderUuid );

		// The owner's repeat, such as a retry whose answer was lost: done, and nothing written.
		if ( null !== $order && self::isCustomer( $order->access, $customer ) ) {
			return;
		}

		if ( null === $order || ! $this->mayClaim( $order, $actor, $key ) ) {
			CodedException::raise( OrderError::NotFound );
		}

		$this->tx->transaction(
			function () use ( $order, $orderUuid, $actor ): void {
				$this->claimInside( $order, $orderUuid, $actor );
			},
			RetryPolicy::deadlocks(),
			Isolation::ReadCommitted
		);
	}

	/**
	 * Tells whether the actor may make an order theirs: a guest's order, which a visitor placed, opened by the key presented, placed with the account's email address.
	 *
	 * @since 0.2.0
	 *
	 * @param ClaimableOrder $order The order, as read.
	 * @param Actor          $actor A person.
	 * @param string|null    $key   The presented key, or null.
	 * @return bool True when every test passes.
	 */
	private function mayClaim( ClaimableOrder $order, Actor $actor, ?string $key ): bool {
		return null === $order->access->customerId
			&& null === $order->actorId
			&& $this->keyOpens( $order->access, $key )
			&& $this->isAccountsEmail( $actor, $order->email );
	}

	/**
	 * Tells whether an email address is the actor's account's: the two compared trimmed and in ASCII lower case, in constant time.
	 *
	 * WordPress compares addresses so, and the database's collation too; the order keeps the
	 * address as it was typed.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor  $actor A person.
	 * @param string $email The order's address.
	 * @return bool False when it is not, or when the actor's user is gone.
	 */
	private function isAccountsEmail( Actor $actor, string $email ): bool {
		$account = ( $this->emailOf )( $actor->userId() );

		return null !== $account && hash_equals( strtolower( trim( $account ) ), strtolower( trim( $email ) ) );
	}

	/**
	 * Makes the order the actor's customer record's, inside the claim's unit of work, in the placement's lock order: the record, then the order.
	 *
	 * @since 0.2.0
	 *
	 * @throws CodedException `order.not_found` when the actor's user is gone, or another customer claimed the order first.
	 *
	 * @param ClaimableOrder $order     The order, as read before the unit.
	 * @param string         $orderUuid The order's public identifier.
	 * @param Actor          $actor     A person.
	 */
	private function claimInside( ClaimableOrder $order, string $orderUuid, Actor $actor ): void {
		$customer = $this->customers->customerFor( $actor ) ?? CodedException::raise( OrderError::NotFound );

		if ( $this->orders->claim( $order->id, $customer ) ) {
			$this->events->appendAudit( $order->id, self::CLAIMED, $orderUuid, $actor, Machine::Order );

			return;
		}

		// Another request claimed the order first: done when it was this customer's, refused, and rolled back, otherwise.
		$now = $this->orders->findForClaim( $orderUuid );

		if ( null === $now || ! self::isCustomer( $now->access, $customer ) ) {
			CodedException::raise( OrderError::NotFound );
		}
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
