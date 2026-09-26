<?php
/**
 * Tests the Store API through the production wiring: its request policy, the cart token and the session read
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Cart;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Application\CartTokens;
use SEOCart\Cart\Domain\CartToken;
use SEOCart\Cart\Interfaces\StoreApi\CartTokenTransport;
use SEOCart\Cart\Interfaces\StoreApi\StoreOperations;
use SEOCart\Cart\Interfaces\StoreApi\StoreRequestPolicy;
use SEOCart\Platform\Kernel\Container;
use SEOCart\Platform\Kernel\Modules;
use SEOCart\Platform\RateLimiter\ClientIdentities;
use SEOCart\Platform\RateLimiter\ObjectCacheRateLimiter;
use SEOCart\Platform\RateLimiter\RateLimiter;
use SEOCart\Platform\RateLimiter\TableRateLimiter;
use SEOCart\Support\Error\ErrorTable;
use SEOCart\Tests\Fixtures\Operations\FixtureCartOperation;
use SEOCart\Tests\Fixtures\Operations\FixtureCartService;
use SEOCart\Tests\Support\Doubles\FrozenClock;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ServesRequests;
use Spy_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * The real Modules::register() and subscribe(), on a container whose operation registry holds the
 * fixture cart's three operations and the session read, served the way WordPress serves a request
 * from the web (a spy server records what it sends). The replacements: the fixture's service; the
 * transport's cookie sender, which records instead of calling setcookie(); the rate limiter,
 * counting in WordPress's in-memory object cache on a frozen clock; the error table, which also
 * holds the fixture's code.
 *
 * Planted violations, each named on its test.
 *
 * @since 0.1.0
 */
final class StoreApiTest extends WP_UnitTestCase {

	use ServesRequests;

	/**
	 * The instant the clocks of the test show.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NOW = '2026-09-25 10:00:00';

	/**
	 * The fixture writes' path.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const LINES = '/' . RestBinding::STORE_NAMESPACE . FixtureCartOperation::LINES_ROUTE;

	/**
	 * The Store API's request header, as a client sends it.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private const STORE_HEADER = array( StoreRequestPolicy::HEADER => StoreRequestPolicy::HEADER_VALUE );

	/**
	 * The server.
	 *
	 * @since 0.1.0
	 *
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * The container the kernel was wired on.
	 *
	 * @since 0.1.0
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * The fixture's service.
	 *
	 * @since 0.1.0
	 *
	 * @var FixtureCartService
	 */
	private FixtureCartService $service;

	/**
	 * Every cookie the transport sent: its name, value and options.
	 *
	 * @since 0.1.0
	 *
	 * @var list<array{name: string, value: string, options: array<string, mixed>}>
	 */
	private array $cookies = array();

	/**
	 * Wires the kernel with the fixture registry and boots a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->saveRequestGlobals();

		wp_cache_flush();

		$registry = new OperationRegistry();
		$clock    = new FrozenClock( new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) );

		FixtureCartOperation::register( $registry );
		$registry->add( StoreOperations::GET_SESSION, array( StoreOperations::class, 'getSession' ) );

		$container = new Container(
			array(
				OperationRegistry::class  => static fn(): OperationRegistry => $registry,
				ErrorTable::class         => static fn(): ErrorTable => OperationSurfaces::errorTable(),
				RateLimiter::class        => static fn( Container $c ): RateLimiter => new ObjectCacheRateLimiter( $clock, static fn(): RateLimiter => $c->get( TableRateLimiter::class ) ),
				CartTokenTransport::class => fn(): CartTokenTransport => new CartTokenTransport(
					$clock,
					function ( string $name, string $value, array $options ): void {
						$this->cookies[] = array(
							'name'    => $name,
							'value'   => $value,
							'options' => $options,
						);
					}
				),
				FixtureCartService::class => static fn( Container $c ): FixtureCartService => new FixtureCartService( $c->get( CartTokens::class ) ),
			)
		);

		Modules::register( $container );

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		KernelHooks::detach( 'rest_api_init', 'wp_abilities_api_categories_init', 'wp_abilities_api_init' );
		OperationSurfaces::discard();
		Modules::subscribe( $container );

		$server = rest_get_server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );

		$this->server    = $server;
		$this->container = $container;
		$this->service   = $container->get( FixtureCartService::class );
	}

	/**
	 * Restores the request globals and discards the server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$this->restoreRequestGlobals();

		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that a write without the request header is refused before it runs, and runs with it.
	 *
	 * Planted violation: in StoreRequestPolicy::allows(), delete the header check. The write
	 * without the header then runs.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_without_the_header_is_refused_and_changes_nothing(): void {
		foreach ( array( array(), array( StoreRequestPolicy::HEADER => '0' ), array( StoreRequestPolicy::HEADER => 'yes' ) ) as $headers ) {
			$refused = $this->serve( $this->server, 'POST', self::LINES, $headers );

			$this->assertSame( 403, $refused['status'] );
			$this->assertErrorShape( $refused['body'], 'store_api.header_missing', 403 );
		}

		$this->assertSame( array(), $this->service->calls, 'A refused write must not reach the service.' );
		$this->assertSame( array(), $this->cookies, 'A refused write must not create a cart.' );

		$ran = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertSame( 200, $ran['status'] );
		$this->assertCount( 1, $this->service->calls );
	}

	/**
	 * Tests that a request carrying a valid login cookie but no nonce is refused, and never runs as a guest; with its nonce it runs as the user.
	 *
	 * WordPress itself turns such a request into a guest's: this is the silent downgrade.
	 *
	 * Planted violation: in StoreRequestPolicy::allows(), delete the downgrade check. The write
	 * then runs, as user 0.
	 *
	 * @since 0.1.0
	 */
	public function test_a_login_cookie_without_a_nonce_is_refused_never_run_as_a_guest(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->logInByCookie( $user );

		$refused = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertSame( 403, $refused['status'] );
		$this->assertErrorShape( $refused['body'], 'store_api.nonce_missing', 403 );
		$this->assertSame( array(), $this->service->calls, 'The write ran as a guest.' );

		$this->logInByCookie( $user );

		$ran = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER + array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );

		$this->assertSame( 200, $ran['status'] );
		$this->assertSame( array( 'addLine' ), array_column( $this->service->calls, 'method' ) );
		$this->assertSame( $user, $this->service->calls[0]['user_id'], 'With its nonce the write runs as the user.' );
	}

	/**
	 * Tests that the first write gets the cart token in the cookie and the header, and that a later write carrying it, in either, gets none.
	 *
	 * @since 0.1.0
	 */
	public function test_the_first_write_gets_the_token_and_later_writes_carry_it(): void {
		$first = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertSame( 200, $first['status'] );
		$this->assertSame( 'issued', $first['body']['token'] );
		$this->assertCount( 1, $this->cookies, 'The first write sends exactly one cookie.' );

		$token  = $this->service->issued[0]->value();
		$cookie = $this->cookies[0];

		$this->assertSame( CartTokenTransport::COOKIE, $cookie['name'] );
		$this->assertSame( $token, $cookie['value'] );
		$this->assertSame( $token, $first['headers'][ CartTokenTransport::HEADER ] ?? null, 'A headless client reads the token from the header.' );
		$this->assertSame(
			array(
				'expires'  => ( new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) )->modify( '+7 days' )->getTimestamp(),
				'path'     => (string) constant( 'COOKIEPATH' ),
				'domain'   => (string) constant( 'COOKIE_DOMAIN' ),
				'secure'   => false,
				'httponly' => true,
				'samesite' => 'Lax',
			),
			$cookie['options'],
			'A guest\'s cart cookie: HttpOnly, SameSite=Lax, not Secure over HTTP, for the guest cart\'s seven days.'
		);

		$_COOKIE[ CartTokenTransport::COOKIE ] = $token;

		$later = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		unset( $_COOKIE[ CartTokenTransport::COOKIE ] );

		$headless = $this->serve( $this->server, 'PATCH', self::LINES, self::STORE_HEADER + array( CartTokenTransport::HEADER => $token ) );

		$this->assertSame( 'presented', $later['body']['token'], 'The cookie is read back.' );
		$this->assertSame( 'presented', $headless['body']['token'], 'The header is read back.' );
		$this->assertCount( 1, $this->cookies, 'A write carrying the token gets no new one.' );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $later['headers'] );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $headless['headers'] );
	}

	/**
	 * Tests that the cookie is Secure over HTTPS, as WordPress's own login cookies are, and lives a logged-in customer's cart period.
	 *
	 * @since 0.1.0
	 */
	public function test_the_cookie_is_secure_over_https_and_lives_as_long_as_the_cart(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$_SERVER['HTTPS'] = 'on';
		wp_set_current_user( $user );

		$this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertCount( 1, $this->cookies );
		$this->assertTrue( $this->cookies[0]['options']['secure'] );
		$this->assertSame( ( new \DateTimeImmutable( self::NOW, new \DateTimeZone( 'UTC' ) ) )->modify( '+30 days' )->getTimestamp(), $this->cookies[0]['options']['expires'] );
	}

	/**
	 * Tests that a read never sets a cookie: a token its service issues is not sent, and the developer is told.
	 *
	 * Planted violation: in CartTokenTransport::attach(), delete the check of the request's method.
	 * The read then sends the cookie.
	 *
	 * @since 0.1.0
	 */
	public function test_a_read_never_sets_a_cookie(): void {
		$this->setExpectedIncorrectUsage( CartTokenTransport::class . '::attach' );

		$read = $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . FixtureCartOperation::CART_ROUTE );

		$this->assertSame( 200, $read['status'] );
		$this->assertSame( 'issued', $read['body']['token'], 'The fixture read issues a token, so the test proves the transport withholds it.' );
		$this->assertSame( array(), $this->cookies );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $read['headers'] );
	}

	/**
	 * Tests that a write that fails after issuing a token sends no token.
	 *
	 * @since 0.1.0
	 */
	public function test_a_failed_write_sends_no_token(): void {
		$failed = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER, array( 'fail' => 'yes' ) );

		$this->assertSame( 409, $failed['status'] );
		$this->assertCount( 1, $this->service->issued, 'The fixture issued a token before it failed.' );
		$this->assertSame( array(), $this->cookies );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $failed['headers'] );
	}

	/**
	 * Tests that a write to an existing cart is refused without the cart's token.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_to_an_existing_cart_needs_its_token(): void {
		$refused = $this->serve( $this->server, 'PATCH', self::LINES, self::STORE_HEADER );

		$this->assertSame( 400, $refused['status'] );
		$this->assertErrorShape( $refused['body'], 'store_api.cart_token_missing', 400 );
		$this->assertSame( array(), $this->service->calls );

		$_COOKIE[ CartTokenTransport::COOKIE ] = 'not-a-token';

		$this->assertSame( 400, $this->serve( $this->server, 'PATCH', self::LINES, self::STORE_HEADER )['status'], 'Something that is not a token is no token.' );
	}

	/**
	 * Tests that a client is refused once it has sent the limit of a window, and that the refused requests do not run.
	 *
	 * Planted violations, one at a time:
	 * - in StoreWrites::admit(), compare `$count > $limit->limit() + 1`: the request over the limit
	 *   then runs;
	 * - in RestAdapter::respond(), skip the count (delete the PublicWrites::admit() step): nothing is
	 *   counted, and every request runs.
	 *
	 * @since 0.1.0
	 */
	public function test_the_rate_limit_refuses_the_request_over_it(): void {
		for ( $i = 0; $i < FixtureCartOperation::LIMIT; $i++ ) {
			$this->assertSame( 200, $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER )['status'] );
		}

		$over = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertSame( 429, $over['status'] );
		$this->assertErrorShape( $over['body'], 'store_api.rate_limited', 429 );
		$this->assertCount( FixtureCartOperation::LIMIT, $this->service->calls );
		$this->assertSame( 403, $this->serve( $this->server, 'POST', self::LINES )['status'], 'A request refused for its header is refused before it is counted.' );
	}

	/**
	 * Tests that a write is counted once, although WordPress asks the policies again for the
	 * `Allow` header, and that an OPTIONS request, whose `Allow` header asks every endpoint's policy,
	 * counts nothing: the count is made where the endpoint runs, never by a policy.
	 *
	 * The OPTIONS request carries the header and a cart token, so the last endpoint of the route,
	 * the write to an existing cart, passes every check of its policy but the method. An OPTIONS
	 * request did not arrive as a write, so every policy refuses it, and the answer's `Allow` header
	 * names no write method.
	 *
	 * Planted violation: count in the permission callback again. In RestAdapter::register(), give
	 * each public write a policy that calls PublicWrites::admit() whenever it is asked, and then asks
	 * the Store API's policy. The write is then counted again for its `Allow` header, and the
	 * OPTIONS request is counted.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_is_counted_once_and_a_question_counts_nothing(): void {
		$write = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER );

		$this->assertSame( 200, $write['status'] );
		$this->assertStringContainsString( 'POST', $write['headers']['Allow'] ?? '', 'WordPress built the Allow header, asking the policies again.' );
		$this->assertSame( 1, $this->counted(), 'The write was counted more than once.' );

		$options = $this->serve( $this->server, 'OPTIONS', self::LINES, self::STORE_HEADER + array( CartTokenTransport::HEADER => CartToken::generate()->value() ) );

		$this->assertSame( 200, $options['status'] );
		$this->assertStringNotContainsString( 'POST', $options['headers']['Allow'] ?? '', 'A policy allowed a write to a request that did not arrive as one.' );
		$this->assertSame( 1, $this->counted(), 'An OPTIONS request was counted.' );
	}

	/**
	 * Tests that a write another plugin dispatches with rest_do_request() while a response is being
	 * served is counted once, and is checked for the method its HTTP request arrived with.
	 *
	 * A plugin's `rest_post_dispatch` callback dispatches the fixture's write, once while a POST is
	 * served and once while a GET is: the write runs, and is counted, only in the POST.
	 *
	 * Planted violation: in StoreRequestPolicy::allows(), skip the HttpMethod check while
	 * `rest_post_dispatch` runs (`if ( ! doing_filter( 'rest_post_dispatch' ) && ! HttpMethod::isWrite( $request ) )`).
	 * The write dispatched while a GET is served then runs.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_dispatched_while_a_response_is_served_is_counted_once_and_checks_its_method(): void {
		$session    = '/' . RestBinding::STORE_NAMESPACE . StoreOperations::SESSION_ROUTE;
		$dispatched = array();

		// The client already has a cart, so the fixture's write issues no token that a later response would carry.
		$_COOKIE[ CartTokenTransport::COOKIE ] = CartToken::generate()->value();

		add_filter(
			'rest_post_dispatch',
			static function ( $result, $server, WP_REST_Request $request ) use ( $session, &$dispatched ) {
				if ( $session === $request->get_route() ) {
					$write = new WP_REST_Request( 'POST', self::LINES );
					$write->set_header( StoreRequestPolicy::HEADER, StoreRequestPolicy::HEADER_VALUE );

					$dispatched[] = rest_do_request( $write );
				}

				return $result;
			},
			20,
			3
		);

		$this->serve( $this->server, 'POST', $session );

		$this->assertCount( 1, $dispatched );
		$this->assertSame( 200, $dispatched[0]->get_status(), 'The write dispatched while a POST was served did not run.' );
		$this->assertSame( 1, $this->counted(), 'The dispatched write was not counted once.' );

		$this->serve( $this->server, 'GET', $session );

		$this->assertCount( 2, $dispatched );
		$this->assertSame( 405, $dispatched[1]->get_status(), 'The write dispatched while a GET was served ran.' );
		$this->assertSame( 'store_api.read_method', $dispatched[1]->get_data()['code'] ?? null );
		$this->assertCount( 1, $this->service->calls );
		$this->assertSame( 1, $this->counted() );
	}

	/**
	 * Tests that a client cannot escape the limit by sending a made-up cart token with each request.
	 *
	 * A token that is only well formed names no cart the store knows, so it is not part of what the
	 * client is counted under.
	 *
	 * Planted violation: in StoreWrites::admit(), count under the presented token when the request
	 * carries one: `$token = $this->tokens->presented();` and then
	 * `null === $token ? $this->identities->of( get_current_user_id() ) : $this->identities->ofCart( $token )`.
	 * Every write with a fresh token then runs.
	 *
	 * @since 0.1.0
	 */
	public function test_made_up_tokens_do_not_escape_the_limit(): void {
		for ( $i = 0; $i < FixtureCartOperation::LIMIT; $i++ ) {
			$this->assertSame( 200, $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER + array( CartTokenTransport::HEADER => CartToken::generate()->value() ) )['status'] );
		}

		$over = $this->serve( $this->server, 'POST', self::LINES, self::STORE_HEADER + array( CartTokenTransport::HEADER => CartToken::generate()->value() ) );

		$this->assertSame( 429, $over['status'], 'A fresh token made a new client.' );
		$this->assertCount( FixtureCartOperation::LIMIT, $this->service->calls );
	}

	/**
	 * Tests that a write whose permission callback another plugin wraps is still counted, once.
	 *
	 * The wrapper asks the plugin's callback twice for each request, as a plugin that checks before
	 * it logs might, so the endpoint WordPress matched holds the wrapper, not the plugin's callback.
	 * The count is made where the endpoint runs, so the wrapper can neither skip it nor repeat it.
	 *
	 * Planted violation: count in the permission callback again, as on
	 * test_a_write_is_counted_once_and_a_question_counts_nothing(): each write is counted every time
	 * the wrapper asks, and the second write is already refused.
	 *
	 * @since 0.1.0
	 */
	public function test_a_wrapped_callback_is_counted_once(): void {
		add_filter(
			'rest_endpoints',
			static function ( array $endpoints ): array {
				foreach ( array_keys( $endpoints[ self::LINES ] ?? array() ) as $key ) {
					if ( is_int( $key ) ) {
						$original = $endpoints[ self::LINES ][ $key ]['permission_callback'];

						$endpoints[ self::LINES ][ $key ]['permission_callback'] = static function ( WP_REST_Request $request ) use ( $original ) {
							$original( $request );

							return $original( $request );
						};
					}
				}

				return $endpoints;
			}
		);

		OperationSurfaces::discard();

		$server = rest_get_server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );
		$this->assertInstanceOf( \Closure::class, $server->get_routes()[ self::LINES ][0]['permission_callback'] ?? null, 'The callback was not wrapped, so the test proves nothing.' );

		for ( $i = 0; $i < FixtureCartOperation::LIMIT; $i++ ) {
			$this->assertSame( 200, $this->serve( $server, 'POST', self::LINES, self::STORE_HEADER )['status'], 'A wrapped write was counted more than once.' );
		}

		$this->assertSame( 429, $this->serve( $server, 'POST', self::LINES, self::STORE_HEADER )['status'], 'A wrapped write escaped the count.' );
		$this->assertSame( FixtureCartOperation::LIMIT + 1, $this->counted(), 'Each wrapped write, the refused one included, was counted once.' );
	}

	/**
	 * Tests that a GET naming another method with `?_method=` is refused and sets no cookie, although WordPress routes it as the write.
	 *
	 * Planted violation: in StoreRequestPolicy::allows(), delete the HttpMethod check. The GET then
	 * runs the write, and its response carries the new cart's cookie.
	 *
	 * @since 0.1.0
	 */
	public function test_a_get_naming_a_write_method_is_refused_and_sets_no_cookie(): void {
		$get = $this->serve( $this->server, 'GET', self::LINES, self::STORE_HEADER, array(), array( '_method' => 'POST' ) );

		$this->assertSame( 405, $get['status'] );
		$this->assertErrorShape( $get['body'], 'store_api.read_method', 405 );
		$this->assertSame( array(), $this->service->calls );
		$this->assertSame( array(), $this->cookies );
		$this->assertSame( 0, $this->counted(), 'A refused read was counted.' );
	}

	/**
	 * Tests that the transport sends no token for a request routed as a write that arrived as a GET.
	 *
	 * Planted violation: in HttpMethod::isWrite(), look only at the method WordPress routes the
	 * request by. The token is then sent.
	 *
	 * @since 0.1.0
	 */
	public function test_the_transport_sends_no_token_for_a_write_that_arrived_as_a_get(): void {
		$this->setExpectedIncorrectUsage( CartTokenTransport::class . '::attach' );

		$transport = $this->container->get( CartTokenTransport::class );
		$response  = new WP_REST_Response( array(), 200 );

		$_SERVER['REQUEST_METHOD'] = 'GET';

		$transport->issue( CartToken::generate() );
		$transport->attach( $response, $this->server, new WP_REST_Request( 'POST', self::LINES ) );

		$this->assertSame( array(), $this->cookies );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $response->get_headers() );
	}

	/**
	 * Tests the session read for a guest: a nonce for user 0, cached nowhere, and no cookie.
	 *
	 * @since 0.1.0
	 */
	public function test_the_session_read_answers_a_guest_and_sets_no_cookie(): void {
		$session = $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . StoreOperations::SESSION_ROUTE );

		$this->assertSame( 200, $session['status'] );
		$this->assertSame( 0, $session['body']['user_id'] );
		$this->assertSame( 1, wp_verify_nonce( $session['body']['nonce'], 'wp_rest' ), 'The nonce is fresh, for the guest.' );
		$this->assertSame( 'no-store, private', $session['headers']['Cache-Control'] ?? null );
		$this->assertContains( 'Cookie', array_map( 'trim', explode( ',', $session['headers']['Vary'] ?? '' ) ), 'A Store API answer varies by cookie, a guest\'s too.' );
		$this->assertSame( array(), $this->cookies );
		$this->assertArrayNotHasKey( CartTokenTransport::HEADER, $session['headers'] );
	}

	/**
	 * Tests the session read for a logged-in customer: with a nonce, the user and a nonce for them; with the cookie alone, a guest's answer.
	 *
	 * The customer who reads 0 knows the login was not honoured. The answer to the cookie alone
	 * holds no nonce the customer's login would accept.
	 *
	 * @since 0.1.0
	 */
	public function test_the_session_read_says_which_user_wordpress_authenticated(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->logInByCookie( $user );

		$session = $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . StoreOperations::SESSION_ROUTE, array( 'X-WP-Nonce' => wp_create_nonce( 'wp_rest' ) ) );

		$this->assertSame( 200, $session['status'] );
		$this->assertSame( $user, $session['body']['user_id'] );
		$this->assertSame( 'Cookie', $session['headers']['Vary'] ?? null );

		$this->logInByCookie( $user );

		$downgraded = $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . StoreOperations::SESSION_ROUTE );

		$this->assertSame( 200, $downgraded['status'] );
		$this->assertSame( 0, $downgraded['body']['user_id'], 'A login cookie without a nonce is a guest\'s request.' );

		wp_set_current_user( $user );

		$this->assertFalse( wp_verify_nonce( $downgraded['body']['nonce'], 'wp_rest' ), 'The nonce answered to the cookie alone must not be one the login accepts.' );
		$this->assertSame( array(), $this->cookies );
	}

	/**
	 * Returns how many writes of the fixture's bucket the guest client has been counted for.
	 *
	 * @since 0.1.0
	 *
	 * @return int The count of the current window.
	 */
	private function counted(): int {
		return $this->container->get( RateLimiter::class )->peek( FixtureCartOperation::BUCKET, $this->container->get( ClientIdentities::class )->of( 0 ), FixtureCartOperation::WINDOW );
	}

	/**
	 * Plays a browser sending a valid login cookie: the user is current, and WordPress has recorded the cookie as valid.
	 *
	 * @since 0.1.0
	 *
	 * @param int $user The user.
	 */
	private function logInByCookie( int $user ): void {
		wp_set_current_user( $user );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's own record that the login cookie was valid.
		$GLOBALS['wp_rest_auth_cookie'] = true;
	}
}
