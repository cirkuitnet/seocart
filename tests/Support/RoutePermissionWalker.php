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
 * Every route whose namespace, or whose path, starts with `seocart` is examined. Each of its
 * endpoints must be guarded by the plugin's permission-callback type, and the route must have
 * a schema. "Went through the shared factory" is checked structurally: the callback must be a
 * PermissionCallback. Any other callable, whether `__return_true`, a closure, a function name
 * or an array callable, is a violation whatever it returns, because it escapes the one
 * permission path. The public-read marker is a PermissionCallback too, and it is a violation
 * on any endpoint that accepts POST, PUT, PATCH or DELETE.
 *
 * The one route that is skipped is the namespace index core registers for every namespace,
 * `/<namespace>`, served by WP_REST_Server::get_namespace_index(): it is core's, and core gives
 * it no permission callback and no schema.
 *
 * The walk also returns the plugin routes it examined, so that the operations module can add
 * its own check, that every route resolves to one operation definition, over the same list.
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
	 * Rule: an endpoint that changes state is marked as a public read.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_PUBLIC_READ_ON_WRITE = 'public-read-on-a-mutating-method';

	/**
	 * Rule: a route has no schema.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const RULE_MISSING_SCHEMA = 'schema-missing';

	/**
	 * The prefix of every namespace, and every route path, that the walk examines.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const PLUGIN_PREFIX = 'seocart';

	/**
	 * The HTTP methods that change state.
	 *
	 * @since 0.1.0
	 *
	 * @var list<string>
	 */
	private const MUTATING_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Walks every route of a booted server.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server $server A server on which `rest_api_init` has run.
	 * @return array{routes_examined: int, plugin_routes: list<string>, violations: list<array{route: string, method: string, rule: string, message: string}>}
	 *         How many routes the server has in all, which of them were walked, and what is wrong with them.
	 */
	public function walk( WP_REST_Server $server ): array {
		$routes        = $server->get_routes();
		$plugin_routes = array();
		$violations    = array();

		foreach ( $routes as $route => $handlers ) {
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
			'routes_examined' => count( $routes ),
			'plugin_routes'   => $plugin_routes,
			'violations'      => $violations,
		);
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
			} elseif ( $callback->isPublicRead() && in_array( $method, self::MUTATING_METHODS, true ) ) {
				$violations[] = self::violation(
					$route,
					$method,
					self::RULE_PUBLIC_READ_ON_WRITE,
					'changes state but is marked PermissionCallback::publicRead()',
					"require the capability the operation declares, with PermissionCallback::requiring( '<capability>' ); the public-read marker is for GET only."
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
	 * `rest_endpoints` filter, which carries no namespace, is walked too.
	 *
	 * @since 0.1.0
	 *
	 * @param string $route           The route, with its leading slash.
	 * @param string $route_namespace The namespace it was registered in, or an empty string.
	 * @return bool True for a route in a `seocart*` namespace or under a `/seocart*` path.
	 */
	private static function isPluginRoute( string $route, string $route_namespace ): bool {
		return str_starts_with( $route_namespace, self::PLUGIN_PREFIX ) || str_starts_with( ltrim( $route, '/' ), self::PLUGIN_PREFIX );
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
