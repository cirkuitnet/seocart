<?php
/**
 * RestAdapter: registers every operation that has a REST route
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\CompiledOperation;
use SEOCart\Application\Operations\OperationRegistry;
use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Authorization\RequestPolicy;
use SEOCart\Platform\Rest\CachePolicy;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves each operation that declares a REST route, in its namespace: `seocart/v1`, or the Store
 * API's `seocart/store/v1`.
 *
 * This class owns one fact: how an operation becomes a WordPress REST route. It restates nothing
 * the declaration says. For each route it registers:
 *
 * - the method the definition derives — GET only for a read-only operation;
 * - the compiled arguments, with which WordPress validates, sanitizes and fills in defaults
 *   before the permission check runs;
 * - PermissionFactory's permission callback; for a public write, a public-write callback with the
 *   request policy PublicWrites::policy() builds from the operation's PublicWrite;
 * - the compiled output schema, which WordPress serves to OPTIONS requests;
 * - a callback that has OperationInvoker prepare the declared input and run the operation for the
 *   current user, as an Actor, and answers with the result. For a public write it first counts the
 *   write against its rate limit, with PublicWrites::admit(), and answers with the refusal over it.
 *   The count is here, and not in the permission callback, because this callback runs once for
 *   each request it serves, including one dispatched with rest_do_request(), while WordPress asks
 *   a permission callback again for the `Allow` header and for an OPTIONS request, and another
 *   plugin may wrap it and ask it any number of times;
 * - the operation's id under OPERATION_KEY, which marks the endpoint as an operation's.
 *
 * A route parameter is read from the URL only. WP_REST_Request::get_param() would let a query or
 * body value of the same name win over the URL segment, so a request that sends a route
 * parameter anywhere else is refused as invalid; the permission check and the service therefore
 * read the one value in the URL.
 *
 * Every response of an operation route is finished the same way: an error gets exactly the
 * documented data members from the ErrorTranslator, and every response gets CachePolicy's
 * headers, with `Vary: Cookie` always on a Store API route. WordPress produces a response at
 * several points, so four filters finish it, each leaving every other route alone:
 *
 * - finishResponse(), on `rest_request_after_callbacks`: a success, an error the service raised,
 *   and a request WordPress refused before the service ran, for bad input or a missing permission;
 * - finishShortCircuitedResponse(), on `rest_pre_dispatch`: an answer given before any endpoint
 *   runs — WordPress's answer to OPTIONS, or another plugin's;
 * - finishServedResponse(), on `rest_post_dispatch`, while WordPress serves a request: a refusal
 *   by authentication, such as an invalid cookie nonce, which WordPress decides before it matches
 *   a route;
 * - sendCacheHeaders(), on `rest_pre_serve_request`: the caching headers again, sent directly, so
 *   they reach the client when `?_envelope` has moved the response's headers into its body and
 *   when WordPress has replaced Cache-Control for a logged-in user.
 *
 * The last three recognise an operation's request by the endpoint WordPress matched to it, which
 * carries the operation marker; another plugin's endpoint under the same namespace, even one whose
 * path an operation's pattern would also match, is not an operation's. Only where WordPress has
 * matched no endpoint — a refusal by authentication, an answer given before dispatch — do they
 * match the route and the method themselves, the way WordPress matches them; an OPTIONS request
 * on an operation's route counts.
 *
 * One more request is finished as an operation's by the two filters that run while WordPress
 * serves it: a request under one of the plugin's namespaces that WordPress answered with
 * `rest_no_route` — an unknown path, or a method the route does not serve; WordPress answers both
 * with 404 — unless the path matches a route the plugin did not register, such as another
 * plugin's route under the namespace, which stays WordPress's. A request dispatched internally,
 * with rest_do_request(), passes neither filter and keeps WordPress's answer.
 *
 * One refusal passes none of the filters: an invalid `?_jsonp` callback, which WordPress refuses
 * with a 400 of its own before it builds a request at all.
 *
 * The kernel hooks register() to `rest_api_init`, and register() adds the four response filters:
 *
 *     add_action( 'rest_api_init', array( $rest_adapter, 'register' ) );
 *
 * A read-only operation is served with GET and a changing one never is: the method is derived
 * from the declaration, and the write-method enum has no GET.
 *
 * @since 0.1.0
 */
final class RestAdapter {

	/**
	 * The key of a route endpoint that holds the id of the operation it serves.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPERATION_KEY = 'seocart_operation';

	/**
	 * The operations.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationRegistry
	 */
	private OperationRegistry $registry;

	/**
	 * Runs an operation's service.
	 *
	 * @since 0.1.0
	 *
	 * @var OperationInvoker
	 */
	private OperationInvoker $invoker;

	/**
	 * Gives the errors WordPress raises on an operation route the documented data members.
	 *
	 * @since 0.1.0
	 *
	 * @var ErrorTranslator
	 */
	private ErrorTranslator $translator;

	/**
	 * The routes registered, as the REST server lists them, each with the methods it serves.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $routes = array();

	/**
	 * Returns what guards and counts a public write, or null when none is given.
	 *
	 * @since 0.1.0
	 *
	 * @var (\Closure(): PublicWrites)|null
	 */
	private ?\Closure $publicWrites;

	/**
	 * The requests to an unknown route under a plugin namespace finished while being served, so
	 * their caching headers are sent too.
	 *
	 * @since 0.1.0
	 *
	 * @var \WeakMap<WP_REST_Request, true>
	 */
	private \WeakMap $unrouted;

	/**
	 * Creates the adapter. Registers nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry     The operations.
	 * @param OperationInvoker  $invoker      Runs an operation's service.
	 * @param ErrorTranslator   $translator   The translator the invoker uses, which also gives the
	 *                                        errors WordPress raises on a route their data members.
	 * @param \Closure|null     $public_writes Optional. Returns what guards and counts a public
	 *                                         write: the Store API's. Called only when the registry
	 *                                         holds a public write, which requires it. Default null.
	 *
	 * @phpstan-param (\Closure(): PublicWrites)|null $public_writes
	 */
	public function __construct( OperationRegistry $registry, OperationInvoker $invoker, ErrorTranslator $translator, ?\Closure $public_writes = null ) {
		$this->registry     = $registry;
		$this->invoker      = $invoker;
		$this->translator   = $translator;
		$this->publicWrites = $public_writes;
		$this->unrouted     = new \WeakMap();
	}

	/**
	 * Registers the route of every operation that has one, and the filters that finish their
	 * responses. Hooked to `rest_api_init`.
	 *
	 * The response filters run last, so every other filter sees the response WordPress built. The
	 * headers are sent before WordPress's own cross-origin headers, which add to `Vary` instead of
	 * replacing it.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
		add_filter( 'rest_request_after_callbacks', array( $this, 'finishResponse' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'finishShortCircuitedResponse' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( $this, 'finishServedResponse' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'sendCacheHeaders' ), 9, 4 );

		foreach ( $this->registry->all() as $definition ) {
			$rest = $definition->rest();

			if ( null === $rest ) {
				continue;
			}

			$operation = new CompiledOperation( $definition );

			register_rest_route(
				$rest->restNamespace(),
				self::pattern( $rest ),
				array(
					array(
						'methods'             => (string) $definition->httpMethod(),
						'args'                => self::arguments( $operation, $rest ),
						'permission_callback' => PermissionFactory::forRest( $definition, fn( PublicWrite $write ): RequestPolicy => $this->publicWrites()->policy( $write ) ),
						'callback'            => function ( WP_REST_Request $request ) use ( $operation ): WP_REST_Response|WP_Error {
							return $this->respond( $operation, $request );
						},
						self::OPERATION_KEY   => $definition->id(),
					),
					'schema' => array( $operation, 'outputSchema' ),
				)
			);

			$this->routes[ self::serverRoute( $rest ) ][ (string) $definition->httpMethod() ] = true;
		}
	}

	/**
	 * Finishes the response of an operation endpoint. Hooked to `rest_request_after_callbacks`.
	 *
	 * WordPress passes every outcome of a matched endpoint through that filter: the error of a
	 * request that failed validation or the permission check, and the callback's result. An
	 * endpoint that is not an operation's is left alone.
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $response The outcome: a WP_Error, a response, or the callback's data.
	 * @param array           $handler  The endpoint that matched the request.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed The outcome of another endpoint unchanged; for an operation's endpoint, the
	 *               finished WP_REST_Response.
	 *
	 * @phpstan-param array<string, mixed> $handler
	 */
	public function finishResponse( $response, array $handler, WP_REST_Request $request ) {
		if ( ! isset( $handler[ self::OPERATION_KEY ] ) ) {
			return $response;
		}

		return $this->finish( $response, $request );
	}

	/**
	 * Finishes an answer given to an operation's request before any endpoint runs. Hooked to
	 * `rest_pre_dispatch`, after WordPress and every other plugin have had their say.
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $result  Null, or the answer an earlier filter gave.
	 * @param WP_REST_Server  $server  The server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed Null, or the answer to another route, unchanged; for an operation's request,
	 *               the finished WP_REST_Response.
	 */
	public function finishShortCircuitedResponse( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		unset( $server );

		if ( empty( $result ) || ! $this->isOperationRequest( $request ) ) {
			return $result;
		}

		return $this->finish( $result, $request );
	}

	/**
	 * Finishes the response to an operation's request as WordPress serves it. Hooked to
	 * `rest_post_dispatch`, last.
	 *
	 * This is the one filter a refusal by authentication passes through: WordPress checks the
	 * cookie nonce and the `rest_authentication_errors` filter before it matches a route. So is
	 * WordPress's `rest_no_route` for a request under a plugin namespace. A response the other
	 * filters finished already is unchanged.
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $result  The response; another filter may have replaced it.
	 * @param WP_REST_Server  $server  The server.
	 * @param WP_REST_Request $request The request.
	 * @return mixed The response to another route, or anything but a response, unchanged; for an
	 *               operation's request, the finished response.
	 */
	public function finishServedResponse( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( ! $result instanceof WP_HTTP_Response ) {
			return $result;
		}

		if ( $this->isOperationRequest( $request ) ) {
			return $this->finish( $result, $request );
		}

		if ( ! $this->isUnroutedPluginRequest( $server, $request, $result ) ) {
			return $result;
		}

		$this->unrouted[ $request ] = true;

		return $this->finish( $result, $request );
	}

	/**
	 * Sends the caching headers of an operation's request as WordPress serves it. Hooked to
	 * `rest_pre_serve_request`.
	 *
	 * The headers go out at priority 9, before WordPress's own cross-origin headers at 10, which add
	 * `Vary: Origin` without replacing it; a later callback that serves the response itself therefore
	 * sends it with `no-store, private` too, which is conservative and harmless.
	 *
	 * Public only because WordPress calls it as a filter.
	 *
	 * @since 0.1.0
	 *
	 * @param bool             $served  Whether the request has been served already.
	 * @param WP_HTTP_Response $result  The response being served, possibly an envelope.
	 * @param WP_REST_Request  $request The request.
	 * @param WP_REST_Server   $server  The server.
	 * @return bool Whether the request has been served, unchanged.
	 */
	public function sendCacheHeaders( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
		if ( ! $served && ( $this->isOperationRequest( $request ) || isset( $this->unrouted[ $request ] ) ) ) {
			CachePolicy::send( $server, $result, self::isStoreRequest( $request ) );
		}

		return $served;
	}

	/**
	 * Finishes the response of an operation's request: the documented error shape and the caching headers.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $outcome A WP_Error, a response, or data to answer with.
	 * @param WP_REST_Request $request The request, whose namespace decides whether it varies by cookie.
	 * @return WP_REST_Response The response. An error body — WordPress's `{ code, message, data }` —
	 *                          has exactly the documented data members; any other body is unchanged.
	 */
	private function finish( $outcome, WP_REST_Request $request ): WP_REST_Response {
		$store = self::isStoreRequest( $request );

		if ( $outcome instanceof WP_Error ) {
			return CachePolicy::apply( rest_convert_error_to_response( $this->translator->conform( $outcome ) ), $store );
		}

		$response = rest_ensure_response( $outcome );
		$body     = $response->get_data();

		if ( $response->is_error() && is_array( $body ) && array_key_exists( 'code', $body ) && array_key_exists( 'message', $body ) && array_key_exists( 'data', $body ) ) {
			$error = $response->as_error();

			if ( null !== $error ) {
				$response->set_data( rest_convert_error_to_response( $this->translator->conform( $error ) )->get_data() );
			}
		}

		return CachePolicy::apply( $response, $store );
	}

	/**
	 * Returns what guards and counts a public write.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the adapter was given none: a public write cannot be served without it.
	 *
	 * @return PublicWrites The Store API's.
	 */
	private function publicWrites(): PublicWrites {
		if ( null === $this->publicWrites ) {
			throw new \LogicException( 'A public write is registered, and the REST adapter was given nothing to guard and count it with.' );
		}

		return ( $this->publicWrites )();
	}

	/**
	 * Tells whether WordPress found no route for a request under one of the plugin's namespaces.
	 *
	 * True when WordPress matched no endpoint and answered `rest_no_route`, the request's path is
	 * under `seocart/v1` or `seocart/store/v1` in any letter case, and no route the adapter did not
	 * register matches the path: a wrong method on another plugin's route under the namespace, or
	 * on the namespace index core registers, stays WordPress's.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Server   $server  The server, whose routes are compared.
	 * @param WP_REST_Request  $request The request.
	 * @param WP_HTTP_Response $result  The response WordPress built.
	 * @return bool True for WordPress's `rest_no_route` under a plugin namespace.
	 */
	private function isUnroutedPluginRequest( WP_REST_Server $server, WP_REST_Request $request, WP_HTTP_Response $result ): bool {
		$body = $result->get_data();

		if ( array() !== $request->get_attributes() || ! is_array( $body ) || 'rest_no_route' !== ( $body['code'] ?? null ) ) {
			return false;
		}

		$path = $request->get_route();

		if ( null === self::pluginNamespace( $path ) ) {
			return false;
		}

		foreach ( array_keys( $server->get_routes() ) as $route ) {
			if ( ! isset( $this->routes[ $route ] ) && 1 === preg_match( '@^' . $route . '$@i', $path ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Tells whether a request is for the Store API, whose every response varies by cookie.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True when the path is under `seocart/store/v1`, in any letter case.
	 */
	private static function isStoreRequest( WP_REST_Request $request ): bool {
		return RestBinding::STORE_NAMESPACE === self::pluginNamespace( $request->get_route() );
	}

	/**
	 * Returns the plugin namespace a path is under, compared as WordPress compares routes: ignoring case.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The request's path, such as `/seocart/store/v1/session`.
	 * @return string|null RestBinding::NAMESPACE, RestBinding::STORE_NAMESPACE, or null.
	 */
	private static function pluginNamespace( string $path ): ?string {
		$path = strtolower( trim( $path, '/' ) ) . '/';

		foreach ( array( RestBinding::NAMESPACE, RestBinding::STORE_NAMESPACE ) as $plugin_namespace ) {
			if ( str_starts_with( $path, $plugin_namespace . '/' ) ) {
				return $plugin_namespace;
			}
		}

		return null;
	}

	/**
	 * Tells whether a request is for one of the operation endpoints this adapter registered.
	 *
	 * When WordPress has matched an endpoint to the request, it has stored the endpoint as the
	 * request's attributes, and the endpoint's operation marker alone decides. Otherwise — a refusal
	 * by authentication, or an answer given before dispatch — the route is matched as WordPress
	 * matches it, the route pattern against the whole path ignoring case, and so is the method: HEAD
	 * counts as GET, and OPTIONS, which WordPress answers for every route, counts too.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool True when an operation endpoint serves, or would serve, the request.
	 */
	private function isOperationRequest( WP_REST_Request $request ): bool {
		$endpoint = $request->get_attributes();

		if ( array() !== $endpoint ) {
			return isset( $endpoint[ self::OPERATION_KEY ] );
		}

		$method = $request->get_method();

		foreach ( $this->routes as $route => $methods ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $request->get_route() ) ) {
				continue;
			}

			if ( 'OPTIONS' === $method || isset( $methods[ $method ] ) || ( 'HEAD' === $method && isset( $methods['GET'] ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the route a binding is registered under, as the REST server lists it.
	 *
	 * @since 0.1.0
	 *
	 * @param RestBinding $rest The binding.
	 * @return string The route with its namespace, such as `/seocart/v1/stock-items/(?P<item_id>[^/]+)`.
	 */
	public static function serverRoute( RestBinding $rest ): string {
		return '/' . $rest->restNamespace() . self::pattern( $rest );
	}

	/**
	 * Compiles a binding's path template into a WordPress route pattern.
	 *
	 * A parameter matches any one path segment; what the value must look like is the argument
	 * schema's to check, so the check and its error are the same on every surface.
	 *
	 * @since 0.1.0
	 *
	 * @param RestBinding $rest The binding.
	 * @return string The pattern, relative to the namespace.
	 */
	private static function pattern( RestBinding $rest ): string {
		return (string) preg_replace( '/\{([a-z0-9_]+)\}/', '(?P<$1>[^/]+)', $rest->route() );
	}

	/**
	 * Returns the route arguments: the compiled ones, with the URL-only rule on each route parameter.
	 *
	 * @since 0.1.0
	 *
	 * @param CompiledOperation $operation The operation.
	 * @param RestBinding       $rest      Its route.
	 * @return array<string, array<string, mixed>> The arguments.
	 */
	private static function arguments( CompiledOperation $operation, RestBinding $rest ): array {
		$arguments = $operation->restArguments();

		foreach ( $rest->pathParameters() as $name ) {
			$arguments[ $name ]['validate_callback'] = array( self::class, 'validatePathParameter' );
		}

		return $arguments;
	}

	/**
	 * Validates a route parameter: refused when the request also sends it outside the URL.
	 *
	 * Public only because WordPress calls it as a `validate_callback`.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed           $value   The value WordPress resolved for the parameter.
	 * @param WP_REST_Request $request The request.
	 * @param string          $param   The parameter's name.
	 * @return bool|WP_Error True when the parameter arrives in the URL only and matches its schema.
	 */
	public static function validatePathParameter( $value, WP_REST_Request $request, string $param ): bool|WP_Error {
		foreach ( array( $request->get_query_params(), $request->get_body_params(), (array) $request->get_json_params() ) as $params ) {
			if ( array_key_exists( $param, $params ) ) {
				return new WP_Error(
					'rest_invalid_param',
					/* translators: %s: The name of a route parameter, such as item_id. */
					sprintf( __( '%s is part of the URL and must not be sent in the query or the body.', 'seocart' ), $param ),
					array( 'status' => 400 )
				);
			}
		}

		return rest_validate_request_arg( $value, $request, $param );
	}

	/**
	 * Answers a request: the declared input to the invoker, its result to the client.
	 *
	 * A public write is counted first, once: this callback runs once per request.
	 *
	 * @since 0.1.0
	 *
	 * @param CompiledOperation $operation The operation.
	 * @param WP_REST_Request   $request   The validated, permitted request.
	 * @return WP_REST_Response|WP_Error The serialized output, or the translated failure, or the
	 *                                   refusal of a public write over its rate limit.
	 */
	private function respond( CompiledOperation $operation, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$definition = $operation->definition();
		$write      = $definition->publicWrite();

		if ( null !== $write ) {
			$admitted = $this->publicWrites()->admit( $write );

			if ( true !== $admitted ) {
				return $admitted;
			}
		}

		$url    = $request->get_url_params();
		$path   = null === $definition->rest() ? array() : $definition->rest()->pathParameters();
		$values = array();

		foreach ( $definition->input() as $field ) {
			$name = $field->name();

			if ( in_array( $name, $path, true ) ) {
				$values[ $name ] = $url[ $name ] ?? null;
			} elseif ( $request->has_param( $name ) ) {
				$values[ $name ] = $request->get_param( $name );
			}
		}

		$input = $this->invoker->prepare( $operation, $values );

		if ( $input instanceof WP_Error ) {
			return $input;
		}

		$result = $this->invoker->invoke( $operation, $input, Actor::user( get_current_user_id() ) );

		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result, 200 );
	}
}
