<?php
/**
 * RoutePermissionWalker: finds SEOCart REST routes whose permission or schema breaks the rules
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

use SEOCart\Platform\Authorization\PermissionCallback;
use WP_REST_Server;

/**
 * Walks the routes of a booted REST server and reports every violation of the permission rules.
 *
 * Every route whose namespace, or whose path, starts with `seocart` in any letter case is
 * examined: WordPress matches routes without regard to case, so `SEOCart/v1` serves a request
 * for `seocart/v1` and is the plugin's too. Each of its endpoints must be guarded by the
 * plugin's permission-callback type, and the route must have a schema. "Went through the
 * shared factory" is checked structurally: the callback must be a PermissionCallback, which
 * cannot be built for a capability the plugin does not declare. Any other callable, whether
 * `__return_true`, a closure, a function name or an array callable, is a violation whatever it
 * returns, because it escapes the one permission path. The public-read marker is a
 * PermissionCallback too, and it is allowed only on an endpoint whose every method is GET or
 * HEAD: an allow-list, so a method nobody thought of, such as LINK, is refused as well.
 *
 * The one route that is skipped is the namespace index core registers for every namespace,
 * `/<namespace>`, served by WP_REST_Server::get_namespace_index(): it is core's, and core gives
 * it no permission callback and no schema.
 *
 * A post type's `wp/v2` base is walked apart, by walkPostTypeBase(): its routes are core-shaped,
 * served by WordPress's controllers for the type, so their permission callbacks are the
 * controllers' own methods. There the rule is ownership and core's mapping. Every endpoint under
 * the base must be guarded by a method of one of the post type's three controllers, the posts
 * controller and the autosave and revision controllers core builds from it, compared by identity;
 * the posts controller must be the plugin's class, and the other two core's own classes. And the
 * method must be the one core guards that route and HTTP method with: on each route core
 * registers, the expected controller's `get_items_`, `create_item_`, `get_item_`, `update_item_`
 * or `delete_item_permissions_check`. A closure, `__return_true`, a function name or any other
 * object is a violation, and so are core's default controller, a check of the wrong kind, such
 * as a write guarded by a read check, and a route core does not register.
 *
 * The walk also returns the plugin routes it examined, so that the operations module can add
 * its own check over the same list: that every route resolves to one operation definition, and is
 * guarded by the capability that definition declares. This walk does not know the definitions, so
 * a PermissionCallback for another declared capability passes here and is caught there.
 *
 * @since 0.1.0
 */
final class RoutePermissionWalker {

	/**
	 * Rule: an endpoint has no permission callback.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_MISSING_CALLBACK = 'permission-callback-missing';

	/**
	 * Rule: an endpoint's permission callback is not the plugin's permission-callback type.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_FOREIGN_CALLBACK = 'permission-callback-not-the-plugin-type';

	/**
	 * Rule: an endpoint marked as a public read accepts a method other than GET or HEAD.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_PUBLIC_READ_BEYOND_GET = 'public-read-on-a-method-other-than-get-or-head';

	/**
	 * Rule: a route has no schema.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_MISSING_SCHEMA = 'schema-missing';

	/**
	 * Rule: a post type's `wp/v2` base is not served by the plugin's controller class.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_FOREIGN_CONTROLLER = 'post-type-controller-not-the-plugins';

	/**
	 * Rule: an endpoint under a post type's `wp/v2` base is guarded by something other than one of the post type's controllers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_NOT_THE_TYPES_CONTROLLER = 'permission-callback-not-the-post-types-controller';

	/**
	 * Rule: an endpoint under a post type's `wp/v2` base is guarded by another check than the one core guards it with, or is on a route core does not register.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_WRONG_CHECK = 'permission-callback-not-the-endpoints-check';

	/**
	 * The routes core registers under a post type's base, by the part after the base: which of the three controllers guards each, and whether it names one item.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const BASE_ROUTES = array(
		''                                           => array( 'posts', 'collection' ),
		'/(?P<id>[\d]+)'                             => array( 'posts', 'item' ),
		'/(?P<id>[\d]+)/autosaves'                   => array( 'autosaves', 'collection' ),
		'/(?P<parent>[\d]+)/autosaves/(?P<id>[\d]+)' => array( 'revisions', 'item' ),
		'/(?P<parent>[\d]+)/revisions'               => array( 'revisions', 'collection' ),
		'/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)' => array( 'revisions', 'item' ),
	);

	/**
	 * The permission check core guards each HTTP method with, on a collection route and on an item route.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, string>>
	 */
	private const CHECKS = array(
		'collection' => array(
			'GET'  => 'get_items_permissions_check',
			'HEAD' => 'get_items_permissions_check',
			'POST' => 'create_item_permissions_check',
		),
		'item'       => array(
			'GET'    => 'get_item_permissions_check',
			'HEAD'   => 'get_item_permissions_check',
			'POST'   => 'update_item_permissions_check',
			'PUT'    => 'update_item_permissions_check',
			'PATCH'  => 'update_item_permissions_check',
			'DELETE' => 'delete_item_permissions_check',
		),
	);

	/**
	 * The prefix of every namespace, and every route path, that the walk examines, in any letter case.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLUGIN_PREFIX = 'seocart';

	/**
	 * The only HTTP methods a public-read endpoint may accept.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const PUBLIC_READ_METHODS = array( 'GET', 'HEAD' );

	/**
	 * Walks every route of a booted server.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server $server A server on which `rest_api_init` has run.
	 * @return array{routes_visited: int, plugin_routes: list<string>, violations: list<array{route: string, method: string, rule: string, message: string}>}
	 *         How many routes the walk looked at, which of them it checked as the plugin's, and what is wrong with them.
	 */
	public function walk( WP_REST_Server $server ): array {
		$visited       = 0;
		$plugin_routes = array();
		$violations    = array();

		foreach ( $server->get_routes() as $route => $handlers ) {
			++$visited;

			$route           = (string) $route;
			$options         = $server->get_route_options( $route );
			$route_namespace = is_array( $options ) && isset( $options['namespace'] ) ? (string) $options['namespace'] : '';

			if ( ! self::isPluginRoute( $route, $route_namespace ) || self::isNamespaceIndex( $route, $route_namespace, $handlers ) ) {
				continue;
			}

			$plugin_routes[] = $route;
			$route_methods   = array();

			foreach ( $handlers as $handler ) {
				$methods       = array_keys( (array) ( $handler['methods'] ?? array() ) );
				$route_methods = array_merge( $route_methods, $methods );
				$violations    = array_merge( $violations, self::checkEndpoint( $route, $methods, $handler['permission_callback'] ?? null ) );
			}

			if ( ! self::hasSchema( is_array( $options ) ? $options : array() ) ) {
				$violations[] = self::violation(
					$route,
					implode( ', ', array_unique( $route_methods ) ),
					self::RULE_MISSING_SCHEMA,
					'has no schema',
					"register the route with a 'schema' callback that returns the JSON Schema compiled from the operation's resource schema."
				);
			}
		}

		return array(
			'routes_visited' => $visited,
			'plugin_routes'  => $plugin_routes,
			'violations'     => $violations,
		);
	}

	/**
	 * Walks every route under a post type's `wp/v2` base of a booted server.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server $server          A server on which `rest_api_init` has run.
	 * @param string         $postType        The post type.
	 * @param string         $controllerClass The class that must serve it.
	 * @return array{routes: list<string>, violations: list<array{route: string, method: string, rule: string, message: string}>}
	 *         The routes under the base, and what is wrong with them.
	 */
	public function walkPostTypeBase( WP_REST_Server $server, string $postType, string $controllerClass ): array {
		$type = get_post_type_object( $postType );

		if ( ! $type instanceof \WP_Post_Type ) {
			return array(
				'routes'     => array(),
				'violations' => array( self::violation( $postType, '*', self::RULE_FOREIGN_CONTROLLER, 'is not a registered post type', 'register it before the REST server is built.' ) ),
			);
		}

		$base        = '/' . ( ! empty( $type->rest_namespace ) ? $type->rest_namespace : 'wp/v2' ) . '/' . ( ! empty( $type->rest_base ) ? $type->rest_base : $type->name );
		$controllers = array(
			'posts'     => $type->get_rest_controller(),
			'autosaves' => $type->get_autosave_rest_controller(),
			'revisions' => $type->get_revisions_rest_controller(),
		);
		$owners      = array_values( array_filter( $controllers ) );
		$routes      = array();
		$violations  = self::foreignControllers( $base, $controllers, $controllerClass );

		foreach ( $server->get_routes() as $route => $handlers ) {
			$route = (string) $route;

			// WordPress matches routes without regard to letter case, so the comparison ignores it too.
			if ( 0 !== strcasecmp( $route, $base ) && 0 !== strncasecmp( $route, $base . '/', strlen( $base ) + 1 ) ) {
				continue;
			}

			$routes[] = $route;
			$expected = self::BASE_ROUTES[ substr( $route, strlen( $base ) ) ] ?? null;

			foreach ( $handlers as $handler ) {
				$callback = $handler['permission_callback'] ?? null;
				$owner    = is_array( $callback ) && isset( $callback[0] ) && is_object( $callback[0] ) ? $callback[0] : null;

				foreach ( array_keys( (array) ( $handler['methods'] ?? array() ) ) as $method ) {
					$method = strtoupper( (string) $method );

					if ( null === $owner || ! in_array( $owner, $owners, true ) ) {
						$violations[] = self::violation(
							$route,
							$method,
							self::RULE_NOT_THE_TYPES_CONTROLLER,
							'is guarded by ' . self::describeCallback( $callback ) . ", which is not a method of one of the post type's controllers",
							"leave the routes at the post type's base to its controllers, whose permission methods decide through its mapped capabilities."
						);

						continue;
					}

					$check = null === $expected ? null : ( self::CHECKS[ $expected[1] ][ $method ] ?? null );
					$given = (string) ( $callback[1] ?? '' );

					if ( null !== $check && $controllers[ $expected[0] ] === $owner && $check === $given ) {
						continue;
					}

					$violations[] = self::violation(
						$route,
						$method,
						self::RULE_WRONG_CHECK,
						'is guarded by ' . self::describeCallback( $callback ) . ( null === $check ? ', on a route or method core does not register at the base' : ', where core guards it with ' . get_class( (object) $controllers[ $expected[0] ] ) . '::' . $check . '()' ),
						"leave the routes at the post type's base as core registers them, each guarded by the check core gives it."
					);
				}
			}
		}

		return array(
			'routes'     => $routes,
			'violations' => $violations,
		);
	}

	/**
	 * Checks the classes of a post type's three controllers: the posts controller the plugin's, the other two core's own.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $base            The post type's base, for the message.
	 * @param array<string, mixed> $controllers     The posts, autosave and revision controllers, keyed so.
	 * @param string               $controllerClass The class that must serve the posts.
	 * @return list<array{route: string, method: string, rule: string, message: string}> A violation per controller of another class.
	 */
	private static function foreignControllers( string $base, array $controllers, string $controllerClass ): array {
		$classes    = array(
			'posts'     => $controllerClass,
			'autosaves' => \WP_REST_Autosaves_Controller::class,
			'revisions' => \WP_REST_Revisions_Controller::class,
		);
		$violations = array();

		foreach ( $classes as $role => $class ) {
			$controller = $controllers[ $role ] ?? null;

			if ( is_object( $controller ) && get_class( $controller ) === $class ) {
				continue;
			}

			$violations[] = self::violation(
				$base,
				'*',
				self::RULE_FOREIGN_CONTROLLER,
				'is served by ' . ( is_object( $controller ) ? get_class( $controller ) : 'no controller' ) . ' for its ' . $role . ', not by ' . $class,
				'posts' === $role ? "give the post type's registration `rest_controller_class`, and let nothing replace it." : "leave the post type's " . $role . ' controller to core.'
			);
		}

		return $violations;
	}

	/**
	 * Prints violations, one per line, for a failure message.
	 *
	 * @since 0.1.0
	 *
	 * @param list<array{route: string, method: string, rule: string, message: string}> $violations What walk() found.
	 * @return string The messages, or a note that there are none.
	 */
	public static function describe( array $violations ): string {
		if ( array() === $violations ) {
			return '  (no violations)';
		}

		return '  ' . implode( "\n  ", array_column( $violations, 'message' ) );
	}

	/**
	 * Checks the permission callback of one endpoint.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $route    The route.
	 * @param string[] $methods  The HTTP methods the endpoint accepts.
	 * @param mixed    $callback The endpoint's `permission_callback`, or null when it has none.
	 * @return list<array{route: string, method: string, rule: string, message: string}> One violation per method.
	 */
	private static function checkEndpoint( string $route, array $methods, $callback ): array {
		$violations = array();

		foreach ( $methods as $method ) {
			if ( null === $callback || '' === $callback || false === $callback ) {
				$violations[] = self::violation(
					$route,
					$method,
					self::RULE_MISSING_CALLBACK,
					'has no permission_callback',
					"pass PermissionCallback::requiring( '<capability>' ), built by the shared permission factory from the operation's declared capability, or PermissionCallback::publicRead() for a read that is public on purpose."
				);
			} elseif ( ! $callback instanceof PermissionCallback ) {
				$violations[] = self::violation(
					$route,
					$method,
					self::RULE_FOREIGN_CALLBACK,
					'is guarded by ' . self::describeCallback( $callback ) . ", which is not the plugin's permission-callback type and escapes the one permission path whatever it returns",
					'use PermissionCallback::requiring() or PermissionCallback::requiringOn(), or PermissionCallback::publicRead() for a read that is public on purpose.'
				);
			} elseif ( $callback->isPublicRead() && ! in_array( strtoupper( (string) $method ), self::PUBLIC_READ_METHODS, true ) ) {
				$violations[] = self::violation(
					$route,
					$method,
					self::RULE_PUBLIC_READ_BEYOND_GET,
					'is marked PermissionCallback::publicRead(), which allows GET and HEAD only',
					"require the capability the operation declares, with PermissionCallback::requiring( '<capability>' ), or serve the public read on GET."
				);
			}
		}

		return $violations;
	}

	/**
	 * Builds one violation.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route   The route.
	 * @param string $method  The HTTP method, or the route's methods for a rule about the whole route.
	 * @param string $rule    One of the RULE_* constants.
	 * @param string $problem What is wrong, as the rest of a sentence that starts with the method and route.
	 * @param string $fix     What to do about it.
	 * @return array{route: string, method: string, rule: string, message: string} The violation.
	 */
	private static function violation( string $route, string $method, string $rule, string $problem, string $fix ): array {
		return array(
			'route'   => $route,
			'method'  => $method,
			'rule'    => $rule,
			'message' => sprintf( '%s %s %s [%s]. Fix: %s', $method, $route, $problem, $rule, $fix ),
		);
	}

	/**
	 * Tells whether a route belongs to the plugin.
	 *
	 * The path is checked as well as the namespace, so an endpoint added through the
	 * `rest_endpoints` filter, which carries no namespace, is walked too. Both comparisons ignore
	 * letter case, as WordPress does when it matches a request to a route.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route           The route, with its leading slash.
	 * @param string $route_namespace The namespace it was registered in, or an empty string.
	 * @return bool True for a route in a `seocart*` namespace or under a `/seocart*` path, in any letter case.
	 */
	private static function isPluginRoute( string $route, string $route_namespace ): bool {
		return 0 === strncasecmp( $route_namespace, self::PLUGIN_PREFIX, strlen( self::PLUGIN_PREFIX ) )
			|| 0 === strncasecmp( ltrim( $route, '/' ), self::PLUGIN_PREFIX, strlen( self::PLUGIN_PREFIX ) );
	}

	/**
	 * Tells whether a route is the namespace index core registers for every namespace.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route           The route.
	 * @param string $route_namespace The route's namespace.
	 * @param mixed  $handlers        The route's endpoints.
	 * @return bool True only for `/<namespace>` served by WP_REST_Server::get_namespace_index() alone.
	 */
	private static function isNamespaceIndex( string $route, string $route_namespace, $handlers ): bool {
		if ( '/' . $route_namespace !== $route || ! is_array( $handlers ) || array() === $handlers ) {
			return false;
		}

		foreach ( $handlers as $handler ) {
			$callback = $handler['callback'] ?? null;

			if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) || ! $callback[0] instanceof WP_REST_Server || 'get_namespace_index' !== $callback[1] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a route declares a schema that produces something.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $options The route's options.
	 * @return bool True when the `schema` option is callable and returns a non-empty array.
	 */
	private static function hasSchema( array $options ): bool {
		if ( ! isset( $options['schema'] ) || ! is_callable( $options['schema'] ) ) {
			return false;
		}

		$schema = call_user_func( $options['schema'] );

		return is_array( $schema ) && array() !== $schema;
	}

	/**
	 * Names a callback the way a developer would look for it.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $callback The permission callback as registered.
	 * @return string For example `the function '__return_true'` or `a closure at tests/Foo.php:12`.
	 */
	private static function describeCallback( $callback ): string {
		if ( is_string( $callback ) ) {
			return "the function '" . $callback . "'";
		}

		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];

			return 'the array callable ' . $class . '::' . (string) $callback[1];
		}

		if ( $callback instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callback );

			return sprintf( 'a closure at %s:%d', (string) $reflection->getFileName(), (int) $reflection->getStartLine() );
		}

		if ( is_object( $callback ) ) {
			return 'an object of class ' . get_class( $callback );
		}

		return 'a value of type ' . gettype( $callback );
	}
}
