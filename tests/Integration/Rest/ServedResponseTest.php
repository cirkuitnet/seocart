<?php
/**
 * Tests the responses WordPress serves for an operation route without running its endpoint
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Rest;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use Spy_REST_Server;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Serves requests the way WordPress serves them from the web, through the test library's spy
 * server, which records the headers it sends and the body it prints.
 *
 * WordPress decides some answers before it matches a route, or instead of the endpoint: the
 * refusal of an invalid cookie nonce, a refusal by a `rest_authentication_errors` filter, and an
 * answer another plugin gives on `rest_pre_dispatch`. Each must still carry exactly the three
 * data members and the caching headers on an operation route. `?_envelope` moves the response's
 * headers into the body of a new response, and the headers must still reach the client. A route
 * outside the plugin is served untouched, and so is another plugin's endpoint under the plugin's
 * own namespace whose path an operation's pattern would also match.
 *
 * @since 0.1.0
 */
final class ServedResponseTest extends WP_UnitTestCase {

	/**
	 * The fixture's route below the namespace, for the example item.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const ROUTE = '/fixture-stock/' . FixtureStockOperation::EXAMPLE_ITEM . '/adjustments';

	/**
	 * The overlapping endpoint's route below the namespace: the fixture's pattern matches it too.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const OVERLAPPING_ROUTE = '/fixture-stock/special/adjustments';

	/**
	 * The request globals, restored after each test.
	 *
	 * @since 0.1.0
	 *
	 * @var array{server: array<string, mixed>, get: array<string, mixed>, post: array<string, mixed>, request: array<string, mixed>}
	 */
	private array $globals;

	/**
	 * The server, a spy.
	 *
	 * @since 0.1.0
	 *
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * Registers the fixture and a route outside the plugin on a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->globals = array(
			'server'  => $_SERVER,
			'get'     => $_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to be restored.
			'post'    => $_POST, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- saved to be restored.
			'request' => $_REQUEST, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- saved to be restored.
		);

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		add_action( 'rest_api_init', array( self::class, 'registerForeignRoute' ) );
		add_action( 'rest_api_init', array( self::class, 'registerOverlappingRoute' ), 5 );

		$registry = new OperationRegistry();
		FixtureStockOperation::register( $registry );

		$server = ( new OperationSurfaces( $registry ) )->server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );

		$this->server = $server;
	}

	/**
	 * Restores the request globals, WordPress's record of cookie authentication and the REST server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		$_SERVER  = $this->globals['server'];
		$_GET     = $this->globals['get'];
		$_POST    = $this->globals['post'];
		$_REQUEST = $this->globals['request'];

		unset( $GLOBALS['wp_rest_auth_cookie'] );

		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests the refusal of an invalid cookie nonce, which WordPress decides before it matches a route.
	 *
	 * @since 0.1.0
	 */
	public function test_an_invalid_cookie_nonce_is_refused_in_the_shape(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => 'subscriber' ) );
		$user->add_cap( 'seocart_manage_inventory' );

		wp_set_current_user( $user->ID );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's own record that the login cookie was valid.
		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'not-a-nonce';

		$served = $this->serve( 'POST', self::ROUTE, array( 'delta' => '1' ) );

		$this->assertSame( 403, $served['status'] );
		$this->assertSame( 'rest_cookie_invalid_nonce', $served['body']['code'] );
		$this->assertShaped( $served['body']['data'], 403, array() );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null, 'Sent after WordPress\'s own no-cache header, which it sends for this refusal.' );
		$this->assertSame( 'Cookie', $served['headers']['Vary'] ?? null );
	}

	/**
	 * Tests a refusal by a `rest_authentication_errors` filter, for a visitor.
	 *
	 * @since 0.1.0
	 */
	public function test_a_refusal_by_authentication_is_answered_in_the_shape(): void {
		add_filter( 'rest_authentication_errors', static fn(): WP_Error => new WP_Error( 'fixture_not_logged_in', 'You are not currently logged in.', array( 'status' => 401 ) ) );

		$served = $this->serve( 'POST', self::ROUTE, array( 'delta' => '1' ) );

		$this->assertSame( 401, $served['status'] );
		$this->assertSame( 'fixture_not_logged_in', $served['body']['code'] );
		$this->assertShaped( $served['body']['data'], 401, array() );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null );
		$this->assertArrayNotHasKey( 'Vary', $served['headers'], 'A visitor is not cookie-authenticated.' );
	}

	/**
	 * Tests an answer another plugin gives on `rest_pre_dispatch`, served and dispatched.
	 *
	 * @since 0.1.0
	 */
	public function test_an_answer_given_before_the_endpoint_is_finished(): void {
		add_filter(
			'rest_pre_dispatch',
			static fn(): WP_Error => new WP_Error(
				'fixture_maintenance',
				'Down for maintenance.',
				array(
					'status'      => 503,
					'retry_after' => 60,
				)
			)
		);

		$served = $this->serve( 'POST', self::ROUTE, array( 'delta' => '1' ) );

		$this->assertSame( 503, $served['status'] );
		$this->assertShaped( $served['body']['data'], 503, array( 'retry_after' => 60 ) );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null );

		$request = new WP_REST_Request( 'POST', '/' . RestBinding::NAMESPACE . self::ROUTE );
		$request->set_body_params( array( 'delta' => '1' ) );

		$dispatched = rest_do_request( $request );

		$this->assertShaped( $dispatched->get_data()['data'], 503, array( 'retry_after' => 60 ) );
		$this->assertSame( 'no-store, private', $dispatched->get_headers()['Cache-Control'] ?? null, 'An internal dispatch is finished too.' );
	}

	/**
	 * Tests `?_envelope` for a visitor: the headers reach the client although the response's own
	 * headers are in the body.
	 *
	 * @since 0.1.0
	 */
	public function test_an_enveloped_response_still_sends_the_headers(): void {
		$served = $this->serve( 'POST', self::ROUTE, array( 'delta' => '1' ), array( '_envelope' => '1' ) );

		$this->assertSame( 200, $served['status'], 'An envelope is always 200.' );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null );
		$this->assertSame( 401, $served['body']['status'] );
		$this->assertSame( 'rest_forbidden', $served['body']['body']['code'] );
		$this->assertShaped( $served['body']['body']['data'], 401, array() );
	}

	/**
	 * Tests that a route outside the plugin is served untouched: no caching header, the error data as it was.
	 *
	 * @since 0.1.0
	 */
	public function test_a_route_outside_the_plugin_is_served_untouched(): void {
		$success = $this->serve( 'GET', '', array(), array(), '/fixture-foreign/v1/things' );

		$this->assertSame( 200, $success['status'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $success['headers'] );

		$error = $this->serve( 'POST', '', array(), array(), '/fixture-foreign/v1/things' );

		$this->assertSame( 418, $error['status'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $error['headers'] );
		$this->assertSame( '{"status":418,"params":["x"]}', wp_json_encode( $error['body']['data'] ) );
	}

	/**
	 * Tests that another plugin's endpoint under the plugin's namespace, matched before an operation
	 * route whose pattern also fits its path, is served and dispatched untouched.
	 *
	 * @since 0.1.0
	 */
	public function test_another_endpoint_under_the_namespace_is_left_alone(): void {
		$routes      = array_keys( $this->server->get_routes( RestBinding::NAMESPACE ) );
		$overlapping = array_search( '/' . RestBinding::NAMESPACE . self::OVERLAPPING_ROUTE, $routes, true );
		$operation   = array_search( '/' . RestBinding::NAMESPACE . '/fixture-stock/(?P<item_id>[^/]+)/adjustments', $routes, true );

		$this->assertIsInt( $overlapping );
		$this->assertIsInt( $operation );
		$this->assertLessThan( $operation, $overlapping, 'The overlapping endpoint must be matched first, or the test proves nothing.' );
		$this->assertSame( 1, preg_match( '@^/' . RestBinding::NAMESPACE . '/fixture-stock/(?P<item_id>[^/]+)/adjustments$@i', '/' . RestBinding::NAMESPACE . self::OVERLAPPING_ROUTE ), 'The operation\'s pattern must fit the overlapping path, or the test proves nothing.' );

		$success = $this->serve( 'POST', self::OVERLAPPING_ROUTE );

		$this->assertSame( 200, $success['status'] );
		$this->assertSame( array( 'overlap' => true ), $success['body'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $success['headers'] );

		$error = $this->serve( 'POST', self::OVERLAPPING_ROUTE, array( 'fail' => '1' ) );

		$this->assertSame( 409, $error['status'] );
		$this->assertSame( 'fixture_overlap_refused', $error['body']['code'] );
		$this->assertSame( '{"status":409,"reason":"overlap"}', wp_json_encode( $error['body']['data'] ), 'The error data is the other plugin\'s, as its endpoint gave it.' );
		$this->assertArrayNotHasKey( 'Cache-Control', $error['headers'] );

		$options = $this->serve( 'OPTIONS', self::OVERLAPPING_ROUTE );

		$this->assertArrayNotHasKey( 'Cache-Control', $options['headers'] );

		$request = new WP_REST_Request( 'POST', '/' . RestBinding::NAMESPACE . self::OVERLAPPING_ROUTE );
		$request->set_body_params( array( 'fail' => '1' ) );

		$dispatched = rest_do_request( $request );

		$this->assertSame( '{"status":409,"reason":"overlap"}', wp_json_encode( $dispatched->get_data()['data'] ) );
		$this->assertArrayNotHasKey( 'Cache-Control', $dispatched->get_headers() );
	}

	/**
	 * Registers another plugin's endpoint under the plugin's namespace, before the operation routes:
	 * a POST that succeeds, or fails when asked to. Hooked to `rest_api_init` early.
	 *
	 * @since 0.1.0
	 */
	public static function registerOverlappingRoute(): void {
		register_rest_route(
			RestBinding::NAMESPACE,
			self::OVERLAPPING_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => static fn( WP_REST_Request $request ): WP_REST_Response|WP_Error => $request->has_param( 'fail' )
					? new WP_Error(
						'fixture_overlap_refused',
						'Refused by the other plugin.',
						array(
							'status' => 409,
							'reason' => 'overlap',
						)
					)
					: new WP_REST_Response( array( 'overlap' => true ) ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Registers a route outside the plugin: a GET that succeeds and a POST that fails. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerForeignRoute(): void {
		register_rest_route(
			'fixture-foreign/v1',
			'/things',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'ok' => true ) ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => static fn(): WP_Error => new WP_Error(
						'fixture_foreign_refused',
						'Refused.',
						array(
							'status' => 418,
							'params' => array( 'x' ),
						)
					),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Serves one request through the spy server.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $method The HTTP method.
	 * @param string               $route  The route below the plugin's namespace.
	 * @param array<string, mixed> $body   Optional. The form body. Default none.
	 * @param array<string, mixed> $query  Optional. The query. Default none.
	 * @param string|null          $path   Optional. The whole path instead of a plugin route. Default null.
	 * @return array{status: int|null, headers: array<string, string>, body: array<string, mixed>} What was sent.
	 */
	private function serve( string $method, string $route, array $body = array(), array $query = array(), ?string $path = null ): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET                      = $query;
		$_POST                     = $body;
		$_REQUEST                  = array_merge( $query, $body );

		$this->server->sent_headers = array();

		$this->server->serve_request( $path ?? '/' . RestBinding::NAMESPACE . $route );

		$decoded = json_decode( $this->server->sent_body, true );

		$this->assertIsArray( $decoded, 'The server printed no JSON: ' . $this->server->sent_body );

		return array(
			'status'  => $this->server->status,
			'headers' => $this->server->sent_headers,
			'body'    => $decoded,
		);
	}

	/**
	 * Asserts that error data has exactly the three members, in order.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed                $data    The `data` of an error body, decoded.
	 * @param int                  $status  The status it must carry.
	 * @param array<string, mixed> $details The details it must carry.
	 */
	private function assertShaped( $data, int $status, array $details ): void {
		$this->assertIsArray( $data );
		$this->assertSame( array( 'status', 'details', 'correlation_id' ), array_keys( $data ), 'The data has exactly the three members: none missing, none extra.' );
		$this->assertSame( $status, $data['status'] );
		$this->assertSame( $details, (array) $data['details'] );
		$this->assertSame( OperationSurfaces::CORRELATION_ID, $data['correlation_id'] );
	}
}
