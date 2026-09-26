<?php
/**
 * Tests that an unknown path or a wrong method under a plugin namespace is answered in the one error shape
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Rest;

use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Cart\Interfaces\StoreApi\StoreOperations;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use SEOCart\Tests\Support\ServesRequests;
use Spy_REST_Server;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * WordPress answers a request it finds no route for with its own `rest_no_route`, 404, for an
 * unknown path and for a method the route does not serve alike. Under the plugin's namespaces,
 * `seocart/v1` and `seocart/store/v1`, that answer takes the one error shape and the caching
 * headers, like every other answer there. Another plugin's route under the namespace, and every
 * route outside it, keep WordPress's answer.
 *
 * The routes are the fixture stock adjustment's, in `seocart/v1`, and the session read's, in the
 * Store API, each with another plugin's route beside it; they are served as from the web.
 *
 * Planted violations, one at a time:
 *
 * - in RestAdapter::isUnroutedPluginRequest(), return false first: the unknown paths and the
 *   wrong methods keep WordPress's shape and no caching header;
 * - in RestAdapter::isUnroutedPluginRequest(), delete the loop over the routes the adapter did not
 *   register: the wrong method on another plugin's route is reshaped.
 *
 * @since 0.1.0
 */
final class UnroutedRequestTest extends WP_UnitTestCase {

	use ServesRequests;

	/**
	 * The other plugin's route, below each plugin namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const FOREIGN_ROUTE = '/fixture-foreign-thing';

	/**
	 * The server.
	 *
	 * @since 0.1.0
	 *
	 * @var Spy_REST_Server
	 */
	private Spy_REST_Server $server;

	/**
	 * Registers the operations and the other plugin's routes on a spy server.
	 *
	 * @since 0.1.0
	 */
	public function set_up(): void {
		parent::set_up();

		$this->saveRequestGlobals();

		add_filter( 'wp_rest_server_class', static fn(): string => Spy_REST_Server::class );
		add_action( 'rest_api_init', array( self::class, 'registerForeignRoutes' ) );

		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );
		$registry->add( StoreOperations::GET_SESSION, array( StoreOperations::class, 'getSession' ) );

		$server = ( new OperationSurfaces( $registry ) )->server();

		$this->assertInstanceOf( Spy_REST_Server::class, $server );

		$this->server = $server;
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
	 * Provides unknown paths and wrong methods under each namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array{string, string, bool}> The method, the path, and whether it is the Store API's.
	 */
	public static function unrouted(): array {
		$stock = '/' . RestBinding::NAMESPACE . str_replace( '{item_id}', FixtureStockOperation::EXAMPLE_ITEM, FixtureStockOperation::ROUTE );

		return array(
			'an unknown path under seocart/v1'             => array( 'GET', '/' . RestBinding::NAMESPACE . '/nothing-here', false ),
			'an unknown path under the Store API'          => array( 'POST', '/' . RestBinding::STORE_NAMESPACE . '/nothing-here', true ),
			'an unknown path in another letter case'       => array( 'GET', '/SEOCart/Store/v1/nothing-here', true ),
			'a wrong method on an operation of seocart/v1' => array( 'GET', $stock, false ),
			'a wrong method on the session read'           => array( 'DELETE', '/' . RestBinding::STORE_NAMESPACE . StoreOperations::SESSION_ROUTE, true ),
		);
	}

	/**
	 * Tests that an unknown path or a wrong method under a plugin namespace gets the one shape and the caching headers.
	 *
	 * @since 0.1.0
	 *
	 * @dataProvider unrouted
	 *
	 * @param string $method The method.
	 * @param string $path   The path.
	 * @param bool   $store  Whether the path is the Store API's.
	 */
	public function test_an_unrouted_request_under_a_plugin_namespace_takes_the_one_shape( string $method, string $path, bool $store ): void {
		$served = $this->serve( $this->server, $method, $path );

		$this->assertSame( 404, $served['status'], 'WordPress answers an unknown path and a wrong method alike, with 404.' );
		$this->assertErrorShape( $served['body'], 'rest_no_route', 404 );
		$this->assertSame( array(), (array) $served['body']['data']['details'] );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null );

		if ( $store ) {
			$this->assertSame( 'Cookie', $served['headers']['Vary'] ?? null, 'A Store API answer varies by cookie.' );
		}
	}

	/**
	 * Tests that the caching headers reach the client when `?_envelope` has moved the answer into the body.
	 *
	 * @since 0.1.0
	 */
	public function test_an_enveloped_unrouted_request_still_sends_the_headers(): void {
		$served = $this->serve( $this->server, 'GET', '/' . RestBinding::STORE_NAMESPACE . '/nothing-here', array(), array(), array( '_envelope' => '1' ) );

		$this->assertSame( 200, $served['status'], 'An envelope is always 200.' );
		$this->assertSame( 404, $served['body']['status'] );
		$this->assertErrorShape( $served['body']['body'], 'rest_no_route', 404 );
		$this->assertSame( 'no-store, private', $served['headers']['Cache-Control'] ?? null );
	}

	/**
	 * Tests that a wrong method on another plugin's route under a plugin namespace, and an unknown path outside the namespaces, keep WordPress's answer.
	 *
	 * @since 0.1.0
	 */
	public function test_another_plugins_route_and_other_namespaces_are_left_alone(): void {
		foreach ( array( RestBinding::NAMESPACE, RestBinding::STORE_NAMESPACE ) as $plugin_namespace ) {
			$foreign = $this->serve( $this->server, 'POST', '/' . $plugin_namespace . self::FOREIGN_ROUTE );

			$this->assertSame( 404, $foreign['status'] );
			$this->assertSame( 'rest_no_route', $foreign['body']['code'] );
			$this->assertSame( array( 'status' => 404 ), $foreign['body']['data'], "The other plugin's route under {$plugin_namespace} keeps WordPress's shape." );
			$this->assertArrayNotHasKey( 'Cache-Control', $foreign['headers'] );
		}

		$outside = $this->serve( $this->server, 'GET', '/fixture-foreign/v1/nothing-here' );

		$this->assertSame( array( 'status' => 404 ), $outside['body']['data'] );
		$this->assertArrayNotHasKey( 'Cache-Control', $outside['headers'] );
	}

	/**
	 * Registers another plugin's GET route under each plugin namespace. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerForeignRoutes(): void {
		foreach ( array( RestBinding::NAMESPACE, RestBinding::STORE_NAMESPACE ) as $plugin_namespace ) {
			register_rest_route(
				$plugin_namespace,
				self::FOREIGN_ROUTE,
				array(
					'methods'             => 'GET',
					'callback'            => static fn(): WP_REST_Response => new WP_REST_Response( array( 'foreign' => true ) ),
					'permission_callback' => '__return_true',
				)
			);
		}
	}
}
