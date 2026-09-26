<?php
/**
 * PermissionFactory: builds every surface's permission check from an operation's declared capability
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\OperationDefinition;
use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Platform\Authorization\PermissionCallback;
use SEOCart\Platform\Authorization\RequestPolicy;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The one way an operation's capability becomes a permission check.
 *
 * This class owns one fact: how the capability an operation declares is checked on every surface.
 * Each surface ends in the same PermissionCallback, and so in current_user_can() and the one
 * map_meta_cap callback:
 *
 * - the REST route is given forRest() as its `permission_callback`, which the route walker
 *   requires of every plugin route;
 * - the Ability's `permission_callback` and the WP-CLI command call allows(), which asks the same
 *   PermissionCallback about a request that carries only the resource identifier from the input
 *   OperationInvoker prepared — the value the service will act on, not the raw one.
 *
 * A meta capability is checked on the resource named by the operation's resource field. On REST
 * that field is a route parameter, and the route refuses a request that also sends it in the
 * query or the body, so the permission check and the service read the one value in the URL. An
 * Ability or a command has only its input, so both read it there.
 *
 * A public operation, which has no capability, exists only on the REST surface: a public read gets
 * the public-read marker, and a public write PermissionCallback::publicWrite() with the request
 * policy built for its PublicWrite, which the REST adapter is given by the kernel.
 *
 * @since 0.1.0
 */
final class PermissionFactory {

	/**
	 * Builds the permission callback of an operation's REST route.
	 *
	 * @since 0.1.0
	 *
	 * @throws \LogicException When the operation is a public write and no policy factory is given.
	 *
	 * @param OperationDefinition $definition   The operation.
	 * @param \Closure|null       $write_policy Optional. Builds the request policy of a public
	 *                                          write from its PublicWrite. Required for a public
	 *                                          write. Default null.
	 * @return PermissionCallback The callback: the declared primitive, the declared meta capability
	 *                            on the resource field, the public-read marker for a public read,
	 *                            or a public write with its policy.
	 *
	 * @phpstan-param (\Closure(PublicWrite): RequestPolicy)|null $write_policy
	 */
	public static function forRest( OperationDefinition $definition, ?\Closure $write_policy = null ): PermissionCallback {
		$capability     = $definition->capability();
		$resource_field = $definition->resourceField();
		$public_write   = $definition->publicWrite();

		if ( null !== $public_write ) {
			if ( null === $write_policy ) {
				throw new \LogicException( sprintf( 'The operation %s is a public write, and no request policy was given to guard it.', esc_html( $definition->id() ) ) );
			}

			return PermissionCallback::publicWrite( $write_policy( $public_write ) );
		}

		if ( null === $capability ) {
			return PermissionCallback::publicRead();
		}

		if ( null === $resource_field ) {
			return PermissionCallback::requiring( $capability );
		}

		return PermissionCallback::requiringOn( $capability, $resource_field );
	}

	/**
	 * Tells whether the current user may run an operation with an input that did not arrive by HTTP.
	 *
	 * @since 0.1.0
	 *
	 * @param OperationDefinition  $definition The operation.
	 * @param array<string, mixed> $input      The prepared input of an Ability or a command, from
	 *                                         OperationInvoker::prepare().
	 * @return bool The answer of the operation's REST permission callback for the same user and
	 *              resource: true only when it allows the request.
	 */
	public static function allows( OperationDefinition $definition, array $input ): bool {
		$request        = new WP_REST_Request();
		$resource_field = $definition->resourceField();

		if ( null !== $resource_field && array_key_exists( $resource_field, $input ) ) {
			$request->set_url_params( array( $resource_field => $input[ $resource_field ] ) );
		}

		return true === ( self::forRest( $definition ) )( $request );
	}
}
