<?php
/**
 * PermissionCallback: the only kind of permission callback a SEOCart REST route may use
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a REST request by checking one declared capability through current_user_can().
 *
 * This class owns one fact: how a plugin route decides whether the current request may run.
 * Every route in a `seocart*` namespace passes an instance as its `permission_callback`, built
 * by the operations module's shared permission factory from the operation's declared
 * capability. The route walker in the test suite fails the build on any other callback, so a
 * route cannot be more permissive than the capability it declares.
 *
 * The check always goes through current_user_can(), and so through the one map_meta_cap
 * callback (CapabilityMapper), which the admin screens and the CLI use as well. There is no
 * shortcut for logged-in users and no "assume the current user" fallback.
 *
 * Three kinds exist:
 *
 * - requiring(): a primitive capability, or a meta capability checked without a resource;
 * - requiringOn(): a meta capability checked on the resource a request parameter names. When
 *   the request names no usable resource, the answer is false without asking anyone;
 * - publicRead(): the named marker for a route that is public on purpose. It checks nothing,
 *   so "intentionally public" is a written decision rather than an omission, and the walker
 *   refuses it on any route that accepts POST, PUT, PATCH or DELETE.
 *
 * Any kind can be narrowed further with withPolicy(), the seam described by RequestPolicy.
 *
 * @since 0.1.0
 */
final class PermissionCallback {

	/**
	 * The capability to check, or null for the public-read marker.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $capability;

	/**
	 * The request parameter that names the resource, or null for a check without one.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $resourceParameter;

	/**
	 * Policies consulted after the capability check, in the order they were added.
	 *
	 * @since 0.1.0
	 *
	 * @var list<RequestPolicy>
	 */
	private array $policies = array();

	/**
	 * Creates a callback. Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $capability        The capability to check, or null for a public read.
	 * @param string|null $resourceParameter The request parameter that names the resource, if any.
	 */
	private function __construct( ?string $capability, ?string $resourceParameter ) {
		$this->capability        = $capability;
		$this->resourceParameter = $resourceParameter;
	}

	/**
	 * Creates a callback that requires a capability.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability The capability the current user must hold.
	 * @return self The callback.
	 *
	 * @throws \InvalidArgumentException When the capability name is empty.
	 */
	public static function requiring( string $capability ): self {
		return new self( self::nonEmpty( $capability ), null );
	}

	/**
	 * Creates a callback that requires a meta capability on the resource a request parameter names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability        The meta capability the current user must hold on the resource.
	 * @param string $resourceParameter The request parameter that carries the resource identifier.
	 * @return self The callback.
	 *
	 * @throws \InvalidArgumentException When either name is empty.
	 */
	public static function requiringOn( string $capability, string $resourceParameter ): self {
		return new self( self::nonEmpty( $capability ), self::nonEmpty( $resourceParameter ) );
	}

	/**
	 * Creates the public-read marker: the named decision that a read needs no capability.
	 *
	 * @since 0.1.0
	 *
	 * @return self The marker.
	 */
	public static function publicRead(): self {
		return new self( null, null );
	}

	/**
	 * Returns a copy that also requires a policy to let the request through.
	 *
	 * @since 0.1.0
	 *
	 * @param RequestPolicy $policy The policy to add. It can only narrow what the copy allows.
	 * @return self The narrowed copy. This callback is left unchanged.
	 */
	public function withPolicy( RequestPolicy $policy ): self {
		$copy             = clone $this;
		$copy->policies[] = $policy;

		return $copy;
	}

	/**
	 * Tells whether this is the public-read marker.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when no capability is checked.
	 */
	public function isPublicRead(): bool {
		return null === $this->capability;
	}

	/**
	 * Returns the capability this callback checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The capability, or null for the public-read marker.
	 */
	public function capability(): ?string {
		return $this->capability;
	}

	/**
	 * Answers the REST server: may the current request run?
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The request being answered.
	 * @return bool True when the current user holds the capability, on the resource if there is
	 *              one, and every policy allows the request.
	 */
	public function __invoke( WP_REST_Request $request ): bool {
		if ( null !== $this->capability && ! $this->currentUserHolds( $this->capability, $request ) ) {
			return false;
		}

		foreach ( $this->policies as $policy ) {
			if ( ! $policy->allows( $request, $this->capability ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks the capability for the current user, on the resource the request names if there is one.
	 *
	 * @since 0.1.0
	 *
	 * @param string          $capability The capability to check.
	 * @param WP_REST_Request $request    The request being answered.
	 * @return bool The answer of current_user_can(), or false when no usable resource is named.
	 */
	private function currentUserHolds( string $capability, WP_REST_Request $request ): bool {
		if ( null === $this->resourceParameter ) {
			return current_user_can( $capability );
		}

		$resource = self::resourceIdentifier( $request->get_param( $this->resourceParameter ) );

		return null !== $resource && current_user_can( $capability, $resource );
	}

	/**
	 * Reads a resource identifier from a request parameter.
	 *
	 * A number, including one written as digits in a string, as route patterns deliver it,
	 * becomes a positive integer. Any other non-empty string, such as a uuid, is passed on as it
	 * is, for the resolver to look up. Everything else names no resource.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The parameter's value.
	 * @return int|string|null The identifier, or null when the value names no resource.
	 */
	private static function resourceIdentifier( $value ) {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/D', $value ) ) {
			$value = (int) $value;
		}

		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		return is_string( $value ) && '' !== trim( $value ) ? $value : null;
	}

	/**
	 * Refuses an empty name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name What was given.
	 * @return string The name.
	 *
	 * @throws \InvalidArgumentException When the name is empty.
	 */
	private static function nonEmpty( string $name ): string {
		if ( '' === trim( $name ) ) {
			throw new \InvalidArgumentException( 'A permission callback needs a capability name, and a resource parameter name when it checks a resource.' );
		}

		return $name;
	}
}
