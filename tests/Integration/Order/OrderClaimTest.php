<?php
/**
 * Tests a signed-in customer's claim of an order they placed as a guest, through its route, with real users
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Customer\Infrastructure\CustomerTables;
use SEOCart\Order\Application\OrderAccessPolicy;
use SEOCart\Order\Application\OrderError;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\AccountOrderClaim;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Database\Database;
use SEOCart\Platform\Database\TransactionManager;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\GatedTransactionManager;
use SEOCart\Platform\Kernel\GateState;
use SEOCart\Platform\Kernel\KernelError;
use SEOCart\Platform\Kernel\SchemaGate;
use SEOCart\Support\Error\CodedException;
use SEOCart\Tests\Support\Cart\ServesStoreApi;
use SEOCart\Tests\Support\Checkout\PlacementKernel;
use SEOCart\Tests\Support\Checkout\PlacementTestCase;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Customer\CustomerRecords;
use SEOCart\Tests\Support\Doubles\ClosedGateTransactions;
use SEOCart\Tests\Support\Order\ClaimsOrders;
use SEOCart\Tests\Support\Pricing\PricesInCurrencies;

/**
 * A guest's order, placed with the address E, claimed over `POST seocart/store/v1/account/orders` by real WordPress users: as a table of who sends what, and what each is answered.
 *
 * The order is placed as a shopper places one, through the placement, and its key is the one the
 * placement answered. A user's email address is written to WordPress's users table directly
 * where a test needs two accounts with one address, which WordPress itself refuses. A logged-in
 * user signs in as a browser does, with the login cookie and a REST nonce; every claim carries the
 * Store API's header, as a browser's write does.
 *
 * Planted violations, each shown red and removed: in OrderAccessPolicy::mayClaim(), leave out the
 * email comparison: the claim by an account with another address is taken; leave out the key test:
 * the claim without a key is taken; in OrderAccessPolicy::keyOpens(), leave out the expiry: the
 * claim with the expired key is taken; in OrderAccessPolicy::mayClaim(), leave out
 * `null === $order->actorId`: the order placed logged-in is taken; in OrderAccessPolicy::claim(),
 * call ActorCustomers::customerFor() before the decision: the refused users are left with a
 * record; leave out the audit row: the claim writes no row; write it on the repeat too: the repeat
 * writes a second; in OrderAccessPolicy::isAccountsEmail(), leave out the refusal of an empty
 * address: an order and an account without one are taken as a match.
 *
 * @since 0.2.0
 */
final class OrderClaimTest extends PlacementTestCase {

	use ClaimsOrders;
	use CreatesUsers;
	use CustomerRecords;
	use PricesInCurrencies;
	use ServesStoreApi;

	/**
	 * The email address the guest's orders are placed with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const EMAIL = 'ada@example.com';

	/**
	 * How many orders the test placed, which names each placement's idempotency key.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private int $placed = 0;

	/**
	 * Wires the kernel and boots a spy server.
	 *
	 * @since 0.2.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bootStoreApi();
	}

	/**
	 * Restores the request globals and the installation record, and deletes the users.
	 *
	 * @since 0.2.0
	 */
	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->shutStoreApi();
		$this->removeBootRecord();
		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests who may claim a guest's order, as a table of what each claim is answered, then what the claimed order reads like to its new customer.
	 *
	 * @since 0.2.0
	 */
	public function test_who_may_claim_a_guests_order(): void {
		$order       = $this->guestOrder( self::EMAIL );
		$other       = $this->guestOrder( self::EMAIL );
		$expired     = $this->guestOrder( self::EMAIL );
		$elsewhere   = $this->guestOrder( 'someone@example.org' );
		$cased       = $this->guestOrder( self::EMAIL );
		$placer      = $this->createUserWithEmail( 'subscriber', 'placer@example.org' );
		$loggedIn    = $this->loggedInOrderWithoutCustomer( $placer );
		$claimant    = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$stranger    = $this->createUserWithEmail( 'subscriber', 'stranger@example.org' );
		$admin       = $this->createUserWithEmail( 'administrator', 'admin@example.org' );
		$caseClaimer = $this->createUserWithEmail( 'subscriber', 'Ada@Example.com' );
		$unaddressed = $this->guestOrder( self::EMAIL );
		$noAddress   = $this->createUserWithEmail( 'subscriber', '' );

		$this->expireKey( $expired['uuid'] );

		// No checkout places an order without an address: the empty one is written directly.
		$this->db->execute( "UPDATE %i SET email = '' WHERE uuid = %s", $this->table( OrderTables::ORDERS ), $unaddressed['uuid'] );

		$rows  = array(
			'a user with E and the key'                    => array( 200, fn(): array => $this->claim( $order['uuid'], $this->logIn( $claimant ) + self::keyHeader( $order['key'] ) ) ),
			'the same request again'                       => array( 200, fn(): array => $this->claim( $order['uuid'], $this->logIn( $claimant ) + self::keyHeader( $order['key'] ) ) ),
			'a user with E and no key'                     => array( 404, fn(): array => $this->claim( $other['uuid'], $this->logIn( $claimant ) ) ),
			'a user with E and another order\'s key'       => array( 404, fn(): array => $this->claim( $other['uuid'], $this->logIn( $claimant ) + self::keyHeader( $cased['key'] ) ) ),
			'a user with E and the key expired'            => array( 404, fn(): array => $this->claim( $expired['uuid'], $this->logIn( $claimant ) + self::keyHeader( $expired['key'] ) ) ),
			'a user with another email and the key'        => array( 404, fn(): array => $this->claim( $other['uuid'], $this->logIn( $stranger ) + self::keyHeader( $other['key'] ) ) ),
			'an administrator with another email and the key' => array( 404, fn(): array => $this->claim( $other['uuid'], $this->logIn( $admin ) + self::keyHeader( $other['key'] ) ) ),
			'a user with E, the order placed with another' => array( 404, fn(): array => $this->claim( $elsewhere['uuid'], $this->logIn( $claimant ) + self::keyHeader( $elsewhere['key'] ) ) ),
			'a visitor with the key'                       => array( 404, fn(): array => $this->claim( $other['uuid'], $this->asGuest() + self::keyHeader( $other['key'] ) ) ),
			'a login cookie without the nonce'             => array( 403, fn(): array => $this->claim( $other['uuid'], self::withoutNonce( $this->logIn( $claimant ) ) + self::keyHeader( $other['key'] ) ) ),
			'the key in the query'                         => array( 400, fn(): array => $this->claim( $other['uuid'], $this->logIn( $claimant ), array(), array( OrderStoreOperations::KEY => $other['key'] ) ) ),
			'the key in the body'                          => array( 400, fn(): array => $this->claim( $other['uuid'], $this->logIn( $claimant ), array( OrderStoreOperations::KEY => $other['key'] ) ) ),
			'an order placed logged-in that has no customer' => array( 404, fn(): array => $this->claim( $loggedIn['uuid'], $this->logIn( $claimant ) + self::keyHeader( $loggedIn['key'] ) ) ),
			'no address on the order nor on the account'   => array( 404, fn(): array => $this->claim( $unaddressed['uuid'], $this->logIn( $noAddress ) + self::keyHeader( $unaddressed['key'] ) ) ),
			'E in another case (Ada@Example.com)'          => array( 200, fn(): array => $this->claim( $cased['uuid'], $this->logIn( $caseClaimer ) + self::keyHeader( $cased['key'] ) ) ),
		);
		$table = array();
		$sent  = array();

		foreach ( $rows as $row => list( $expected, $request ) ) {
			$answer        = null;
			$log           = $this->captureQueries(
				function () use ( $request, &$answer ): void {
					$answer = $request();
				}
			);
			$sent[ $row ]  = count( self::claimsOwn( $log->sqls() ) );
			$table[ $row ] = array( $expected, $answer['status'] ?? null, $answer['body']['code'] ?? ( $answer['body']['uuid'] ?? '' ) );

			if ( 'a user with E and the key' === $row ) {
				$this->assertSame( 1, $this->auditRows( $order['uuid'] ), 'A claim writes one audit row.' );
			}
		}

		$lines = array( sprintf( '%-50s %-9s %-9s %s', 'claim', 'expected', 'answered', 'code or uuid' ) );

		foreach ( $table as $row => list( $expected, $status, $code ) ) {
			$lines[] = sprintf( '%-50s %-9s %-9s %s (%d statements of the claim)', $row, $expected, (string) $status, $code, $sent[ $row ] );
		}

		fwrite( STDOUT, "\nWho may claim a guest's order:\n  " . implode( "\n  ", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Printed for the report.

		$this->assertSame( array_map( static fn( array $row ): int => $row[0], $rows ), array_map( static fn( array $row ): ?int => $row[1], $table ), 'The claims\' answers.' );
		$this->assertSame( 0, $sent['a visitor with the key'], 'A visitor is no person: the claim sends nothing.' );
		$this->assertSame( 2, $sent['no address on the order nor on the account'], 'Two empty addresses are refused after the same two reads as every refusal.' );
		$this->assertSame( 1, $this->auditRows( $order['uuid'] ), 'The repeat writes no second audit row.' );
		$this->assertSame( 1, $this->auditRows( $cased['uuid'] ) );
		$this->assertSame( array( $order['uuid'], $cased['uuid'] ), array( $table['a user with E and the key'][2], $table['E in another case (Ada@Example.com)'][2] ), 'A claim answers the order\'s uuid.' );

		$this->assertClaimed( $order, $claimant );

		$records = array_map( static fn( array $row ): ?int => $row['user_id'], array_values( $this->customerRows() ) );

		sort( $records );

		$this->assertSame( array( $placer, $claimant, $caseClaimer ), $records, 'No refusal leaves a customer record: the placer\'s, and the two claimants\'.' );
		$this->assertNull( $this->customerOf( $other['uuid'] ), 'The refused claims left the order a guest\'s.' );
		$this->assertNull( $this->customerOf( $loggedIn['uuid'] ) );
		$this->assertNull( $this->customerOf( $unaddressed['uuid'] ) );
	}

	/**
	 * Tests that while the schema gate is closed, a signed-in claim, one the gate would let through and the owner's repeat alike, is refused `store.unavailable` before it reads the link or the order, and writes nothing; a visitor's is still `order.not_found`, with no statement.
	 *
	 * The claim runs through the kernel with a transaction manager that plays the closed gate, as
	 * the gate's refusals of the other services whose first statements are reads are tested.
	 *
	 * Planted violations: in OrderAccessPolicy::claim(), leave out the gate's question: the claim
	 * reads the link and the order, and the repeat is answered as done; ask it after the two reads:
	 * both claims read the link and the order before they are refused.
	 *
	 * @since 0.2.0
	 */
	public function test_while_the_gate_is_closed_a_signed_in_claim_is_refused_before_its_first_read(): void {
		$order  = $this->guestOrder( self::EMAIL );
		$owned  = $this->guestOrder( self::EMAIL );
		$user   = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$owner  = $this->createUserWithEmail( 'subscriber', self::EMAIL );
		$gated  = PlacementKernel::over(
			$this->db,
			$this->tokens,
			$this->identities,
			$this->wake,
			$this->reporter(),
			array( TransactionManager::class => fn(): TransactionManager => new ClosedGateTransactions( $this->db, GateState::CodeNewer ) )
		);
		$claims = array(
			'a claim the gate would let through' => array( $order, $user ),
			'the owner\'s repeat'                => array( $owned, $owner ),
		);

		// The owner's order became theirs while the gate was open.
		$this->assertSame( 200, $this->claim( $owned['uuid'], $this->logIn( $owner ) + self::keyHeader( $owned['key'] ) )['status'] );

		foreach ( $claims as $claim => list( $target, $claimant ) ) {
			$refusal = null;
			$log     = $this->captureQueries(
				function () use ( $gated, $target, $claimant, &$refusal ): void {
					$refusal = self::refusalOf( fn() => $gated->get( AccountOrderClaim::class )->claim( self::claimInput( $target ), Actor::user( $claimant ) ) );
				}
			);

			$this->assertSame( KernelError::StoreUnavailable, $refusal?->errorCode(), ucfirst( $claim ) . ' with the gate closed.' );

			foreach ( array( CustomerTables::CUSTOMERS, OrderTables::ORDERS, OrderTables::EVENTS ) as $table ) {
				$this->assertQueryCount( 0, $log->forTable( $this->table( $table ) ), "{$claim}: {$table}, before the refusal" );
			}
		}

		$visitor = null;
		$log     = $this->captureQueries(
			function () use ( $gated, $order, &$visitor ): void {
				$visitor = self::refusalOf( fn() => $gated->get( AccountOrderClaim::class )->claim( self::claimInput( $order ), Actor::user( 0 ) ) );
			}
		);

		$this->assertSame( OrderError::NotFound, $visitor?->errorCode(), 'A visitor is no person, whatever the gate.' );
		$this->assertSame( 0, $log->count(), 'A visitor\'s claim sends nothing.' );
		$this->assertNull( $this->customerOf( $order['uuid'] ), 'The order is still a guest\'s.' );
		$this->assertSame( array(), array_filter( $this->customerRows(), static fn( array $row ): bool => $user === $row['user_id'] ), 'The claimant has no record.' );
		$this->assertSame( array( 0, 1 ), array( $this->auditRows( $order['uuid'] ), $this->auditRows( $owned['uuid'] ) ), 'No claim row was written while the gate was closed.' );
	}

	/**
	 * Tests what each path of a claim sends: the claim's own statements, the rate limit's count set apart, with the unit of work's control statements printed beside.
	 *
	 * A visitor sends nothing; a refusal and the owner's repeat read the user's customer and the
	 * order; a claim adds the unit: the record (one read, one insert more at a first record, one
	 * conditional unlink more for a stale link), the update and the audit row. The loser of two
	 * claims at once, here another customer's claim committed between the read and the update,
	 * reads the order again where a winner writes its audit row, and its unit rolls back.
	 *
	 * The claims run through the production's gated transaction manager, the store installed and
	 * its schema current, so the schema gate's question is in every count: it reads the
	 * installation record WordPress loads with its options, and sends nothing.
	 *
	 * Planted violation: in OrderAccessPolicy::claim(), ask ActorCustomers::customerFor() for the
	 * repeat too: the repeat costs more than a refusal.
	 *
	 * @since 0.2.0
	 */
	public function test_what_each_claim_costs(): void {
		$this->plantBootRecord();
		$this->shutStoreApi();
		$this->bootStoreApi( array( TransactionManager::class => static fn( Container $c ): TransactionManager => new GatedTransactionManager( $c->get( Database::class ), $c->get( SchemaGate::class ) ) ) );

		$paths   = array(
			'a visitor'                          => array( 0, fn(): array => $this->claimPath( 'visitor' ) ),
			'a refusal'                          => array( 2, fn(): array => $this->claimPath( 'refused' ) ),
			'the owner\'s repeat'                => array( 2, fn(): array => $this->claimPath( 'repeat' ) ),
			'a claim, the customer has a record' => array( 5, fn(): array => $this->claimPath( 'record' ) ),
			'a claim at a first record'          => array( 6, fn(): array => $this->claimPath( 'first' ) ),
			'a claim with a stale link'          => array( 7, fn(): array => $this->claimPath( 'stale' ) ),
			'the loser of two claims at once'    => array( 5, fn(): array => $this->claimPath( 'loser' ) ),
		);
		$counts  = array();
		$options = array();
		$lines   = array();

		foreach ( $paths as $path => list( $expected, $measure ) ) {
			list( $status, $own, $control, $optionReads ) = $measure();

			$counts[ $path ]  = count( $own );
			$options[ $path ] = $optionReads;
			$lines[]          = sprintf( '%-36s %d %s; the unit\'s control: %s; options read: %d', $path, count( $own ), (string) $status, array() === $control ? 'none' : implode( ', ', $control ), $optionReads );

			foreach ( $own as $statement ) {
				$lines[] = '    ' . substr( (string) preg_replace( '/\s+/', ' ', $statement ), 0, 130 );
			}
		}

		fwrite( STDOUT, "\nWhat each claim sends, the rate limit's count set apart:\n  " . implode( "\n  ", $lines ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Printed for the report.

		$this->assertSame( array_map( static fn( array $path ): int => $path[0], $paths ), $counts );
		$this->assertSame( array_fill_keys( array_keys( $paths ), 0 ), $options, 'The schema gate\'s question reads no option: the installation record is loaded with WordPress\'s options.' );
	}

	/**
	 * Sets up one path of a claim, sends it, and returns its status, the claim's own statements, the unit of work's control statements and how many statements read WordPress's options.
	 *
	 * @since 0.2.0
	 *
	 * @param string $path `visitor`, `refused`, `repeat`, `record`, `first`, `stale` or `loser`.
	 * @return array{0: int|null, 1: list<string>, 2: list<string>, 3: int} What was sent.
	 */
	private function claimPath( string $path ): array {
		global $wpdb;

		$order = $this->guestOrder( self::EMAIL );
		$user  = $this->createUserWithEmail( 'subscriber', self::EMAIL );

		if ( 'repeat' === $path ) {
			$this->assertSame( 200, $this->claim( $order['uuid'], $this->logIn( $user ) + self::keyHeader( $order['key'] ) )['status'] );
		}

		if ( in_array( $path, array( 'record', 'loser' ), true ) ) {
			$this->plantCustomer( $user, self::registeredAt( $user ) );
		}

		if ( 'stale' === $path ) {
			$this->plantCustomer( $user, '2001-01-01 00:00:00' );
		}

		if ( 'loser' === $path ) {
			$winner = $this->plantCustomer( $this->createUser( 'subscriber' ), null );
			$b      = $this->secondConnection();

			// Another customer's claim commits between this claim's read of the order and its update.
			$this->beforeStatement(
				'/^' . preg_quote( sprintf( 'UPDATE `%s` SET customer_id', $this->table( OrderTables::ORDERS ) ), '/' ) . '/',
				fn() => $b->query( sprintf( "UPDATE `%s` SET customer_id = %d WHERE uuid = '%s'", $this->table( OrderTables::ORDERS ), $winner, $order['uuid'] ) )
			);
		}

		$headers = 'visitor' === $path ? $this->asGuest() : $this->logIn( $user );
		$key     = 'refused' === $path ? self::keyHeader( $this->guestOrder( self::EMAIL )['key'] ) : self::keyHeader( $order['key'] );
		$answer  = array();
		$log     = $this->captureQueries(
			function () use ( $order, $headers, $key, &$answer ): void {
				$answer = $this->claim( $order['uuid'], $headers + $key );
			}
		);

		return array(
			$answer['status'] ?? null,
			$this->claimsOwn( $log->sqls() ),
			array_values( preg_grep( '/^(SET TRANSACTION|START TRANSACTION|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK|COMMIT)/', $log->sqls() ) ),
			$log->forTable( $wpdb->options )->count(),
		);
	}

	/**
	 * Asserts what a claimed order reads like to its new customer: the owner's read with its addresses, in their list, and the status read without a key; and its audit row.
	 *
	 * @since 0.2.0
	 *
	 * @param array{uuid: string, key: string} $order    The order.
	 * @param int                              $customer The user who claimed it.
	 */
	private function assertClaimed( array $order, int $customer ): void {
		$own    = $this->readAs( $customer, str_replace( '{uuid}', $order['uuid'], OrderStoreOperations::OWN_ROUTE ) );
		$list   = $this->readAs( $customer, OrderStoreOperations::LIST_ROUTE );
		$status = $this->readAs( $customer, str_replace( '{uuid}', $order['uuid'], OrderStoreOperations::STATUS_ROUTE ) );
		$event  = $this->db->fetchRow( 'SELECT e.machine, e.reason, e.reference, e.actor_type, e.actor_id FROM %i e JOIN %i o ON o.id = e.order_id WHERE o.uuid = %s AND e.reason = %s', $this->table( OrderTables::EVENTS ), $this->table( OrderTables::ORDERS ), $order['uuid'], OrderAccessPolicy::CLAIMED );

		$this->assertSame( 200, $own['status'], (string) wp_json_encode( $own['body'] ) );
		$this->assertSame( self::EMAIL, $own['body']['billing_address']['email'] ?? null, 'The owner\'s read carries the order\'s addresses.' );
		$this->assertContains( $order['uuid'], array_column( (array) ( $list['body']['orders'] ?? array() ), 'uuid' ), 'The order is in the customer\'s list.' );
		$this->assertSame( 200, $status['status'], 'The status read opens the order to its customer without a key.' );
		$this->assertSame(
			array(
				'machine'    => 'order',
				'reason'     => OrderAccessPolicy::CLAIMED,
				'reference'  => $order['uuid'],
				'actor_type' => 'user',
				'actor_id'   => (string) $customer,
			),
			$event,
			'The audit row names the order and the user, and nothing else of anyone\'s.'
		);
	}

	/**
	 * Returns what a claim's service is handed for an order: its uuid, and its key as the header carries it.
	 *
	 * @since 0.2.0
	 *
	 * @param array{uuid: string, key: string} $order The order.
	 * @return array<string, string> The prepared input.
	 */
	private static function claimInput( array $order ): array {
		return array(
			'uuid'                    => $order['uuid'],
			OrderStoreOperations::KEY => $order['key'],
		);
	}

	/**
	 * Runs work that must be refused, and returns the refusal, or null when it was not.
	 *
	 * @since 0.2.0
	 *
	 * @param \Closure $work The work.
	 * @return CodedException|null The refusal.
	 */
	private static function refusalOf( \Closure $work ): ?CodedException {
		try {
			$work();
		} catch ( CodedException $refused ) {
			return $refused;
		}

		return null;
	}

	/**
	 * Places a guest's order, as a shopper places one, with an email address, and returns its uuid and the key the placement answered.
	 *
	 * @since 0.2.0
	 *
	 * @param string $email The address typed at checkout.
	 * @return array{uuid: string, key: string} The order.
	 */
	private function guestOrder( string $email ): array {
		return $this->placeWithEmail( self::guest(), $email, $this->nextKey() );
	}

	/**
	 * Returns a new idempotency key for the next order the test places.
	 *
	 * @since 0.2.0
	 *
	 * @return string The key.
	 */
	private function nextKey(): string {
		++$this->placed;

		return 'claim-' . $this->placed;
	}

	/**
	 * Places an order logged in, with the claimant's address E, and leaves it without a customer, as a record unlinked later would.
	 *
	 * @since 0.2.0
	 *
	 * @param int $user The user who places it.
	 * @return array{uuid: string, key: string} The order.
	 */
	private function loggedInOrderWithoutCustomer( int $user ): array {
		$order = $this->placeWithEmail( Actor::user( $user ), self::EMAIL, $this->nextKey() );

		$this->db->execute( 'UPDATE %i SET customer_id = NULL WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $order['uuid'] );

		return $order;
	}

	/**
	 * Serves one Store API read as a logged-in user, with no key.
	 *
	 * @since 0.2.0
	 *
	 * @param int    $user  The user.
	 * @param string $route The route, under the Store API's namespace.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function readAs( int $user, string $route ): array {
		return $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . $route, $this->logIn( $user ) );
	}

	/**
	 * Returns a logged-in browser's headers without its nonce: the login cookie alone.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, string> $headers The headers logIn() returned.
	 * @return array<string, string> The headers, without the nonce.
	 */
	private static function withoutNonce( array $headers ): array {
		unset( $headers['X-WP-Nonce'] );

		return $headers;
	}

	/**
	 * Returns the statements a claim sent itself: the plugin's, but for the rate limit's count.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $statements The statements a request sent.
	 * @return list<string> The claim's.
	 */
	private function claimsOwn( array $statements ): array {
		global $wpdb;

		return array_values(
			array_filter(
				$statements,
				fn( string $sql ): bool => str_contains( $sql, $wpdb->prefix . 'seocart_' ) && ! str_contains( $sql, $this->table( 'rate_counters' ) )
			)
		);
	}

	/**
	 * Moves an order's key expiry a second into the past, by the database clock.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order.
	 */
	private function expireKey( string $uuid ): void {
		$this->db->execute( 'UPDATE %i SET access_key_expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $uuid );
	}

	/**
	 * Counts an order's claim rows among its events.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order.
	 * @return int The rows.
	 */
	private function auditRows( string $uuid ): int {
		return (int) $this->db->fetchValue( 'SELECT COUNT(*) FROM %i e JOIN %i o ON o.id = e.order_id WHERE o.uuid = %s AND e.reason = %s', $this->table( OrderTables::EVENTS ), $this->table( OrderTables::ORDERS ), $uuid, OrderAccessPolicy::CLAIMED );
	}

	/**
	 * Returns an order's customer record.
	 *
	 * @since 0.2.0
	 *
	 * @param string $uuid The order.
	 * @return int|null The record's id, or null for none.
	 */
	private function customerOf( string $uuid ): ?int {
		$customer = $this->db->fetchValue( 'SELECT customer_id FROM %i WHERE uuid = %s', $this->table( OrderTables::ORDERS ), $uuid );

		return null === $customer ? null : (int) $customer;
	}
}
