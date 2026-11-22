<?php
/**
 * Tests which customer record an actor is, and who an order is opened to by it
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Customer;

use SEOCart\Customer\Application\Customers;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;

/**
 * The link from a customer record to a WordPress user: who it matches, who it never matches, and what an unlinked record keeps.
 *
 * The production Customers and OrderAccessPolicy, from the kernel's container over the test's
 * connection and real WordPress users. A link matches only the user it names at the time that user
 * registered: a visitor, a process, a deleted user and a new user given a deleted user's id match
 * none, and send no statement where nothing can match. The same pair names the person an actor
 * is.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.2.0
 */
final class CustomerLinkTest extends OrderTestCase {

	use CreatesUsers;
	use CustomerRecords;

	/**
	 * The kernel's container over the test's connection.
	 *
	 * @since 0.2.0
	 *
	 * @var Container
	 */
	private Container $kernel;

	/**
	 * Creates the customer records' table and the container.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->createCustomerTable();

		$this->kernel = KernelContainer::build( $this->db, $this->reporter() );
	}

	/**
	 * Deletes the users the test created.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a user is the customer of the record linked to them, at one read.
	 *
	 * @since 0.2.0
	 */
	public function test_a_user_is_the_customer_of_their_record_at_one_read(): void {
		$user     = $this->createUser( 'subscriber' );
		$customer = $this->plantCustomer( $user, self::registeredAt( $user ) );
		$found    = null;
		$log      = $this->captureQueries(
			function () use ( $user, &$found ): void {
				$found = $this->customers()->customerOf( Actor::user( $user ) );
			}
		);

		$this->assertSame( $customer, $found );
		$this->assertQueryCount( 1, $log, 'The link, read by the user\'s id' );
	}

	/**
	 * Tests that a visitor is no customer and sends no statement, and that a record planted for user 0, with an order placed for it, opens that order to no visitor.
	 *
	 * No code links a record to user 0: the planted row is what a broken import could leave.
	 *
	 * Planted violation: in Actor::isPerson(), drop `$this->userId >= 1 &&`. The visitor
	 * then matches the planted record, sends the read, and reads its order without a key.
	 *
	 * @since 0.2.0
	 */
	public function test_a_visitor_is_no_customer_and_a_record_of_user_zero_opens_nothing(): void {
		$planted = $this->plantCustomer( 0, null );
		$order   = $this->place( NewOrders::forTwoLines( 'USD', 'USD', $planted ) );
		$found   = 'unset';
		$log     = $this->captureQueries(
			function () use ( &$found ): void {
				$found = $this->customers()->customerOf( Actor::user( 0 ) );
			}
		);

		$this->assertNull( $found, 'A visitor is no customer.' );
		$this->assertQueryCount( 0, $log, 'Asking whether a visitor is a customer' );
		$this->assertRefused( $order, Actor::user( 0 ), 'A visitor, with the record of user 0 planted' );
	}

	/**
	 * Tests that a process acting on a user's authority is no customer, even of the user's own record, and sends no statement.
	 *
	 * Planted violation: in Actor::isPerson(), drop `&& null === $this->systemName`. The
	 * process then answers as the user's record.
	 *
	 * @since 0.2.0
	 */
	public function test_a_process_acting_for_a_user_is_no_customer(): void {
		$user = $this->createUser( 'administrator' );

		$this->plantCustomer( $user, self::registeredAt( $user ) );

		foreach ( array( Actor::system( 'cli', $user ), Actor::system( 'reconcile_stale_placements', $user ), Actor::system( 'cli', 0 ) ) as $actor ) {
			$found = 'unset';
			$log   = $this->captureQueries(
				function () use ( $actor, &$found ): void {
					$found = $this->customers()->customerOf( $actor );
				}
			);

			$this->assertNull( $found, (string) $actor->systemName() . ' acting for user ' . $actor->userId() );
			$this->assertQueryCount( 0, $log, 'Asking whether a process is a customer' );
		}
	}

	/**
	 * Tests that a deleted user's record and its orders stay, and that the record matches nobody: not the deleted id, and not a new user given that id, who registered at another time.
	 *
	 * The new user is played by a record planted for a live user's id at another registration
	 * time: what the store holds when a deleted user's id comes back for someone else.
	 *
	 * Planted violation: in Customers::customerOf(), compare the link by the user's id alone (drop
	 * `|| ! $link->matches( … )`). The new user then reads the deleted user's order.
	 *
	 * @since 0.2.0
	 */
	public function test_a_deleted_users_record_stays_and_matches_nobody(): void {
		$gone     = $this->createUser( 'subscriber' );
		$record   = $this->plantCustomer( $gone, self::registeredAt( $gone ) );
		$order    = $this->place( NewOrders::forTwoLines( 'USD', 'USD', $record ) );
		$newcomer = $this->createUser( 'subscriber' );
		$old      = $this->plantCustomer( $newcomer, '2001-02-03 04:05:06' );
		$older    = $this->place( NewOrders::forTwoLines( 'USD', 'USD', $old ) );

		$this->assertTrue( $this->deleteUser( $gone ) );
		clean_user_cache( $gone );

		$this->assertSame( $gone, $this->customerRows()[ $record ]['user_id'] ?? null, 'The deleted user\'s record stays, linked as it was: no hook followed the user.' );
		$this->assertSame( $record, (int) $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE id = %d', $this->table( 'orders' ), $order->id ), 'The order keeps naming its record.' );
		$this->assertNull( $this->customers()->customerOf( Actor::user( $gone ) ), 'The deleted user\'s id is no customer.' );
		$this->assertRefused( $order, Actor::user( $gone ), 'The deleted user\'s id' );
		$this->assertNull( $this->customers()->customerOf( Actor::user( $newcomer ) ), 'A user given a deleted user\'s id is not the old record\'s customer.' );
		$this->assertRefused( $older, Actor::user( $newcomer ), 'A user given a deleted user\'s id' );
	}

	/**
	 * Tests that unlinking a record empties its link, once, keeps the record and its orders, and leaves its user no customer.
	 *
	 * @since 0.2.0
	 */
	public function test_unlinking_keeps_the_record_and_its_orders_and_matches_nobody(): void {
		$user   = $this->createUser( 'subscriber' );
		$record = $this->plantCustomer( $user, self::registeredAt( $user ) );
		$order  = $this->place( NewOrders::forTwoLines( 'USD', 'USD', $record ) );

		$this->assertSame( $order->uuid, $this->policy()->authorize( $order->uuid, Actor::user( $user ), null )->uuid, 'The customer reads the order before the unlink.' );

		$log = $this->captureQueries(
			function () use ( $record ): void {
				$this->assertTrue( $this->customers()->unlink( $record ) );
			}
		);

		$this->assertQueryCount( 1, $log, 'An unlink' );
		$this->assertFalse( $this->customers()->unlink( $record ), 'A record already unlinked is not unlinked again.' );
		$this->assertSame(
			array(
				'user_id'            => null,
				'user_registered_at' => null,
			),
			$this->customerRows()[ $record ] ?? null
		);
		$this->assertNull( $this->customers()->customerOf( Actor::user( $user ) ) );
		$this->assertRefused( $order, Actor::user( $user ), 'The user of an unlinked record' );
	}

	/**
	 * Tests who a person is: a user, as their id and the time they registered, at no statement for a user WordPress has read, as it reads the user it authenticates, and at WordPress's own read alone for one it has not; nobody for a visitor, a process acting for a user, or a deleted user; and never a read of the records.
	 *
	 * Planted violation: in Customers::personOf(), drop the `isPerson()` check. A process acting for
	 * the user is then that person.
	 *
	 * @since 0.2.0
	 */
	public function test_a_person_is_a_users_id_and_registration_time_and_nobody_else_is_one(): void {
		$user = $this->createUser( 'subscriber' );
		$gone = $this->createUser( 'subscriber' );

		$this->plantCustomer( $user, self::registeredAt( $user ) );

		foreach ( array(
			'a visitor'                     => Actor::user( 0 ),
			'a process acting for the user' => Actor::system( 'cli', $user ),
		) as $who => $actor ) {
			$asked = $this->personWithStatements( $actor );

			$this->assertNull( $asked['person'], "{$who} is no person." );
			$this->assertQueryCount( 0, $asked['log'], "Asking who {$who} is" );
		}

		// The user as WordPress's authentication leaves them: read, and in the request's cache.
		$expected = $user . '|' . self::registeredAt( $user );
		$warm     = $this->personWithStatements( Actor::user( $user ) );

		$this->assertSame( $expected, $warm['person'], 'A user is their id and registration time.' );
		$this->assertQueryCount( 0, $warm['log'], 'Asking who a user WordPress has read is' );

		clean_user_cache( $user );

		$cold = $this->personWithStatements( Actor::user( $user ) );

		$this->assertSame( $expected, $cold['person'], 'A user WordPress has not read is the same person.' );
		$this->assertGreaterThan( 0, $cold['log']->forTable( $GLOBALS['wpdb']->users )->count(), 'WordPress reads the user it has not read.' );
		$this->assertQueryCount( 0, $cold['log']->forTable( $this->db->table( CustomerTables::CUSTOMERS ) ), 'Asking who a user is, of the records' );
		$this->assertSame( $cold['log']->count(), $cold['log']->forTable( $GLOBALS['wpdb']->users )->count() + $cold['log']->forTable( $GLOBALS['wpdb']->usermeta )->count(), 'Asking who a user is reads WordPress\'s user alone: ' . implode( '; ', $cold['log']->sqls() ) );
		$this->assertTrue( $this->deleteUser( $gone ) );

		clean_user_cache( $gone );

		$this->assertNull( $this->customers()->personOf( Actor::user( $gone ) ), 'A user WordPress no longer has is no person.' );
	}

	/**
	 * Asks the production service who an actor is, and returns the answer with the statements it cost.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return array{person: string|null, log: \SEOCart\Tests\Support\QueryLog} The person, and the statements.
	 */
	private function personWithStatements( Actor $actor ): array {
		$person = null;
		$log    = $this->captureQueries(
			function () use ( $actor, &$person ): void {
				$person = $this->customers()->personOf( $actor );
			}
		);

		return array(
			'person' => $person,
			'log'    => $log,
		);
	}

	/**
	 * Asserts that the policy refuses an order to an actor without a key.
	 *
	 * @since 0.2.0
	 *
	 * @param InsertedOrder $order The order.
	 * @param Actor         $actor Who asks.
	 * @param string        $who   Who asks, for the message.
	 */
	private function assertRefused( InsertedOrder $order, Actor $actor, string $who ): void {
		try {
			$this->policy()->authorize( $order->uuid, $actor, null );
			$this->fail( "{$who} read the order without a key." );
		} catch ( CodedException $refused ) {
			$this->assertSame( 'order.not_found', $refused->errorCode()->value, $who );
		}
	}

	/**
	 * Returns the production customers service.
	 *
	 * @since 0.2.0
	 *
	 * @return Customers The service.
	 */
	private function customers(): Customers {
		return $this->kernel->get( Customers::class );
	}

	/**
	 * Returns the production access policy.
	 *
	 * @since 0.2.0
	 *
	 * @return OrderAccessPolicy The policy.
	 */
	private function policy(): OrderAccessPolicy {
		return $this->kernel->get( OrderAccessPolicy::class );
	}
}
