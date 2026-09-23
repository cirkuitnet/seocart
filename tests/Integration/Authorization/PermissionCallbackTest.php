<?php
/**
 * Tests the permission-callback type through real REST requests
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Authorization;

use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Authorization\CapabilityMapper;
use SEOCart\Platform\Authorization\MetaCapabilityResolver;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Authorization\RequestPolicy;
use SEOCart\Tests\Support\ReloadsRoles;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Registers test routes guarded by each kind of PermissionCallback and dispatches requests to them.
 *
 * The one map_meta_cap callback is hooked with a test resolver for the declared meta capability
 * `seocart_view_order`, which knows one resource, 42, and records every identifier it is asked
 * about. What a callback may be built for is proven by the unit test of the same name.
 *
 * @since 0.1.0
 */
final class PermissionCallbackTest extends WP_UnitTestCase {

	use ReloadsRoles;

	/**
	 * The namespace of the test routes.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const NAMESPACE = 'seocart-selftest/v1';

	/**
	 * The identifiers the test resolver was asked about, in order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<mixed>
	 */
	private static array $resolved = array();

	/**
	 * Hooks the mapper, registers the test routes and boots a fresh REST server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		self::reloadRoles();

		self::$resolved = array();

		$mapper = new CapabilityMapper( new CapabilityDeclaration() );
		$mapper->registerMetaCapability( 'seocart_view_order', array( self::class, 'widgetResolver' ) );

		add_filter( 'map_meta_cap', array( $mapper, 'map' ), 10, 4 );
		add_action( 'rest_api_init', array( self::class, 'registerRoutes' ) );

		self::bootRestServer();
	}

	/**
	 * Discards the REST server, rolls the test back and reloads the roles.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: the next test must boot a server without these routes.
		$wp_rest_server = null;

		parent::tear_down();

		self::reloadRoles();
	}

	/**
	 * Tests that a capability is required: a visitor gets 401, a user without it 403, a user with it 200.
	 *
	 * @since 0.1.0
	 */
	public function test_a_capability_is_required(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( 401, $this->status( 'POST', '/capability', 0 ), 'A visitor who is not logged in.' );
		$this->assertSame( 403, $this->status( 'POST', '/capability', $subscriber ), 'A subscriber.' );
		$this->assertSame( 403, $this->status( 'POST', '/capability', $administrator ), 'An administrator whose role does not hold the capability yet.' );

		get_role( 'administrator' )->add_cap( 'seocart_manage_catalog' );

		$this->assertSame( 200, $this->status( 'POST', '/capability', $administrator ), 'An administrator whose role holds the capability.' );
	}

	/**
	 * Tests that a meta capability is checked on the resource the request names, and that no usable resource means no.
	 *
	 * @since 0.1.0
	 */
	public function test_a_meta_capability_is_checked_on_the_resource_the_request_names(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->add_cap( 'seocart_view_orders' );

		$this->assertSame( 200, $this->status( 'GET', '/widgets/42', $administrator ), 'The resource the resolver knows.' );
		$this->assertSame( 403, $this->status( 'GET', '/widgets/7', $administrator ), 'A resource the resolver cannot resolve.' );
		$this->assertSame( 403, $this->status( 'GET', '/widgets/abc-def', $administrator ), 'A string identifier the resolver cannot resolve.' );
		$this->assertSame( array( 42, 7, 'abc-def' ), self::$resolved, 'Digits reach the resolver as an integer, any other identifier as it is.' );

		self::$resolved = array();

		$this->assertSame( 403, $this->status( 'GET', '/widgets/0', $administrator ), 'Zero names no resource.' );
		$this->assertSame( 403, $this->status( 'GET', '/widgets-without-id', $administrator ), 'The request names no resource at all.' );
		$this->assertSame( array(), self::$resolved, 'Without a usable resource, the callback must answer no without asking.' );
	}

	/**
	 * Tests that the public-read marker lets anyone read, and is recognisable as the marker.
	 *
	 * @since 0.1.0
	 */
	public function test_the_public_read_marker_lets_anyone_read(): void {
		$this->assertSame( 200, $this->status( 'GET', '/public', 0 ) );

		$marker = PermissionCallback::publicRead();

		$this->assertTrue( $marker->isPublicRead() );
		$this->assertNull( $marker->capability() );
		$this->assertFalse( PermissionCallback::requiring( 'seocart_view_orders' )->isPublicRead() );
		$this->assertSame( 'seocart_view_orders', PermissionCallback::requiring( 'seocart_view_orders' )->capability() );
	}

	/**
	 * Tests that a policy can only narrow: it can deny a holder of the capability, but cannot admit anyone else.
	 *
	 * @since 0.1.0
	 */
	public function test_a_policy_can_only_narrow(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->add_cap( 'seocart_manage_catalog' );

		$this->assertSame( 403, $this->status( 'POST', '/denied-by-policy', $administrator ), 'A denying policy must win over the capability.' );
		$this->assertSame( 401, $this->status( 'POST', '/allowed-by-policy', 0 ), 'An allowing policy must not admit a visitor without the capability.' );
		$this->assertSame( 200, $this->status( 'POST', '/allowed-by-policy', $administrator ) );
		$this->assertSame( 401, $this->status( 'GET', '/public-denied-by-policy', 0 ), 'A policy narrows a public read too.' );
	}

	/**
	 * Tests that adding a policy leaves the original callback unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_with_policy_returns_a_narrowed_copy(): void {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$original      = PermissionCallback::requiring( 'seocart_manage_catalog' );
		$narrowed      = $original->withPolicy( self::policy( false ) );
		$request       = new WP_REST_Request( 'GET', '/' . self::NAMESPACE . '/any' );

		get_role( 'administrator' )->add_cap( 'seocart_manage_catalog' );
		wp_set_current_user( $administrator );

		$this->assertNotSame( $original, $narrowed );
		$this->assertTrue( $original( $request ), 'Adding a policy changed the original.' );
		$this->assertFalse( $narrowed( $request ) );
	}

	/**
	 * Registers the test routes. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerRoutes(): void {
		$routes = array(
			'/capability'              => array( 'POST', PermissionCallback::requiring( 'seocart_manage_catalog' ) ),
			'/widgets/(?P<id>[\w-]+)'  => array( 'GET', PermissionCallback::requiringOn( 'seocart_view_order', 'id' ) ),
			'/widgets-without-id'      => array( 'GET', PermissionCallback::requiringOn( 'seocart_view_order', 'id' ) ),
			'/public'                  => array( 'GET', PermissionCallback::publicRead() ),
			'/denied-by-policy'        => array( 'POST', PermissionCallback::requiring( 'seocart_manage_catalog' )->withPolicy( self::policy( false ) ) ),
			'/allowed-by-policy'       => array( 'POST', PermissionCallback::requiring( 'seocart_manage_catalog' )->withPolicy( self::policy( true ) ) ),
			'/public-denied-by-policy' => array( 'GET', PermissionCallback::publicRead()->withPolicy( self::policy( false ) ) ),
		);

		foreach ( $routes as $route => $endpoint ) {
			register_rest_route(
				self::NAMESPACE,
				$route,
				array(
					'methods'             => $endpoint[0],
					'callback'            => array( self::class, 'respond' ),
					'permission_callback' => $endpoint[1],
				)
			);
		}
	}

	/**
	 * Answers every test route.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Response An empty success.
	 */
	public static function respond(): WP_REST_Response {
		return new WP_REST_Response( array(), 200 );
	}

	/**
	 * Builds the test resolver: resource 42 needs the order-viewing primitive; nothing else resolves.
	 *
	 * @since 0.1.0
	 *
	 * @return MetaCapabilityResolver The resolver.
	 */
	public static function widgetResolver(): MetaCapabilityResolver {
		return new class() implements MetaCapabilityResolver {

			/**
			 * Records the identifier and resolves resource 42 only.
			 *
			 * @since 0.1.0
			 *
			 * @param int               $userId The user being checked.
			 * @param array<int, mixed> $args   The resource identifier first.
			 * @return list<string>|null The primitives, or null for any other resource.
			 */
			public function primitivesFor( int $userId, array $args ): ?array {
				PermissionCallbackTest::recordResolved( $args[0] ?? null );

				return 42 === ( $args[0] ?? null ) ? array( 'seocart_view_orders' ) : null;
			}
		};
	}

	/**
	 * Records an identifier the test resolver was asked about.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $identifier The identifier.
	 */
	public static function recordResolved( $identifier ): void {
		self::$resolved[] = $identifier;
	}

	/**
	 * Builds a policy with a fixed answer, which also checks that it is told the capability.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $allows What the policy answers.
	 * @return RequestPolicy The policy.
	 */
	private static function policy( bool $allows ): RequestPolicy {
		return new class( $allows ) implements RequestPolicy {

			/**
			 * What the policy answers.
			 *
			 * @since 0.1.0
			 *
			 * @var bool
			 */
			private bool $allows;

			/**
			 * Creates the policy.
			 *
			 * @since 0.1.0
			 *
			 * @param bool $allows What the policy answers.
			 */
			public function __construct( bool $allows ) {
				$this->allows = $allows;
			}

			/**
			 * Answers with the fixed value.
			 *
			 * @since 0.1.0
			 *
			 * @param WP_REST_Request $request    The request.
			 * @param string|null     $capability The capability already confirmed, or null for a public read.
			 * @return bool The fixed answer.
			 */
			public function allows( WP_REST_Request $request, ?string $capability ): bool {
				return $this->allows;
			}
		};
	}

	/**
	 * Dispatches a request to a test route as a user and returns the response status.
	 *
	 * Each request builds a new current-user object, as a real request does, so that a role
	 * changed since the previous request is read afresh instead of from cached capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param string $method HTTP method.
	 * @param string $path   Route path below the test namespace.
	 * @param int    $user   The user to act as, 0 for a visitor who is not logged in.
	 * @return int The HTTP status.
	 */
	private function status( string $method, string $path, int $user ): int {
		wp_set_current_user( 0 );
		wp_set_current_user( $user );

		return rest_do_request( new WP_REST_Request( $method, '/' . self::NAMESPACE . $path ) )->get_status();
	}

	/**
	 * Boots a fresh REST server, which fires `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	private static function bootRestServer(): void {
		global $wp_rest_server;

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- resets core's REST server global: a fresh server fires rest_api_init again and so registers the test routes.
		$wp_rest_server = null;

		rest_get_server();
	}
}
