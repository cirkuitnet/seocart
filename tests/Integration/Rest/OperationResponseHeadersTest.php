<?php
/**
 * Tests that no response of an operation route may be cached: the route walk
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Integration\Rest;

use SEOCart\Application\Operations\Annotations;
use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\Operations;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Interfaces\Operations\RestAdapter;
use SEOCart\Platform\Authorization\CapabilityDeclaration;
use SEOCart\Platform\Rest\CachePolicy;
use SEOCart\Support\Schema\FieldSpec;
use SEOCart\Support\Schema\FieldType;
use SEOCart\Support\Schema\ResourceSchema;
use SEOCart\Tests\Fixtures\Operations\FixtureStockOperation;
use SEOCart\Tests\Support\OperationSurfaces;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Walks every route the REST adapter registered for an operation and sends it each kind of request
 * a client can send, through the REST server's own dispatch: a visitor's, one from a user without
 * the capability, bad input, one the service fails on unexpectedly, one that succeeds, and OPTIONS.
 * Every response must carry `Cache-Control: no-store, private`; `Vary: Cookie` exactly when
 * WordPress authenticated the user with its login cookie.
 *
 * The routes are found on the server, by the operation marker on their endpoints, and must be
 * exactly the routes the registry declares, so a route the walk misses fails it. The production
 * registry declares none yet; the walk also runs over the fixture and a read-only variant, which
 * cover both methods.
 *
 * @group contract
 *
 * @since 0.1.0
 */
final class OperationResponseHeadersTest extends WP_UnitTestCase {

	/**
	 * The Cache-Control header every response must carry, written out rather than read from the
	 * policy, so a change to the policy fails here.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CACHE_CONTROL = 'no-store, private';

	/**
	 * Resets WordPress's record of cookie authentication and the REST server.
	 *
	 * @since 0.1.0
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'] );

		OperationSurfaces::discard();

		parent::tear_down();
	}

	/**
	 * Tests that the production registry's routes, if any, are walked and pass.
	 *
	 * @since 0.1.0
	 */
	public function test_every_production_operation_route_is_not_cacheable(): void {
		$problems = $this->walk( Operations::registry(), false );

		$this->assertSame( array(), $problems['violations'], implode( "\n", $problems['violations'] ) );
	}

	/**
	 * Tests every response of the fixture routes, for a request that is not cookie-authenticated.
	 *
	 * @since 0.1.0
	 */
	public function test_every_response_of_every_route_is_not_cacheable(): void {
		$walk = $this->walk( self::fixtureRegistry(), false );

		$this->assertSame( array(), $walk['violations'], implode( "\n", $walk['violations'] ) );
		$this->assertSame( array( 'GET', 'POST' ), $walk['methods'], 'The walk did not cover both methods.' );
		$this->assertSame( array( 200, 400, 401, 403, 500 ), $walk['statuses'], 'The walk did not see every kind of response.' );
		$this->assertSame( 12, $walk['responses'] );
	}

	/**
	 * Tests every response of the fixture routes for a cookie-authenticated user: each also varies by Cookie.
	 *
	 * @since 0.1.0
	 */
	public function test_a_cookie_authenticated_response_varies_by_cookie(): void {
		$walk = $this->walk( self::fixtureRegistry(), true );

		$this->assertSame( array(), $walk['violations'], implode( "\n", $walk['violations'] ) );
		$this->assertSame( 12, $walk['responses'] );
	}

	/**
	 * Tests that a route outside the plugin is left alone while the operation routes are registered:
	 * a success, an error and an OPTIONS answer carry no caching header of the plugin's, and the
	 * error data is WordPress's, unchanged.
	 *
	 * @since 0.1.0
	 */
	public function test_a_route_outside_the_plugin_is_left_alone(): void {
		add_action( 'rest_api_init', array( self::class, 'registerForeignRoute' ) );

		$user = self::factory()->user->create();

		wp_set_current_user( $user );
		self::recordValidLoginCookie();

		$server = ( new OperationSurfaces( self::fixtureRegistry() ) )->server();

		$this->assertArrayHasKey( '/fixture-foreign/v1/things', $server->get_routes(), 'The foreign route is not registered, so the test would prove nothing.' );

		$responses = array();

		foreach ( array( 'GET', 'POST', 'OPTIONS' ) as $method ) {
			$responses[ $method ] = $server->dispatch( new WP_REST_Request( $method, '/fixture-foreign/v1/things' ) );

			$this->assertArrayNotHasKey( 'Cache-Control', $responses[ $method ]->get_headers(), "{$method}: the plugin set a caching header on a route that is not its own." );
			$this->assertArrayNotHasKey( 'Vary', $responses[ $method ]->get_headers(), "{$method}: the plugin set Vary on a route that is not its own." );
		}

		$this->assertSame( array( 200, 418, 200 ), array( $responses['GET']->get_status(), $responses['POST']->get_status(), $responses['OPTIONS']->get_status() ) );
		$this->assertSame( '{"status":418,"params":["x"]}', wp_json_encode( $responses['POST']->get_data()['data'] ), 'The error data is WordPress\'s, as the route gave it.' );
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
	 * Tests that an existing Vary header is kept and Cookie is added once.
	 *
	 * @since 0.1.0
	 */
	public function test_vary_keeps_what_is_there_and_names_cookie_once(): void {
		wp_set_current_user( self::factory()->user->create() );

		self::recordValidLoginCookie();

		$response = new WP_REST_Response();
		$response->header( 'Vary', 'Origin' );

		CachePolicy::apply( $response );
		CachePolicy::apply( $response );

		$this->assertSame( 'Origin, Cookie', $response->get_headers()['Vary'] );
	}

	/**
	 * Tests that a user authenticated otherwise than by the login cookie, and a visitor holding a
	 * valid cookie without a nonce, are not cookie-authenticated.
	 *
	 * @since 0.1.0
	 */
	public function test_only_a_user_the_login_cookie_authenticated_is_cookie_authenticated(): void {
		wp_set_current_user( self::factory()->user->create() );

		$this->assertFalse( CachePolicy::isCookieAuthenticated(), 'A user WordPress authenticated otherwise, such as with an application password.' );

		self::recordValidLoginCookie();

		$this->assertTrue( CachePolicy::isCookieAuthenticated() );

		wp_set_current_user( 0 );

		$this->assertFalse( CachePolicy::isCookieAuthenticated(), 'WordPress logs a cookie without a nonce out: the answer is a visitor\'s.' );
	}

	/**
	 * Declares a read-only variant of the fixture, served with GET.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationDefinition The definition.
	 */
	public static function readOnlyDefinition(): OperationDefinition {
		$fixture = FixtureStockOperation::definition();

		return new OperationDefinition(
			id: 'fixture_stock.show_stock',
			label: $fixture->label(),
			summary: 'Shows the stock level of one fixture item.',
			input: array(
				$fixture->input()[0],
				new FieldSpec(
					name: 'history',
					type: FieldType::Integer,
					description: 'How many past adjustments to include.',
					label: static fn(): string => 'History',
					example: 3,
					minimum: 0
				),
			),
			output: new ResourceSchema( 'FixtureStockShown', array( $fixture->output()->fields()[0], $fixture->output()->fields()[1] ) ),
			capability: $fixture->capability(),
			resource_field: null,
			errors: array(),
			annotations: new Annotations( read_only: true, destructive: false, idempotent: true ),
			service: $fixture->service(),
			rest: new RestBinding( '/fixture-stock/{item_id}' )
		);
	}

	/**
	 * Registers a registry's operations and sends every kind of request to every operation route.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry      The operations.
	 * @param bool              $cookie_authed Whether WordPress authenticated the users by cookie.
	 * @return array{violations: list<string>, methods: list<string>, statuses: list<int>, responses: int} What the walk found.
	 */
	private function walk( OperationRegistry $registry, bool $cookie_authed ): array {
		$definitions = array();

		foreach ( $registry->all() as $definition ) {
			$definitions[ $definition->id() ] = $definition;
		}

		$service  = new class( $registry ) {

			/**
			 * Whether the next call fails unexpectedly.
			 *
			 * @var bool
			 */
			public bool $fail = false;

			/**
			 * An example of every output field of every operation, keyed by wire name.
			 *
			 * @var array<string, int|string>
			 */
			private array $examples = array();

			/**
			 * Collects the examples.
			 *
			 * @param OperationRegistry $registry The operations.
			 */
			public function __construct( OperationRegistry $registry ) {
				foreach ( $registry->all() as $definition ) {
					foreach ( $definition->output()->fields() as $field ) {
						$this->examples[ $field->name() ] = $field->example();
					}
				}
			}

			/**
			 * Answers every service method: the examples, or an unexpected failure.
			 *
			 * @param string       $name      The method.
			 * @param array<mixed> $arguments The input and the actor.
			 * @return array<string, int|string> The examples.
			 *
			 * @throws \RuntimeException When the call is to fail.
			 */
			public function __call( string $name, array $arguments ): array {
				unset( $name, $arguments );

				if ( $this->fail ) {
					throw new \RuntimeException( 'A failure the walk plants.' );
				}

				return $this->examples;
			}
		};
		$surfaces = new OperationSurfaces( $registry, $service );
		$server   = $surfaces->server();
		$found    = array();
		$walk     = array(
			'violations' => array(),
			'methods'    => array(),
			'statuses'   => array(),
			'responses'  => 0,
		);

		foreach ( $server->get_routes() as $route => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				if ( isset( $endpoint[ RestAdapter::OPERATION_KEY ] ) ) {
					$found[ (string) $route ] = $definitions[ $endpoint[ RestAdapter::OPERATION_KEY ] ] ?? null;
				}
			}
		}

		$declared = array();

		foreach ( $definitions as $definition ) {
			if ( null !== $definition->rest() ) {
				$declared[] = RestAdapter::serverRoute( $definition->rest() );
			}
		}

		$routes = array_keys( $found );

		$declared = array_values( array_unique( $declared ) );

		sort( $routes );
		sort( $declared );

		if ( $routes !== $declared ) {
			$walk['violations'][] = 'The operation routes on the server [' . implode( ', ', $routes ) . '] are not the ones the registry declares [' . implode( ', ', $declared ) . '].';
		}

		$capable = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$unable  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		foreach ( ( new CapabilityDeclaration() )->primitives() as $capability ) {
			get_user_by( 'id', $capable )->add_cap( $capability );
		}

		foreach ( $found as $route => $definition ) {
			if ( null === $definition || null === $definition->rest() ) {
				$walk['violations'][] = "The route {$route} is marked as an operation's, but no operation of the registry declares it.";
				continue;
			}

			$walk['methods'][] = (string) $definition->httpMethod();

			$cases = array(
				'a visitor'                        => array( 0, self::exampleInput( $definition ), false ),
				'a user without the capability'    => array( $unable, self::exampleInput( $definition ), false ),
				'bad input'                        => array( $capable, self::badInput( $definition ), false ),
				'a service that fails'             => array( $capable, self::exampleInput( $definition ), true ),
				'a user who may run the operation' => array( $capable, self::exampleInput( $definition ), false ),
				'OPTIONS'                          => array( $capable, array(), false ),
			);

			foreach ( $cases as $case => list( $user, $input, $fails ) ) {
				wp_set_current_user( $user );

				if ( $cookie_authed ) {
					self::recordValidLoginCookie();
				}

				$service->fail = $fails;
				$response      = $this->send( $server, $definition, 'OPTIONS' === $case ? 'OPTIONS' : (string) $definition->httpMethod(), $input );
				$headers       = $response->get_headers();
				$label         = $definition->httpMethod() . ' ' . $route . ', ' . $case . ' (' . $response->get_status() . ')';

				++$walk['responses'];
				$walk['statuses'][] = $response->get_status();

				if ( self::CACHE_CONTROL !== ( $headers['Cache-Control'] ?? null ) ) {
					$walk['violations'][] = "{$label}: Cache-Control is " . var_export( $headers['Cache-Control'] ?? null, true ) . ', not ' . self::CACHE_CONTROL . '.';
				}

				$varies = in_array( 'cookie', array_map( 'trim', explode( ',', strtolower( (string) ( $headers['Vary'] ?? '' ) ) ) ), true );

				if ( ( $cookie_authed && 0 !== $user ) !== $varies ) {
					$walk['violations'][] = "{$label}: Vary " . ( $varies ? 'names Cookie, but the request was not cookie-authenticated.' : 'does not name Cookie, but the request was cookie-authenticated.' );
				}
			}
		}

		$walk['methods']  = array_values( array_unique( $walk['methods'] ) );
		$walk['statuses'] = array_values( array_unique( $walk['statuses'] ) );

		sort( $walk['methods'] );
		sort( $walk['statuses'] );

		return $walk;
	}

	/**
	 * Sends a request to an operation's route through the server's dispatch.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server       $server     The server.
	 * @param OperationDefinition  $definition The operation.
	 * @param string               $method     The HTTP method.
	 * @param array<string, mixed> $input      The input: route parameters go in the path, the rest in
	 *                                         the query for GET and in a JSON body otherwise.
	 * @return WP_REST_Response The response.
	 */
	private function send( WP_REST_Server $server, OperationDefinition $definition, string $method, array $input ): WP_REST_Response {
		$rest  = $definition->rest();
		$route = null === $rest ? '' : $rest->route();

		foreach ( null === $rest ? array() : $rest->pathParameters() as $name ) {
			$example = '';

			foreach ( $definition->input() as $field ) {
				if ( $field->name() === $name ) {
					$example = (string) $field->example();
				}
			}

			$route = str_replace( '{' . $name . '}', rawurlencode( (string) ( $input[ $name ] ?? $example ) ), $route );
			unset( $input[ $name ] );
		}

		$request = new WP_REST_Request( $method, '/' . RestBinding::NAMESPACE . $route );

		if ( 'GET' === $method ) {
			$request->set_query_params( $input );
		} elseif ( array() !== $input ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $input ) );
		}

		return $server->dispatch( $request );
	}

	/**
	 * Returns an input made of every field's example.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @return array<string, int|string> The input.
	 */
	private static function exampleInput( OperationDefinition $definition ): array {
		$input = array();

		foreach ( $definition->input() as $field ) {
			$input[ $field->name() ] = $field->example();
		}

		return $input;
	}

	/**
	 * Returns an input the schema refuses: the examples, with the first integer field given text.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition $definition The operation.
	 * @return array<string, int|string> The input.
	 */
	private static function badInput( OperationDefinition $definition ): array {
		$input = self::exampleInput( $definition );
		$path  = null === $definition->rest() ? array() : $definition->rest()->pathParameters();

		foreach ( $definition->input() as $field ) {
			if ( FieldType::Integer === $field->type() && ! in_array( $field->name(), $path, true ) ) {
				$input[ $field->name() ] = 'not a number';

				return $input;
			}
		}

		foreach ( $path as $name ) {
			$input[ $name ] = 'not-a-valid-value';
		}

		return $input;
	}

	/**
	 * Records, as WordPress does while it determines the user, that the request's login cookie was valid.
	 *
	 * @since 0.1.0
	 */
	private static function recordValidLoginCookie(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress's own record of cookie authentication, which the policy reads.
		$GLOBALS['wp_rest_auth_cookie'] = true;
	}

	/**
	 * Returns a registry holding the fixture and its read-only variant.
	 *
	 * @since 0.1.0
	 *
	 * @return OperationRegistry The registry.
	 */
	private static function fixtureRegistry(): OperationRegistry {
		$registry = new OperationRegistry();

		FixtureStockOperation::register( $registry );
		$registry->add( 'fixture_stock.show_stock', array( self::class, 'readOnlyDefinition' ) );

		return $registry;
	}
}
