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

use SEOCart\Application\Operations\Operations;
use SEOCart\Catalog\Infrastructure\ProductPostType;
use SEOCart\Catalog\Interfaces\Rest\ProductPostsController;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Authorization\ProductCapabilities;
use SEOCart\Tests\Support\Doubles\SubclassedAutosavesController;
use SEOCart\Tests\Support\KernelHooks;
use SEOCart\Tests\Support\RoutePermissionWalker;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * The route walker: one real walk over the plugin's own routes, one over the product post type's `wp/v2` base, and their self-tests.
 *
 * The real walk boots a fresh REST server exactly as a request does, so every route the plugin
 * registers on `rest_api_init` is walked. It fails, naming route, method, rule and fix, when an
 * endpoint of a `seocart*` namespace, in any letter case, has no permission callback, has one
 * that is not the plugin's PermissionCallback type, or uses the public-read marker for a method
 * other than GET or HEAD, and when a route has no schema.
 *
 * The self-tests keep their planted violations as permanent fixtures, in the namespace
 * `seocart-walker-selftest/v1`, which the walker selects like any plugin namespace. The route
 * without a permission callback makes WordPress report an incorrect usage of
 * register_rest_route(); the tests that register it expect exactly that, instead of silencing
 * the notice for the whole suite.
 *
 * The product's base, `/wp/v2/seocart-products`, is walked by ownership and core's mapping: every
 * endpoint under it must be guarded by a method of one of the post type's three controllers, the
 * very check core guards that route and method with, and the posts controller must be
 * ProductPostsController, the other two core's own classes. The two carried plants are
 * self-tests here, the post type served by core's default controller and a `__return_true`
 * route at the base, and so are a write guarded by a read check and a foreign autosave class.
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
	 * The plugin's namespace spelt in another letter case, for the fixture that must not escape the walk.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const UPPERCASE_NAMESPACE = 'SEOCart/v1';

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
	 * The routes are the ones the kernel registered on `rest_api_init` when the plugin booted. The
	 * walk must see every route the production operations declare, so a clean walk cannot come
	 * from an empty route table, a server that was never booted or a walker that looked at nothing.
	 *
	 * Planted violation: in the kernel's `rest_api_init` closure in Modules::kernelSubscribe(),
	 * after the adapter registers its routes, add
	 * `register_rest_route( 'seocart/v1', '/planted', array( 'methods' => 'POST', 'callback' => '__return_null', 'permission_callback' => '__return_true' ) );`.
	 * The failure must name `POST /seocart/v1/planted` twice: for the callback and for the missing schema.
	 *
	 * Planted violation for the count of visited routes: in RoutePermissionWalker::walk(), add
	 * `if ( $visited > 10 ) { break; }` directly after `++$visited;`.
	 *
	 * @since 0.1.0
	 */
	public function test_the_plugins_own_routes_pass_the_walk(): void {
		$booted_before = did_action( 'rest_api_init' );
		$server        = self::bootRestServer();

		$this->assertGreaterThan( $booted_before, did_action( 'rest_api_init' ), 'rest_api_init did not fire, so no plugin route could have been registered.' );
		$this->assertArrayHasKey( '/wp/v2/posts', $server->get_routes(), 'The server has none of core\'s routes, so it was not booted the way a request boots it.' );

		$walk = ( new RoutePermissionWalker() )->walk( $server );

		foreach ( Operations::registry()->all() as $definition ) {
			if ( null !== $definition->rest() ) {
				$this->assertContains( RestAdapter::serverRoute( $definition->rest() ), $walk['plugin_routes'], 'The walk did not see a route the kernel registers, so a clean walk would prove nothing.' );
			}
		}

		$this->assertSame( count( $server->get_routes() ), $walk['routes_visited'], 'The walker stopped before it had looked at every route of the server.' );
		$this->assertSame(
			array(),
			$walk['violations'],
			"SEOCart REST routes break the permission rules:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n"
		);
	}

	/**
	 * Tests that every planted violation is reported, and nothing else.
	 *
	 * Planted violations in the walker, each of which must make this test fail:
	 * - in isPluginRoute(), compare with `str_starts_with()`, which is case-sensitive: the
	 *   `SEOCart/v1` fixture is no longer reported;
	 * - in checkEndpoint(), replace the public-read allow-list with a list of the methods that
	 *   change state, `! in_array( …, self::PUBLIC_READ_METHODS, true )` becoming
	 *   `in_array( …, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true )`: the LINK fixture is no
	 *   longer reported.
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
				array( $route . '/public-read-delete', 'DELETE', RoutePermissionWalker::RULE_PUBLIC_READ_BEYOND_GET ),
				array( $route . '/public-read-editable', 'POST', RoutePermissionWalker::RULE_PUBLIC_READ_BEYOND_GET ),
				array( $route . '/public-read-editable', 'PUT', RoutePermissionWalker::RULE_PUBLIC_READ_BEYOND_GET ),
				array( $route . '/public-read-editable', 'PATCH', RoutePermissionWalker::RULE_PUBLIC_READ_BEYOND_GET ),
				array( $route . '/public-read-link', 'LINK', RoutePermissionWalker::RULE_PUBLIC_READ_BEYOND_GET ),
				array( '/' . self::UPPERCASE_NAMESPACE . '/uppercase-namespace', 'POST', RoutePermissionWalker::RULE_FOREIGN_CALLBACK ),
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

		$route     = '/' . self::SELFTEST_NAMESPACE;
		$endpoints = array(
			'POST ' . $route . '/return-true',
			'PUT ' . $route . '/array-callable',
			'POST ' . $route . '/missing-callback',
			'DELETE ' . $route . '/public-read-delete',
			'PATCH ' . $route . '/public-read-editable',
			'LINK ' . $route . '/public-read-link',
			'POST /' . self::UPPERCASE_NAMESPACE . '/uppercase-namespace',
			// WordPress matches routes without regard to case, so the plugin's own spelling reaches it too.
			'POST /' . strtolower( self::UPPERCASE_NAMESPACE ) . '/uppercase-namespace',
		);

		foreach ( $endpoints as $endpoint ) {
			list( $method, $path ) = explode( ' ', $endpoint );

			$status = rest_do_request( new WP_REST_Request( $method, $path ) )->get_status();

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
		KernelHooks::detach( 'rest_api_init' );
		add_action( 'rest_api_init', array( self::class, 'registerCompliantRoutes' ) );

		$walk  = ( new RoutePermissionWalker() )->walk( self::bootRestServer() );
		$route = '/' . self::SELFTEST_NAMESPACE;

		$this->assertSame( array(), $walk['violations'], "A compliant route was reported:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n" );
		$this->assertEqualsCanonicalizing( array( $route . '/compliant', $route . '/compliant/(?P<id>[\d]+)' ), $walk['plugin_routes'] );
		$this->assertNotContains( $route, $walk['plugin_routes'], 'The namespace index core registers was walked.' );
		$this->assertNotContains( '/wp/v2/posts', $walk['plugin_routes'], 'A core route was walked.' );
	}

	/**
	 * Tests that every endpoint at the product's `wp/v2` base is guarded by one of its controllers, the plugin's.
	 *
	 * Planted violation: in ProductPostType::arguments(), drop `rest_controller_class`: the base is
	 * served by core's default controller, and the walk reports it (the self-test below plants the
	 * same through `register_post_type_args`).
	 *
	 * @since 0.1.0
	 */
	public function test_the_product_base_is_served_by_the_plugins_controllers(): void {
		self::registerProductType();

		$server = self::bootRestServer();
		$walk   = ( new RoutePermissionWalker() )->walkPostTypeBase( $server, ProductCapabilities::POST_TYPE, ProductPostsController::class );
		$base   = '/wp/v2/' . ProductPostType::REST_BASE;

		$this->assertSame( array(), $walk['violations'], "The product's base breaks the ownership rule:\n" . RoutePermissionWalker::describe( $walk['violations'] ) . "\n" );
		$this->assertContains( $base, $walk['routes'] );
		$this->assertContains( $base . '/(?P<id>[\d]+)', $walk['routes'] );
		$this->assertContains( $base . '/(?P<id>[\d]+)/autosaves', $walk['routes'], 'The autosave routes were not walked.' );
		$this->assertContains( $base . '/(?P<parent>[\d]+)/revisions', $walk['routes'], 'The revision routes were not walked.' );
	}

	/**
	 * Tests that the product post type served by core's default controller is reported: the first carried plant.
	 *
	 * @since 0.1.0
	 */
	public function test_the_product_base_served_by_cores_controller_is_reported(): void {
		$without = static function ( array $args, string $name ): array {
			if ( ProductCapabilities::POST_TYPE === $name ) {
				unset( $args['rest_controller_class'] );
			}

			return $args;
		};

		add_filter( 'register_post_type_args', $without, 10, 2 );
		ProductPostType::register();

		try {
			$walk = ( new RoutePermissionWalker() )->walkPostTypeBase( self::bootRestServer(), ProductCapabilities::POST_TYPE, ProductPostsController::class );
		} finally {
			remove_filter( 'register_post_type_args', $without, 10 );
			ProductPostType::register();
		}

		$this->assertSame(
			array( array( '/wp/v2/' . ProductPostType::REST_BASE, '*', RoutePermissionWalker::RULE_FOREIGN_CONTROLLER ) ),
			array_map( static fn( array $violation ): array => array( $violation['route'], $violation['method'], $violation['rule'] ), $walk['violations'] )
		);
		$this->assertStringContainsString( 'WP_REST_Posts_Controller', $walk['violations'][0]['message'] ?? '' );
	}

	/**
	 * Tests that a `__return_true` route at the product's base is reported: the second carried plant.
	 *
	 * @since 0.1.0
	 */
	public function test_a_return_true_route_at_the_product_base_is_reported(): void {
		self::registerProductType();

		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'wp/v2',
					'/' . ProductPostType::REST_BASE . '/planted',
					array(
						'methods'             => 'GET',
						'callback'            => '__return_null',
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		$walk = ( new RoutePermissionWalker() )->walkPostTypeBase( self::bootRestServer(), ProductCapabilities::POST_TYPE, ProductPostsController::class );

		$this->assertSame(
			array( array( '/wp/v2/' . ProductPostType::REST_BASE . '/planted', 'GET', RoutePermissionWalker::RULE_NOT_THE_TYPES_CONTROLLER ) ),
			array_map( static fn( array $violation ): array => array( $violation['route'], $violation['method'], $violation['rule'] ), $walk['violations'] )
		);
		$this->assertStringContainsString( "the function '__return_true'", $walk['violations'][0]['message'] ?? '' );
	}

	/**
	 * Tests that a write endpoint at the product's base guarded by the controller's own read check is reported.
	 *
	 * The route is guarded by a genuine method of the genuine controller, so ownership alone would
	 * pass it: the check is of the wrong kind, on a route core does not register.
	 *
	 * Planted violation: in Modules::catalogRestInit(), after the controller is installed, register
	 * `/seocart-products/(?P<id>[\d]+)/planted` for POST, guarded by
	 * `array( get_post_type_object( 'seocart_product' )->get_rest_controller(), 'get_item_permissions_check' )`:
	 * the walk of the product's base fails on it.
	 *
	 * @since 0.1.0
	 */
	public function test_a_write_guarded_by_a_read_check_is_reported(): void {
		self::registerProductType();

		add_action(
			'rest_api_init',
			static function (): void {
				register_rest_route(
					'wp/v2',
					'/' . ProductPostType::REST_BASE . '/(?P<id>[\d]+)/planted',
					array(
						'methods'             => 'POST',
						'callback'            => '__return_null',
						'permission_callback' => array( get_post_type_object( ProductCapabilities::POST_TYPE )?->get_rest_controller(), 'get_item_permissions_check' ),
					)
				);
			},
			100
		);

		$walk = ( new RoutePermissionWalker() )->walkPostTypeBase( self::bootRestServer(), ProductCapabilities::POST_TYPE, ProductPostsController::class );

		$this->assertSame(
			array( array( '/wp/v2/' . ProductPostType::REST_BASE . '/(?P<id>[\d]+)/planted', 'POST', RoutePermissionWalker::RULE_WRONG_CHECK ) ),
			array_map( static fn( array $violation ): array => array( $violation['route'], $violation['method'], $violation['rule'] ), $walk['violations'] )
		);
	}

	/**
	 * Tests that the product's update guarded by the controller's read check instead of its update check is reported, method by method.
	 *
	 * @since 0.1.0
	 */
	public function test_an_update_guarded_by_the_read_check_is_reported(): void {
		self::registerProductType();

		$item = '/wp/v2/' . ProductPostType::REST_BASE . '/(?P<id>[\d]+)';

		add_filter(
			'rest_endpoints',
			static function ( array $endpoints ) use ( $item ): array {
				foreach ( $endpoints[ $item ] ?? array() as $index => $endpoint ) {
					// Before the server normalises them, the methods are the string the route was registered with.
					$methods = is_array( $endpoint ) ? $endpoint['methods'] ?? '' : '';
					$update  = is_array( $methods ) ? isset( $methods['PUT'] ) : str_contains( (string) $methods, 'PUT' );

					if ( $update && is_array( $endpoint['permission_callback'] ?? null ) ) {
						$endpoints[ $item ][ $index ]['permission_callback'] = array( $endpoint['permission_callback'][0], 'get_item_permissions_check' );
					}
				}

				return $endpoints;
			}
		);

		$walk = ( new RoutePermissionWalker() )->walkPostTypeBase( self::bootRestServer(), ProductCapabilities::POST_TYPE, ProductPostsController::class );

		$this->assertSame(
			array(
				array( $item, 'POST', RoutePermissionWalker::RULE_WRONG_CHECK ),
				array( $item, 'PUT', RoutePermissionWalker::RULE_WRONG_CHECK ),
				array( $item, 'PATCH', RoutePermissionWalker::RULE_WRONG_CHECK ),
			),
			array_map( static fn( array $violation ): array => array( $violation['route'], $violation['method'], $violation['rule'] ), $walk['violations'] )
		);
		$this->assertStringContainsString( 'update_item_permissions_check', $walk['violations'][0]['message'] ?? '' );
	}

	/**
	 * Tests that an autosave controller of another class than core's is reported.
	 *
	 * @since 0.1.0
	 */
	public function test_an_autosave_controller_of_another_class_is_reported(): void {
		$subclassed = static function ( array $args, string $name ): array {
			if ( ProductCapabilities::POST_TYPE === $name ) {
				$args['autosave_rest_controller_class'] = SubclassedAutosavesController::class;
			}

			return $args;
		};

		add_filter( 'register_post_type_args', $subclassed, 10, 2 );
		ProductPostType::register();

		try {
			$walk = ( new RoutePermissionWalker() )->walkPostTypeBase( self::bootRestServer(), ProductCapabilities::POST_TYPE, ProductPostsController::class );
		} finally {
			remove_filter( 'register_post_type_args', $subclassed, 10 );
			ProductPostType::register();
		}

		$this->assertSame(
			array( array( '/wp/v2/' . ProductPostType::REST_BASE, '*', RoutePermissionWalker::RULE_FOREIGN_CONTROLLER ) ),
			array_map( static fn( array $violation ): array => array( $violation['route'], $violation['method'], $violation['rule'] ), $walk['violations'] )
		);
		$this->assertStringContainsString( SubclassedAutosavesController::class, $walk['violations'][0]['message'] ?? '' );
	}

	/**
	 * Registers the product post type as the kernel does on `init`, when another test has unregistered it.
	 *
	 * @since 0.1.0
	 */
	private static function registerProductType(): void {
		if ( ! post_type_exists( ProductCapabilities::POST_TYPE ) ) {
			ProductPostType::register();
		}
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
		self::registerRoute( '/public-read-link', array( self::endpoint( 'LINK', PermissionCallback::publicRead() ) ) );
		self::registerRoute( '/no-schema', array( self::endpoint( 'GET', PermissionCallback::requiring( 'seocart_view_orders' ) ) ), false );

		register_rest_route(
			self::UPPERCASE_NAMESPACE,
			'/uppercase-namespace',
			array(
				self::endpoint( 'POST', '__return_true' ),
				'schema' => array( self::class, 'schema' ),
			)
		);

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
				self::endpoint( 'GET, HEAD', PermissionCallback::publicRead() ),
				self::endpoint( 'POST', PermissionCallback::requiring( 'seocart_manage_catalog' ) ),
			)
		);
		self::registerRoute(
			'/compliant/(?P<id>[\d]+)',
			array(
				self::endpoint( WP_REST_Server::DELETABLE, PermissionCallback::requiringOn( 'seocart_view_order', 'id' ) ),
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
	 * @return array{routes_visited: int, plugin_routes: list<string>, violations: list<array{route: string, method: string, rule: string, message: string}>} The walk.
	 */
	private function walkPlantedRoutes(): array {
		// The self-tests walk their fixtures alone: without the kernel's routes, whose namespace would hide the fixture spelt in capitals.
		KernelHooks::detach( 'rest_api_init' );
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
