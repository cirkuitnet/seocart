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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

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
 * - a callback that passes the declared input to OperationInvoker and answers with its result.
 *
 * A route parameter is read from the URL only. WP_REST_Request::get_param() would let a query or
 * body value of the same name win over the URL segment, so a request that sends a route
 * parameter anywhere else is refused as invalid; the permission check and the service therefore
 * read the one value in the URL.
 *
 * The kernel hooks register() to `rest_api_init`:
 *
 *     add_action( 'rest_api_init', array( $rest_adapter, 'register' ) );
 *
 * @since 0.1.0
 */
final class RestAdapter {

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
	 * Creates the adapter. Registers nothing yet.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationRegistry $registry The operations.
	 * @param OperationInvoker  $invoker  Runs an operation's service.
	 */
	public function __construct( OperationRegistry $registry, OperationInvoker $invoker ) {
		$this->registry = $registry;
		$this->invoker  = $invoker;
	}

	/**
	 * Registers the route of every operation that has one. Hooked to `rest_api_init`.
	 *
	 * @since 0.1.0
	 */
	public function register(): void {
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
					),
					'schema' => array( $operation, 'outputSchema' ),
				)
			);
		}
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

		$result = $this->invoker->invoke( $operation, $values );

		return $result instanceof WP_Error ? $result : new WP_REST_Response( $result, 200 );
	}
}
