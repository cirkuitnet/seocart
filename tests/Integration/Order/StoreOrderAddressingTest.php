<?php
/**
 * Tests that every Store API route names an order by its uuid and by nothing else
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Order;

use SEOCart\Application\Operations\RestBinding;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Order\Interfaces\StoreApi\OrderStoreOperations;
use SEOCart\Platform\Authorization\PermissionCallback;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Walks every route of a booted REST server under `seocart/store/v1` and reports each one that
 * names an order by anything but a uuid.
 *
 * A storefront route that resolves an order from an integer id or from its number lets anyone
 * count through the orders; a uuid cannot be guessed. Names are read in snake case, so `orderNo`
 * reads as `order_no`. In any letter case of the namespace, a route parameter or any other input
 * names an order when:
 *
 * - its name identifies one: `order`, or `order` followed by an identifier's word, such as
 *   `order_id`, `order_number`, `order_ref` or `order_uuid`;
 * - the route is an order route, one with an `order` or `orders` segment anywhere in its path, and
 *   its name is a bare identifier's word: `id`, `number`, `ref`, `uuid` and the like;
 * - it is a route parameter that directly follows an `order` or `orders` segment, whatever its
 *   name, such as `/orders/(?P<slug>[^/]+)`;
 * - any other name with the word `order`, such as `order_key`, unless it is declared as text: an
 *   access key is a credential, checked against the order its uuid names, but an `order_key`
 *   declared as an integer is a lookup.
 *
 * Such a parameter or input must be declared as a uuid (its argument has `format: uuid`), and a
 * route parameter's pattern must not match digits only.
 *
 * The real walk boots a server as a request does, so it walks every route the plugin registers,
 * and it must see the order-status route. The self-test registers routes that break each rule,
 * and three that keep them, in the Store API's namespace.
 *
 * Planted violation, shown red and removed: in the kernel's `rest_api_init` closure in
 * Modules::kernelSubscribe(), after the adapter registers its routes, register
 * `register_rest_route( 'seocart/store/v1', '/orders/(?P<order_id>\d+)', array( 'methods' => 'GET', 'callback' => '__return_null', 'permission_callback' => PermissionCallback::publicRead() ) )`.
 * The real walk names `GET /seocart/store/v1/orders/(?P<order_id>\d+)`.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class StoreOrderAddressingTest extends WP_UnitTestCase {

	/**
	 * The segment the self-test fixtures are registered under, in the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SELFTEST = '/addressing-selftest';

	/**
	 * The path segments that make a route an order route.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const ORDER_SEGMENTS = array( 'order', 'orders' );

	/**
	 * The words that make a name an identifier: on an order route, alone; anywhere, after `order`.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const IDENTIFIER_WORDS = array( 'id', 'ids', 'number', 'no', 'num', 'ref', 'reference', 'uuid' );

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
	 * Tests that the plugin's own Store API routes name every order by its uuid.
	 *
	 * @since 0.1.0
	 */
	public function test_every_store_route_names_an_order_by_its_uuid_only(): void {
		$walk = self::walk( self::bootRestServer() );
		$rest = OrderStoreOperations::getStatus()->rest();

		$this->assertNotNull( $rest );
		$this->assertContains( RestAdapter::serverRoute( $rest ), $walk['routes'], 'The walk did not see the order-status route, so a clean walk would prove nothing.' );
		$this->assertSame( array(), $walk['violations'], "Store API routes name an order by something other than its uuid:\n  " . implode( "\n  ", $walk['violations'] ) . "\n" );
	}

	/**
	 * Tests that each planted route is reported for the rule it breaks, and the three compliant ones are not.
	 *
	 * Planted violations in the walk, each of which must make this test fail:
	 * - in namesAnOrder(), leave out the order-route rule: `?id=` and `?number=` on an order route,
	 *   and `/orders/by-number/(?P<number>[^/]+)`, are no longer reported;
	 * - in namesAnOrder(), leave out the rule for a parameter after an order segment:
	 *   `/orders/(?P<slug>[^/]+)` is no longer reported;
	 * - in namesAnOrder(), treat every other `order` name as a credential, declared as text or not:
	 *   the integer `order_key` lookup is no longer reported;
	 * - in isDigits(), answer false: `/orders/(?P<uuid>\d+)`, declared a uuid but matching digits, is
	 *   no longer reported.
	 *
	 * @since 0.1.0
	 */
	public function test_every_planted_route_is_reported(): void {
		add_action( 'rest_api_init', array( self::class, 'registerPlantedRoutes' ) );

		$walk  = self::walk( self::bootRestServer() );
		$route = '/' . RestBinding::STORE_NAMESPACE . self::SELFTEST;

		$this->assertEqualsCanonicalizing(
			array(
				"GET {$route}/orders/(?P<order_id>\d+): the path parameter order_id names an order, and is not a uuid",
				"GET {$route}/orders/(?P<id>[\d]+): the path parameter id names an order, and is not a uuid",
				"GET {$route}/orders/(?P<uuid>\d+): the path parameter uuid names an order, and is not a uuid",
				"GET {$route}/orders/(?P<slug>[^/]+): the path parameter slug names an order, and is not a uuid",
				"GET {$route}/by-number/(?P<order_number>[^/]+): the path parameter order_number names an order, and is not a uuid",
				"GET {$route}/orders/by-number/(?P<number>[^/]+): the path parameter number names an order, and is not a uuid",
				"GET {$route}/orders: the input id names an order, and is not a uuid",
				"GET {$route}/orders/lookup: the input number names an order, and is not a uuid",
				"GET {$route}/lookup: the input order_id names an order, and is not a uuid",
				"GET {$route}/lookup: the input order_number names an order, and is not a uuid",
				"GET {$route}/lookup: the input orderNo names an order, and is not a uuid",
				"GET {$route}/lookup-by-key: the input order_key names an order, and is not a uuid",
			),
			array_values( array_filter( $walk['violations'], static fn( string $violation ): bool => str_contains( $violation, self::SELFTEST ) ) )
		);

		foreach ( array( '/orders/(?P<uuid>[^/]+)', '/things/(?P<order_uuid>[^/]+)', '/lookup-with-key' ) as $compliant ) {
			$this->assertContains( $route . $compliant, $walk['routes'], "The compliant fixture {$compliant} was not walked, so its clean result would prove nothing." );
		}
	}

	/**
	 * Registers the self-test's routes. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public static function registerPlantedRoutes(): void {
		$uuid    = array(
			'type'   => 'string',
			'format' => 'uuid',
		);
		$integer = array( 'type' => 'integer' );
		$text    = array( 'type' => 'string' );

		self::registerRoute( '/orders/(?P<order_id>\d+)', array( 'order_id' => $integer ) );
		self::registerRoute( '/orders/(?P<id>[\d]+)', array( 'id' => $integer ) );
		self::registerRoute( '/orders/(?P<uuid>\d+)', array( 'uuid' => $uuid ) );
		self::registerRoute( '/orders/(?P<slug>[^/]+)', array( 'slug' => $text ) );
		self::registerRoute( '/by-number/(?P<order_number>[^/]+)', array( 'order_number' => $text ) );
		self::registerRoute( '/orders/by-number/(?P<number>[^/]+)', array( 'number' => $text ) );
		self::registerRoute( '/orders', array( 'id' => $integer ) );
		self::registerRoute( '/orders/lookup', array( 'number' => $text ) );
		self::registerRoute(
			'/lookup',
			array(
				'order_id'     => $integer,
				'order_number' => $text,
				'orderNo'      => $text,
			)
		);
		self::registerRoute( '/lookup-by-key', array( 'order_key' => $integer ) );
		self::registerRoute( '/orders/(?P<uuid>[^/]+)', array( 'uuid' => $uuid ) );
		self::registerRoute( '/things/(?P<order_uuid>[^/]+)', array( 'order_uuid' => $uuid ) );
		self::registerRoute( '/lookup-with-key', array( 'order_key' => $text ) );
	}

	/**
	 * Walks the Store API's routes.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server $server A server on which `rest_api_init` has run.
	 * @return array{routes: list<string>, violations: list<string>} The Store API routes walked, and one line per violation.
	 */
	private static function walk( WP_REST_Server $server ): array {
		$walk = array(
			'routes'     => array(),
			'violations' => array(),
		);

		foreach ( $server->get_routes() as $route => $endpoints ) {
			$route = (string) $route;

			if ( 0 !== strncasecmp( ltrim( $route, '/' ), RestBinding::STORE_NAMESPACE . '/', strlen( RestBinding::STORE_NAMESPACE ) + 1 ) ) {
				continue;
			}

			$walk['routes'][] = $route;
			$parameters       = self::pathParameters( $route );
			$orderRoute       = self::isOrderRoute( $route );

			foreach ( $endpoints as $endpoint ) {
				$methods = implode( ', ', array_keys( (array) ( $endpoint['methods'] ?? array() ) ) );
				$args    = (array) ( $endpoint['args'] ?? array() );

				foreach ( $parameters as $name => $parameter ) {
					$arg = (array) ( $args[ $name ] ?? array() );

					if ( self::namesAnOrder( $name, $arg, $orderRoute, $parameter['after'] ) && ( self::isDigits( $parameter['pattern'] ) || ! self::isUuid( $arg ) ) ) {
						$walk['violations'][] = "{$methods} {$route}: the path parameter {$name} names an order, and is not a uuid";
					}
				}

				foreach ( $args as $name => $arg ) {
					if ( ! isset( $parameters[ $name ] ) && self::namesAnOrder( (string) $name, (array) $arg, $orderRoute, '' ) && ! self::isUuid( (array) $arg ) ) {
						$walk['violations'][] = "{$methods} {$route}: the input {$name} names an order, and is not a uuid";
					}
				}
			}
		}

		return $walk;
	}

	/**
	 * Returns a route's named parameters, each with its pattern and the path segment before it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route The route, as the server lists it.
	 * @return array<string, array{pattern: string, after: string}> The parameters, by name.
	 */
	private static function pathParameters( string $route ): array {
		preg_match_all( self::parameterPattern(), $route, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		$parameters = array();

		foreach ( $matches as $match ) {
			$before = explode( '/', rtrim( substr( $route, 0, $match[0][1] ), '/' ) );

			$parameters[ $match[1][0] ] = array(
				'pattern' => $match['pattern'][0],
				'after'   => (string) end( $before ),
			);
		}

		return $parameters;
	}

	/**
	 * Tells whether a route is an order route: one with an `order` or `orders` segment anywhere in its path.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route The route, as the server lists it.
	 * @return bool True when a literal segment is `order` or `orders`, in any letter case.
	 */
	private static function isOrderRoute( string $route ): bool {
		$literal = (string) preg_replace( self::parameterPattern(), '', $route );

		return array() !== array_intersect( array_map( 'strtolower', explode( '/', $literal ) ), self::ORDER_SEGMENTS );
	}

	/**
	 * Tells whether a parameter or input names an order.
	 *
	 * @since 0.1.0
	 *
	 * @param string       $name       Its name.
	 * @param array<mixed> $arg        Its argument's schema.
	 * @param bool         $orderRoute Whether the route is an order route.
	 * @param string       $after      For a route parameter, the path segment before it; empty for any other input.
	 * @return bool True when it names an order, by the rules the class lists.
	 */
	private static function namesAnOrder( string $name, array $arg, bool $orderRoute, string $after ): bool {
		if ( in_array( strtolower( $after ), self::ORDER_SEGMENTS, true ) ) {
			return true;
		}

		$words = explode( '_', strtolower( (string) preg_replace( '/(?<=[a-z0-9])([A-Z])/', '_$1', $name ) ) );

		if ( $orderRoute && self::isIdentifier( $words ) ) {
			return true;
		}

		$order = array_keys( array_intersect( $words, self::ORDER_SEGMENTS ) );

		if ( array() === $order ) {
			return false;
		}

		$rest = array_slice( $words, $order[0] + 1 );

		return array() === $rest || self::isIdentifier( $rest ) || ! self::isText( $arg );
	}

	/**
	 * Tells whether words are one identifier's word, such as `id` or `number`.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $words The words.
	 * @return bool True for exactly one word of IDENTIFIER_WORDS.
	 *
	 * @phpstan-param list<string> $words
	 */
	private static function isIdentifier( array $words ): bool {
		return 1 === count( $words ) && in_array( $words[0], self::IDENTIFIER_WORDS, true );
	}

	/**
	 * Tells whether an argument is declared as text, as a credential such as an access key is.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $arg The argument's schema.
	 * @return bool True when its type is `string` and nothing else.
	 */
	private static function isText( array $arg ): bool {
		return 'string' === ( $arg['type'] ?? null );
	}

	/**
	 * Tells whether a parameter's pattern matches digits only, as an integer id's does.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern The pattern inside the named group.
	 * @return bool True for `\d+`, `[\d]+`, `[0-9]+` and their bounded forms.
	 */
	private static function isDigits( string $pattern ): bool {
		return 1 === preg_match( '/^(?:\\\\d|\[\\\\d\]|\[0-9\])(?:[+*]|\{\d+(?:,\d*)?\})?$/', $pattern );
	}

	/**
	 * Tells whether an argument is declared as a uuid.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $arg The argument's schema.
	 * @return bool True when its format is `uuid`.
	 */
	private static function isUuid( array $arg ): bool {
		return 'uuid' === ( $arg['format'] ?? null );
	}

	/**
	 * Returns the pattern of a route's named group: `(?P<name>pattern)`, parentheses balanced.
	 *
	 * @since 0.1.0
	 *
	 * @return string The regular expression.
	 */
	private static function parameterPattern(): string {
		return '/\(\?P<(\w+)>(?<pattern>(?:[^()]++|\((?&pattern)\))*)\)/';
	}

	/**
	 * Registers one self-test route, a public read, in the Store API's namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string                              $route The route below the self-test segment.
	 * @param array<string, array<string, mixed>> $args  Its arguments.
	 */
	private static function registerRoute( string $route, array $args ): void {
		register_rest_route(
			RestBinding::STORE_NAMESPACE,
			self::SELFTEST . $route,
			array(
				'methods'             => 'GET',
				'callback'            => '__return_null',
				'permission_callback' => PermissionCallback::publicRead(),
				'args'                => $args,
			)
		);
	}

	/**
	 * Boots a fresh REST server, which fires `rest_api_init`.
	 *
	 * @since 0.1.0
	 *
	 * @return WP_REST_Server The server.
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
