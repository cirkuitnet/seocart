<?php
/**
 * Tests that every SEOCart REST route has the plugin's permission callback and a schema
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Tests\Support\RoutePermissionWalker;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The route walker: one real walk over the plugin's own routes, and its self-tests.
 *
 * The real walk boots a fresh REST server exactly as a request does, so every route the plugin
 * registers on `rest_api_init` is walked. It fails, naming route, method, rule and fix, when an
 * endpoint of a `seocart*` namespace has no permission callback, has one that is not the
 * plugin's PermissionCallback type, or uses the public-read marker for POST, PUT, PATCH or
 * DELETE, and when a route has no schema.
 *
 * The self-tests keep their planted violations as permanent fixtures, in the namespace
 * `seocart-walker-selftest/v1`, which the walker selects like any plugin namespace. The route
 * without a permission callback makes WordPress report an incorrect usage of
 * register_rest_route(); the tests that register it expect exactly that, instead of silencing
 * the notice for the whole suite.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class RoutePermissionWalkTest extends WP_UnitTestCase {

	/**
	 * The namespace of the self-test fixtures.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SELFTEST_NAMESPACE = 'seocart-walker-selftest/v1';

	/**
	 * Discards the REST server, so the next test boots one without these fixtures.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		self::discardRestServer();

		parent::tear_down();
	}

	/**
	 * Tests that the plugin's own routes break no rule, on a server that was really booted.
	 *
	 * The plugin registers no route yet, so the walk finds none; the assertions before it make
	 * sure that an empty walk means an empty route table for the plugin, not a server that was
	 * never booted or a walker that looked at nothing.
	 *
	 * Planted violation: in Kernel::boot(), directly after `self::$booted = true;`, add
	 * `add_action( 'rest_api_init', static function (): void { register_rest_route( 'seocart/v1', '/planted', array( 'methods' => 'POST', 'callback' => '__return_null', 'permission_callback' => '__return_true' ) ); } );`.
	 * The failure must name `POST /seocart/v1/planted` twice: for the callback and for the missing schema.
	 *
	 * @since 0.1.0
	 */
	public function test_the_plugins_own_routes_pass_the_walk(): void {
		$booted_before = did_action( 'rest_api_init' );
		$server        = self::bootRestServer();

		$this->assertGreaterThan( $booted_before, did_action( 'rest_api_init' ), 'rest_api_init did not fire, so no plugin route could have been registered.' );
		$this->assertArrayHasKey( '/wp/v2/posts', $server->get_routes(), 'The server has none of core\'s routes, so it was not booted the way a request boots it.' );

		$walk = ( new RoutePermissionWalker() )->walk( $server );

		$this->assertSame( count( $server->get_routes() ), $walk['routes_examined'], 'The walker did not look at every route of the server.' );
		$this->assertSame(
			array(),
			$walk['violations'],
			"SEOCart REST routes break the permission rules:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n"
		);
	}

	/**
	 * Tests that every planted violation is reported, and nothing else.
	 *
	 * @since 0.1.0
	 */
	public function test_every_planted_violation_is_reported(): void {
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$walk  = $this->walkPlantedRoutes();
		$found = array();

		foreach ( $walk['violations'] as $violation ) {
			$found[] = array( $violation['route'], $violation['method'], $violation['rule'] );
		}

		$route = '/' . self::SELFTEST_NAMESPACE;

		$this->assertEqualsCanonicalizing(
			array(
				array( $route . '/return-true', 'POST', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
				array( $route . '/closure', 'GET', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
				array( $route . '/function-name', 'GET', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
				array( $route . '/array-callable', 'PUT', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
				array( $route . '/missing-callback', 'POST', RoutePermissionWalker::RULE_MISSING_CALLBACK ),
				array( $route . '/public-read-delete', 'DELETE', RoutePermissionWalker::RULE_PUBLIC_READ_ON_WRITE ),
				array( $route . '/public-read-editable', 'POST', RoutePermissionWalker::RULE_PUBLIC_READ_ON_WRITE ),
				array( $route . '/public-read-editable', 'PUT', RoutePermissionWalker::RULE_PUBLIC_READ_ON_WRITE ),
				array( $route . '/public-read-editable', 'PATCH', RoutePermissionWalker::RULE_PUBLIC_READ_ON_WRITE ),
				array( $route . '/no-schema', 'GET', RoutePermissionWalker::RULE_MISSING_SCHEMA ),
				array( $route . '/added-by-filter', 'POST', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
				array( $route . '/added-by-filter', 'POST', RoutePermissionWalker::RULE_MISSING_SCHEMA ),
			),
			$found,
			"The walker reported something other than the planted violations:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n"
		);

		$this->assertContains( $route . '/compliant', $walk['plugin_routes'], 'The compliant fixture was not walked, so its clean result would prove nothing.' );
	}

	/**
	 * Tests that the permissive plants really are permissive: a visitor who is not logged in can write through them.
	 *
	 * This is what makes them violations rather than style: each one lets anybody change state.
	 *
	 * @since 0.1.0
	 */
	public function test_the_permissive_plants_let_a_visitor_write(): void {
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$this->walkPlantedRoutes();

		wp_set_current_user( 0 );

		foreach ( array( 'POST /return-true', 'PUT /array-callable', 'POST /missing-callback', 'DELETE /public-read-delete', 'PATCH /public-read-editable' ) as $endpoint ) {
			list( $method, $path ) = explode( ' ', $endpoint );

			$status = rest_do_request( new WP_REST_Request( $method, '/' . self::SELFTEST_NAMESPACE . $path ) )->get_status();

			$this->assertSame( 200, $status, "{$endpoint}: the plant did not let a visitor through, so it does not show what the walker guards against." );
		}

		$compliant = rest_do_request( new WP_REST_Request( 'POST', '/' . self::SELFTEST_NAMESPACE . '/compliant' ) )->get_status();

		$this->assertSame( 401, $compliant, 'The compliant fixture let a visitor write.' );
	}

	/**
	 * Tests that a violation's message names the route, the method, the rule and the fix.
	 *
	 * @since 0.1.0
	 */
	public function test_a_violation_names_route_method_rule_and_fix(): void {
		$this->setExpectedIncorrectUsage( 'register_rest_route' );

		$messages = array();

		foreach ( $this->walkPlantedRoutes()['violations'] as $violation ) {
			$messages[ $violation['method'] . ' ' . $violation['route'] . ' ' . $violation['rule'] ] = $violation['message'];
		}

		$return_true = $messages[ 'POST /' . self::SELFTEST_NAMESPACE . '/return-true ' . RoutePermissionWalker::RULE_FOREIGN_CALLBACK ];

		$this->assertStringStartsWith( 'POST /' . self::SELFTEST_NAMESPACE . '/return-true ', $return_true );
		$this->assertStringContainsString( "the function '__return_true'", $return_true );
		$this->assertStringContainsString( '[' . RoutePermissionWalker::RULE_FOREIGN_CALLBACK . ']', $return_true );
		$this->assertStringContainsString( 'Fix: use PermissionCallback::requiring()', $return_true );

		$closure = $messages[ 'GET /' . self::SELFTEST_NAMESPACE . '/closure ' . RoutePermissionWalker::RULE_FOREIGN_CALLBACK ];

		$this->assertStringContainsString( 'a closure at ' . __FILE__ . ':', $closure, 'A closure must be named by the place it is written.' );
	}

	/**
	 * Tests that routes which keep every rule pass, and that core's routes and the namespace index are not walked.
	 *
	 * @since 0.1.0
	 */
	public function test_compliant_routes_pass(): void {
		add_action( 'rest_api_init', array( self::class, 'registerCompliantRoutes' ) );

		$walk  = ( new RoutePermissionWalker() )->walk( self::bootRestServer() );
		$route = '/' . self::SELFTEST_NAMESPACE;

		$this->assertSame( array(), $walk['violations'], "A compliant route was reported:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n" );
		$this->assertEqualsCanonicalizing( array( $route . '/compliant', $route . '/compliant/(?P<id>[\d]+)' ), $walk['plugin_routes'] );
		$this->assertNotContains( $route, $walk['plugin_routes'], 'The namespace index core registers was walked.' );
		$this->assertNotContains( '/wp/v2/posts', $walk['plugin_routes'], 'A core route was walked.' );
	}

	/**
	 * Registers the planted violations. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerPlantedRoutes(): void {
		self::registerRoute( '/return-true', array( self::endpoint( 'POST', '__return_true' ) ) );
		self::registerRoute( '/closure', array( self::endpoint( 'GET', static fn(): bool => true ) ) );
		self::registerRoute( '/function-name', array( self::endpoint( 'GET', 'is_user_logged_in' ) ) );
		self::registerRoute( '/array-callable', array( self::endpoint( 'PUT', array( self::class, 'allowEverything' ) ) ) );
		self::registerRoute(
			'/missing-callback',
			array(
				array(
					'methods'  => 'POST',
					'callback' => array( self::class, 'respond' ),
				),
			)
		);
		self::registerRoute(
			'/public-read-delete',
			array(
				self::endpoint( 'GET', PermissionCallback::publicRead() ),
				self::endpoint( 'DELETE', PermissionCallback::publicRead() ),
			)
		);
		self::registerRoute( '/public-read-editable', array( self::endpoint( WP_REST_Server::EDITABLE, PermissionCallback::publicRead() ) ) );
		self::registerRoute( '/no-schema', array( self::endpoint( 'GET', PermissionCallback::requiring( 'seocart_view_orders' ) ) ), false );

		self::registerCompliantRoutes();
	}

	/**
	 * Registers routes that keep every rule. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerCompliantRoutes(): void {
		self::registerRoute(
			'/compliant',
			array(
				self::endpoint( 'GET', PermissionCallback::publicRead() ),
				self::endpoint( 'POST', PermissionCallback::requiring( 'seocart_manage_catalog' ) ),
			)
		);
		self::registerRoute(
			'/compliant/(?P<id>[\d]+)',
			array(
				self::endpoint( WP_REST_Server::DELETABLE, PermissionCallback::requiringOn( 'seocart_selftest_view_widget', 'id' ) ),
			)
		);
	}

	/**
	 * Adds an endpoint through the `rest_endpoints` filter, which gives it no namespace. Hooked to that filter.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $endpoints The server's endpoints.
	 * @return array<string, mixed> The endpoints with the planted one added.
	 */
	public static function addEndpointThroughFilter( array $endpoints ): array {
		$endpoints[ '/' . self::SELFTEST_NAMESPACE . '/added-by-filter' ] = array( self::endpoint( 'POST', '__return_true' ) );

		return $endpoints;
	}

	/**
	 * A permission callback that allows everything, planted as an array callable.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Always true.
	 */
	public static function allowEverything(): bool {
		return true;
	}

	/**
	 * Answers every fixture route.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response An empty success.
	 */
	public static function respond(): WP_REST_Response {
		return new WP_REST_Response( array(), 200 );
	}

	/**
	 * Returns the schema of the fixture routes.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed> A minimal JSON Schema.
	 */
	public static function schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'seocart-walker-selftest',
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Registers the planted and compliant fixtures, boots a server and walks it.
	 *
	 * @since 0.1.0
	 *
	 * @return array{routes_examined: int, plugin_routes: list<string>, violations: list<array{route: string, method: string, rule: string, message: string}>} The walk.
	 */
	private function walkPlantedRoutes(): array {
		add_action( 'rest_api_init', array( self::class, 'registerPlantedRoutes' ) );
		add_filter( 'rest_endpoints', array( self::class, 'addEndpointThroughFilter' ) );

		return ( new RoutePermissionWalker() )->walk( self::bootRestServer() );
	}

	/**
	 * Registers one fixture route.
	 *
	 * @since 0.1.0
	 *
	 * @param string                     $route      The route below the self-test namespace.
	 * @param list<array<string, mixed>> $endpoints  The endpoints.
	 * @param bool                       $has_schema Optional. Whether to give the route a schema. Default true.
	 */
	private static function registerRoute( string $route, array $endpoints, bool $has_schema = true ): void {
		if ( $has_schema ) {
			$endpoints['schema'] = array( self::class, 'schema' );
		}

		register_rest_route( self::SELFTEST_NAMESPACE, $route, $endpoints );
	}

	/**
	 * Builds one endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param string $methods             The HTTP methods.
	 * @param mixed  $permission_callback The permission callback.
	 * @return array<string, mixed> The endpoint.
	 */
	private static function endpoint( string $methods, $permission_callback ): array {
		return array(
			'methods'             => $methods,
			'callback'            => array( self::class, 'respond' ),
			'permission_callback' => $permission_callback,
		);
	}

	/**
	 * Boots a fresh REST server, which fires `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Server The booted server.
	 */
	private static function bootRestServer(): WP_REST_Server {
		self::discardRestServer();

		return rest_get_server();
	}

	/**
	 * Discards the current REST server.
	 *
	 * @since 0.1.0
	 */
	private static function discardRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next boot must build a new server and fire rest_api_init again.
		$wp_rest_server = null;
	}
}
