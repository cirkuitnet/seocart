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
use SEOCart\Application\Operations\RestBinding;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Rest\CachePolicy;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Serves each operation that declares a REST route, in the `seocart/v1` namespace.
 *
 * This class owns one fact: how an operation becomes a WordPress REST route. It restates nothing
 * the declaration says. For each route it registers:
 *
 * - the method the definition derives — GET only for a read-only operation;
 * - the compiled arguments, with which WordPress validates, sanitizes and fills in defaults
 *   before the permission check runs;
 * - PermissionFactory's permission callback;
 * - the compiled output schema, which WordPress serves to OPTIONS requests;
 * - a callback that has OperationInvoker prepare the declared input and run the operation for the
 *   current user, as an Actor, and answers with the result;
 * - the operation's id under OPERATION_KEY, which marks the endpoint as an operation's.
 *
 * A route parameter is read from the URL only. WP_REST_Request::get_param() would let a query or
 * body value of the same name win over the URL segment, so a request that sends a route
 * parameter anywhere else is refused as invalid; the permission check and the service therefore
 * read the one value in the URL.
 *
 * Every response of an operation route is finished the same way: an error gets exactly the
 * documented data members from the ErrorTranslator, and every response gets CachePolicy's
 * headers. WordPress produces a response at several points, so four filters finish it, each
 * leaving every other route alone:
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
	 * Creates the adapter. Registers nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry   The operations.
	 * @param OperationInvoker  $invoker    Runs an operation's service.
	 * @param ErrorTranslator   $translator The translator the invoker uses, which also gives the
	 *                                      errors WordPress raises on a route their data members.
	 */
	public function __construct( OperationRegistry $registry, OperationInvoker $invoker, ErrorTranslator $translator ) {
		$this->registry   = $registry;
		$this->invoker    = $invoker;
		$this->translator = $translator;
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
				RestBinding::NAMESPACE,
				self::pattern( $rest ),
				array(
					array(
						'methods'             => (string) $definition->httpMethod(),
						'args'                => self::arguments( $operation, $rest ),
						'permission_callback' => PermissionFactory::forRest( $definition ),
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
		unset( $request );

		if ( ! isset( $handler[ self::OPERATION_KEY ] ) ) {
			return $response;
		}

		return $this->finish( $response );
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

		return $this->finish( $result );
	}

	/**
	 * Finishes the response to an operation's request as WordPress serves it. Hooked to
	 * `rest_post_dispatch`, last.
	 *
	 * This is the one filter a refusal by authentication passes through: WordPress checks the
	 * cookie nonce and the `rest_authentication_errors` filter before it matches a route. A response
	 * the other filters finished already is unchanged.
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
		unset( $server );

		return $result instanceof WP_HTTP_Response && $this->isOperationRequest( $request ) ? $this->finish( $result ) : $result;
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
		if ( ! $served && $this->isOperationRequest( $request ) ) {
			CachePolicy::send( $server, $result );
		}

		return $served;
	}

	/**
	 * Finishes the response of an operation's request: the documented error shape and the caching headers.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $outcome A WP_Error, a response, or data to answer with.
	 * @return WP_REST_Response The response. An error body — WordPress's `{ code, message, data }` —
	 *                          has exactly the documented data members; any other body is unchanged.
	 */
	private function finish( $outcome ): WP_REST_Response {
		if ( $outcome instanceof WP_Error ) {
			return CachePolicy::apply( rest_convert_error_to_response( $this->translator->conform( $outcome ) ) );
		}

		$response = rest_ensure_response( $outcome );
		$body     = $response->get_data();

		if ( $response->is_error() && is_array( $body ) && array_key_exists( 'code', $body ) && array_key_exists( 'message', $body ) && array_key_exists( 'data', $body ) ) {
			$error = $response->as_error();

			if ( null !== $error ) {
				$response->set_data( rest_convert_error_to_response( $this->translator->conform( $error ) )->get_data() );
			}
		}

		return CachePolicy::apply( $response );
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
		return '/' . RestBinding::NAMESPACE . self::pattern( $rest );
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
	 * @since 0.1.0
	 *
	 * @param CompiledOperation $operation The operation.
	 * @param WP_REST_Request   $request   The validated, permitted request.
	 * @return WP_REST_Response|WP_Error The serialized output, or the translated failure.
	 */
	private function respond( CompiledOperation $operation, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$definition = $operation->definition();
		$url        = $request->get_url_params();
		$path       = null === $definition->rest() ? array() : $definition->rest()->pathParameters();
		$values     = array();

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
