<?php
/**
 * Tests the order-status read through the production wiring: who may read an order, and what everyone else gets
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Order\Application\ActorCustomers;
use SEOCart\Order\Domain\InsertedOrder;
use SEOCart\Order\Infrastructure\OrderTables;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Support\Clock;
use SEOCart\Tests\Support\CreatesUsers;
use SEOCart\Tests\Support\Doubles\SequentialIdGenerator;
use SEOCart\Tests\Support\KernelContainer;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\Order\NewOrders;
use SEOCart\Tests\Support\Order\OrderTestCase;
use SEOCart\Tests\Support\ServesRequests;
use Spy_REST_Server;

/**
 * `order.get_status` through the real route: the five cases of who may read an order, and one answer for every refusal.
 *
 * The real Modules::register() and subscribe() serve the production operations on a spy server,
 * over the test's connection and real order tables, the way WordPress serves a request from the
 * web. One service is replaced: the port that says which customer an actor is. The store keeps no
 * customer records yet, so in production no actor is a customer; here it maps the owner's user to
 * the customer the order is placed for, and another user to another customer. A logged-in user
 * signs in as a browser does, with the login cookie and a REST nonce.
 *
 * Every refusal is `order.not_found`: no such order, an order that is not the caller's, a wrong
 * key and an expired key give the same status, the same headers and the same bytes.
 *
 * No answer sets a cookie. A cookie can leave in three ways, and each is watched: as a header the
 * REST server sends, which the spy server records; through the plugin's one cookie sender, the
 * cart token's transport, whose sender records here instead of calling setcookie(), as the cart's
 * tests do; and by a setcookie() of its own. The suite has printed before any test runs, so PHP
 * refuses that call with a "headers already sent" warning, which PHPUnit turns into an error:
 * the read then fails instead of answering. The source scan of cookie senders refuses the call
 * anywhere in the plugin as well.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class OrderStatusRouteTest extends OrderTestCase {

	use CreatesUsers;
	use ServesRequests;

	/**
	 * The customer the owner's order is placed for.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const OWNER = 12;

	/**
	 * Another customer, whose user does not own the order.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const SOMEONE_ELSE = 7;

	/**
	 * The server.
	 *
	 * @since 0.1.0
	 *
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * The customer each user acts as, by user id; a user not listed is no customer.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, int>
	 */
	private array $customers = array();

	/**
	 * The name of every cookie the cart token's transport sent.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private array $cookies = array();

	/**
	 * Wires the kernel over the test's connection, with the customers double and a recording cookie sender, and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->saveRequestGlobals();

		$container = KernelContainer::build(
			$this->db,
			$this->reporter(),
			array(
				ActorCustomers::class     => fn(): ActorCustomers => $this->customersDouble(),
				CartTokenTransport::class => fn( Container $c ): CartTokenTransport => new CartTokenTransport(
					$c->get( Clock::class ),
					function ( string $name ): void {
						$this->cookies[] = $name;
					}
				),
			)
		);

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );
		OperationSurfaces::discard();
		Modules::subscribe( $container );

		$server = rest_get_server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );

		$this->server = $server;
	}

	/**
	 * Restores the request globals, discards the server and deletes the users.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->restoreRequestGlobals();

		OperationSurfaces::discard();

		$this->deleteCreatedUsers();

		parent::tear_down();
	}

	/**
	 * Tests that a logged-in user who is not the order's customer is refused, without a key and with a valid key for another order.
	 *
	 * Written first: an exemption for logged-in users is the shape of both documented
	 * vulnerabilities the policy exists to prevent. The key of the user's own order still opens
	 * that order: being logged in neither grants nor withholds anything.
	 *
	 * Planted violation: in OrderAccessPolicy::grants(), add `if ( is_user_logged_in() ) { return true; }`
	 * first. The non-owner then reads the order.
	 *
	 * @since 0.1.0
	 */
	public function test_a_logged_in_customer_who_does_not_own_the_order_is_refused_with_or_without_a_key(): void {
		$order   = $this->place( NewOrders::forTwoLines( 'USD', 'USD', self::OWNER ) );
		$another = $this->place();
		$user    = $this->createUser( 'subscriber' );

		$this->customers[ $user ] = self::SOMEONE_ELSE;

		$attempts = array(
			'without a key'             => array(),
			'with another order\'s key' => array( OrderStoreOperations::KEY_HEADER => $another->accessKey ),
		);

		foreach ( $attempts as $attempt => $headers ) {
			$this->assertNotFound( $this->read( $order->uuid, $this->logIn( $user ) + $headers ), "A logged-in user who does not own the order, {$attempt}" );
		}

		$own = $this->read( $another->uuid, $this->logIn( $user ) + array( OrderStoreOperations::KEY_HEADER => $another->accessKey ) );

		$this->assertSame( 200, $own['status'], 'A key opens its order to a logged-in user as to a guest.' );
		$this->assertSame( $another->uuid, $own['body']['uuid'] ?? null );
	}

	/**
	 * Tests that a guest with the order's key in the header reads the order.
	 *
	 * The answer is exactly the declared figures and the lines as the order recorded them: no
	 * email, no address, never cached, no cookie.
	 *
	 * Planted violation: in OrderStatusRead::read(), call `setcookie( 'seocart_seen', '1' )`. PHP
	 * refuses it, and the read answers 500 instead of 200.
	 *
	 * @since 0.1.0
	 */
	public function test_a_guest_with_the_orders_key_reads_it(): void {
		$order    = $this->place( NewOrders::forTwoLines( 'EUR', 'USD' ) );
		$lines    = $this->orders->findByUuid( $order->uuid )->lines;
		$expected = array(
			'uuid'                 => $order->uuid,
			'order_number'         => $order->orderNumber,
			'status'               => 'pending_payment',
			'payment_status'       => 'unpaid',
			'currency'             => 'EUR',
			'subtotal_minor'       => 2500,
			'discount_total_minor' => 200,
			'shipping_total_minor' => 500,
			'fee_total_minor'      => 0,
			'tax_total_minor'      => 280,
			'grand_total_minor'    => 3080,
			'paid_minor'           => 0,
			'refunded_minor'       => 0,
			'due_minor'            => 3080,
			'lines'                => array(
				array(
					'line_uuid'           => $lines[0]->lineUuid,
					'title'               => 'Tee',
					'variant_label'       => 'Medium',
					'sku'                 => 'TEE-M',
					'quantity'            => 2,
					'unit_price_minor'    => 1000,
					'unit_amount_basis'   => 'net',
					'line_subtotal_minor' => 2000,
					'line_discount_minor' => 200,
					'line_tax_minor'      => 180,
					'line_total_minor'    => 1980,
				),
				array(
					'line_uuid'           => $lines[1]->lineUuid,
					'title'               => 'Mug',
					'variant_label'       => '',
					'sku'                 => 'MUG-1',
					'quantity'            => 1,
					'unit_price_minor'    => 500,
					'unit_amount_basis'   => 'net',
					'line_subtotal_minor' => 500,
					'line_discount_minor' => 0,
					'line_tax_minor'      => 50,
					'line_total_minor'    => 550,
				),
			),
		);

		$read = $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey ) );

		$this->assertSame( 200, $read['status'], (string) wp_json_encode( $read['body'] ) );
		$this->assertSame( $expected, $read['body'], 'The answer is the order\'s figures, and nothing personal.' );
		$this->assertNotStore( $read );
	}

	/**
	 * Tests that a key in the query never opens an order, and its refusal does not tell a right key from a wrong one.
	 *
	 * A key in a URL lands in logs the plugin cannot redact. The key is a header input, so a
	 * request that sends it in the query is refused as a bad request (`rest_invalid_param`, 400)
	 * before the service runs: the order is never looked up, so the answer is the same whether the
	 * key is the order's, another order's or made up, whether the order exists or not, and it
	 * does not carry the key. The key in the header still opens the order.
	 *
	 * Planted violation: in OrderStoreOperations::getStatus(), drop the `headers:` argument of the
	 * RestBinding. `order_key` is then an ordinary query input, and the right key in the query
	 * opens the order with a 200.
	 *
	 * @since 0.1.0
	 */
	public function test_a_key_in_the_query_is_refused_whatever_it_is(): void {
		$order   = $this->place();
		$another = $this->place();

		$refusals = array(
			'the right key'        => $this->read( $order->uuid, $this->asGuest(), array( OrderStoreOperations::KEY => $order->accessKey ) ),
			'another order\'s key' => $this->read( $order->uuid, $this->asGuest(), array( OrderStoreOperations::KEY => $another->accessKey ) ),
			'a made-up key'        => $this->read( $order->uuid, $this->asGuest(), array( OrderStoreOperations::KEY => str_repeat( '0', 32 ) ) ),
			'no such order'        => $this->read( SequentialIdGenerator::nth( 999999 ), $this->asGuest(), array( OrderStoreOperations::KEY => $order->accessKey ) ),
		);
		$answers  = array();

		foreach ( $refusals as $refusal => $read ) {
			$this->assertSame( 400, $read['status'], "A request with {$refusal} in the query: " . (string) wp_json_encode( $read['body'] ) );
			$this->assertErrorShape( $read['body'], 'rest_invalid_param', 400 );
			$this->assertStringNotContainsString( $order->accessKey, $read['raw'], 'The refusal carries the key.' );

			$answers[ $refusal ] = $this->comparable( $read );
		}

		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $answers ) ), "A key in the query is answered differently by what it is:\n" . (string) wp_json_encode( $answers, JSON_PRETTY_PRINT ) );
		$this->assertSame( 200, $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey ) )['status'], 'The same key in the header opens the order.' );
		$this->assertSame( 400, $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey ), array( OrderStoreOperations::KEY => $order->accessKey ) )['status'], 'The right key in the header does not excuse a copy in the query: the copy has already reached the URL.' );
	}

	/**
	 * Tests that a guest without a key is refused, as is one whose key is empty: an order without a customer belongs to no actor.
	 *
	 * Planted violation: in OrderAccessPolicy::isCustomer(), drop `null !== $access->customerId`.
	 * A guest, who is no customer, then owns every order placed without one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_guest_without_a_key_is_refused(): void {
		$order = $this->place();

		$this->assertNotFound( $this->read( $order->uuid, $this->asGuest() ), 'A guest without a key' );
		$this->assertNotFound( $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => '' ) ), 'A guest with an empty key' );
		$this->assertNotFound( $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => ' ' ) ), 'A guest with a blank key' );
	}

	/**
	 * Tests that the order's customer, logged in, reads it without a key.
	 *
	 * @since 0.1.0
	 */
	public function test_the_logged_in_customer_reads_the_order_without_a_key(): void {
		$order = $this->place( NewOrders::forTwoLines( 'USD', 'USD', self::OWNER ) );
		$user  = $this->createUser( 'subscriber' );

		$this->customers[ $user ] = self::OWNER;

		$read = $this->read( $order->uuid, $this->logIn( $user ) );

		$this->assertSame( 200, $read['status'], (string) wp_json_encode( $read['body'] ) );
		$this->assertSame( array( $order->uuid, $order->orderNumber ), array( $read['body']['uuid'] ?? null, $read['body']['order_number'] ?? null ) );
		$this->assertNotStore( $read );
	}

	/**
	 * Tests that a key is refused once the database clock reaches its expiry, and works until then.
	 *
	 * The expiry is planted with the database clock, never a PHP one.
	 *
	 * Planted violation: in OrderAccessPolicy::keyOpens(), drop `&& ! $access->keyExpired`. The
	 * expired key then reads the order.
	 *
	 * @since 0.1.0
	 */
	public function test_an_expired_key_is_refused(): void {
		$order = $this->place();
		$key   = $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey );

		$this->expireKey( $order, '+ INTERVAL 1 HOUR' );
		$this->assertSame( 200, $this->read( $order->uuid, $key )['status'], 'A key an hour before its expiry opens the order.' );

		$this->expireKey( $order, '' );
		$this->assertNotFound( $this->read( $order->uuid, $key ), 'A key at the instant it expires' );

		$this->expireKey( $order, '- INTERVAL 1 SECOND' );
		$this->assertNotFound( $this->read( $order->uuid, $key ), 'A key a second after it expired' );
	}

	/**
	 * Tests that no such order, an order that is not the caller's, a wrong key and an expired key are answered with the same status and the same bytes.
	 *
	 * Only the correlation id differs from one answer to the next, so it is set aside before the
	 * bytes and every header the answers were sent with are compared, and so are the cookies.
	 *
	 * Planted violations, each shown red and removed:
	 * - in OrderAccessPolicy::authorize(), refuse a presented key that does not open the order with
	 *   `AuthorizationError::Denied`: the wrong key's answer is then a 403;
	 * - in OrderAccessPolicy::authorize(), before refusing, add a `rest_post_dispatch` filter that
	 *   sends `X-SEOCart-Refusal: none` when no order has the uuid and `refused` otherwise: the
	 *   headers then differ.
	 *
	 * @since 0.1.0
	 */
	public function test_every_refusal_is_the_same_answer(): void {
		$order   = $this->place();
		$expired = $this->place();

		$this->expireKey( $expired, '- INTERVAL 1 SECOND' );

		$refusals = array(
			'no such order'  => $this->read( SequentialIdGenerator::nth( 999999 ), $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey ) ),
			'not yours'      => $this->read( $order->uuid, $this->asGuest() ),
			'a wrong key'    => $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $expired->accessKey ) ),
			'an expired key' => $this->read( $expired->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $expired->accessKey ) ),
		);
		$answers  = array();

		foreach ( $refusals as $refusal => $read ) {
			$this->assertNotFound( $read, ucfirst( $refusal ) );

			$answers[ $refusal ] = $this->comparable( $read );
		}

		$this->assertCount( 1, array_unique( array_map( 'wp_json_encode', $answers ) ), "The refusals can be told apart:\n" . (string) wp_json_encode( $answers, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Tests that a read costs the access check's one query and the order's four, whatever its number of lines, and a refusal the one.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_costs_five_queries_and_a_refusal_one(): void {
		global $wpdb;

		$plugin    = '/' . preg_quote( $wpdb->prefix . 'seocart_', '/' ) . '/';
		$documents = array(
			'two lines' => NewOrders::forTwoLines(),
			'six lines' => NewOrders::forLines( 6 ),
		);

		foreach ( $documents as $kind => $document ) {
			$order = $this->place( $document );
			$read  = null;
			$log   = $this->captureQueries(
				function () use ( $order, &$read ): void {
					$read = $this->read( $order->uuid, $this->asGuest() + array( OrderStoreOperations::KEY_HEADER => $order->accessKey ) );
				}
			);

			$this->assertSame( 200, $read['status'] ?? null );
			$this->assertCount( count( $document->lines ), $read['body']['lines'] ?? array(), "The answer for an order of {$kind} carries each of its lines." );
			$this->assertQueryCount( 5, $log->matching( $plugin ), "reading an order of {$kind}: its access check, then its row, its lines, their options and its addresses" );

			$refused = $this->captureQueries(
				function () use ( $order ): void {
					$this->assertNotFound( $this->read( $order->uuid, $this->asGuest() ), 'A guest without a key' );
				}
			);

			$this->assertQueryCount( 1, $refused->matching( $plugin ), "refusing a read of an order of {$kind}: its access check" );
		}
	}

	/**
	 * Tests that the order's internal id and its number never name it on the route: only its uuid does.
	 *
	 * @since 0.1.0
	 */
	public function test_only_the_uuid_names_the_order(): void {
		$order = $this->place();
		$key   = array( OrderStoreOperations::KEY_HEADER => $order->accessKey );
		$names = array(
			'its id'     => (string) $order->id,
			'its number' => $order->orderNumber,
		);

		foreach ( $names as $name => $value ) {
			$read = $this->read( $value, $this->asGuest() + $key );

			$this->assertSame( 400, $read['status'], "The order named by {$name}: " . (string) wp_json_encode( $read['body'] ) );
			$this->assertErrorShape( $read['body'], 'rest_invalid_param', 400 );
		}

		$this->assertSame( 200, $this->read( $order->uuid, $this->asGuest() + $key )['status'], 'Its uuid names it.' );
	}

	/**
	 * Serves one status read.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $uuid    What the URL names the order by.
	 * @param array<string, string> $headers Optional. The request headers. Default none.
	 * @param array<string, string> $query   Optional. The query. Default none.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>, raw: string, cookies: list<string>} What was sent, the body's bytes, and every cookie the transport sent while answering.
	 */
	private function read( string $uuid, array $headers = array(), array $query = array() ): array {
		$path   = '/' . RestBinding::STORE_NAMESPACE . str_replace( '{uuid}', $uuid, OrderStoreOperations::STATUS_ROUTE );
		$sent   = count( $this->cookies );
		$answer = $this->serve( $this->server, 'GET', $path, $headers, array(), $query );

		return $answer + array(
			'raw'     => (string) $this->server->sent_body,
			'cookies' => array_slice( $this->cookies, $sent ),
		);
	}

	/**
	 * Returns what makes two refusals the same answer: the status, the bytes, the headers and the cookies, set apart from the correlation id.
	 *
	 * @since 0.1.0
	 *
	 * @param array{status: int|null, headers: array<string, string>, body: array<string, mixed>, raw: string, cookies: list<string>} $read The refusal.
	 * @return array{status: int|null, bytes: string, headers: array<string, string>, cookies: list<string>} What to compare.
	 */
	private function comparable( array $read ): array {
		$correlation = (string) $read['body']['data']['correlation_id'];

		return array(
			'status'  => $read['status'],
			'bytes'   => str_replace( $correlation, '', $read['raw'] ),
			'headers' => array_map( static fn( string $value ): string => str_replace( $correlation, '', $value ), $read['headers'] ),
			'cookies' => $read['cookies'],
		);
	}

	/**
	 * Makes the next request a guest's.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string> The headers a guest sends: none.
	 */
	private function asGuest(): array {
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		return array();
	}

	/**
	 * Makes the next request a logged-in browser's: the user is current, the login cookie valid, and the nonce sent.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user The user.
	 * @return array<string, string> The nonce header.
	 */
	private function logIn( int $user ): array {
		wp_set_current_user( $user );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's own record that the login cookie was valid.
		$GLOBALS['wp_rest_auth_cookie'] = true;

		return array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) );
	}

	/**
	 * Moves an order's key expiry to the database clock's now, plus or minus an interval.
	 *
	 * @since 0.1.0
	 *
	 * @param InsertedOrder $order    The order.
	 * @param string        $interval An SQL interval term such as `- INTERVAL 1 SECOND`, or empty for now.
	 */
	private function expireKey( InsertedOrder $order, string $interval ): void {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The interval is one of the test's own literals.
		$this->db->execute( "UPDATE %i SET access_key_expires_at = UTC_TIMESTAMP() {$interval} WHERE id = %d", $this->table( OrderTables::ORDERS ), $order->id );
	}

	/**
	 * Asserts that a read was refused as `order.not_found`, never cached.
	 *
	 * @since 0.1.0
	 *
	 * @param array{status: int|null, headers: array<string, string>, body: array<string, mixed>, raw: string, cookies: list<string>} $read    The read.
	 * @param string                                                                                                                  $refusal Who was refused, for the message.
	 */
	private function assertNotFound( array $read, string $refusal ): void {
		$this->assertSame( 404, $read['status'], "{$refusal} was not refused: " . (string) wp_json_encode( $read['body'] ) );
		$this->assertErrorShape( $read['body'], 'order.not_found', 404 );
		$this->assertNotStore( $read );
	}

	/**
	 * Asserts that an answer is never cached, varies by cookie and sets no cookie: none among the headers the server sent, none through the transport.
	 *
	 * @since 0.1.0
	 *
	 * @param array{status: int|null, headers: array<string, string>, body: array<string, mixed>, raw: string, cookies: list<string>} $read The read.
	 */
	private function assertNotStore( array $read ): void {
		$this->assertSame( 'no-store, private', $read['headers']['Cache-Control'] ?? null );
		$this->assertContains( 'Cookie', array_map( 'trim', explode( ',', $read['headers']['Vary'] ?? '' ) ) );
		$this->assertArrayNotHasKey( 'Set-Cookie', $read['headers'] );
		$this->assertSame( array(), $read['cookies'], 'The read sent a cookie.' );
	}

	/**
	 * Returns the customers double: each user's customer from $customers, read when asked.
	 *
	 * @since 0.1.0
	 *
	 * @return ActorCustomers The double.
	 */
	private function customersDouble(): ActorCustomers {
		return new class( fn( int $user ): ?int => $this->customers[ $user ] ?? null ) implements ActorCustomers {

			/**
			 * Returns a user's customer.
			 *
			 * @var \Closure(int): ?int
			 */
			private \Closure $lookup;

			/**
			 * Creates the double.
			 *
			 * @param \Closure $lookup Returns a user's customer.
			 *
			 * @phpstan-param \Closure(int): ?int $lookup
			 */
			public function __construct( \Closure $lookup ) {
				$this->lookup = $lookup;
			}

			/**
			 * Returns the customer the actor's user is mapped to.
			 *
			 * @param Actor $actor The actor.
			 * @return int|null The customer, or null.
			 */
			public function customerOf( Actor $actor ): ?int {
				return ( $this->lookup )( $actor->userId() );
			}
		};
	}
}
