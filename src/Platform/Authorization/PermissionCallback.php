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
 * Answers a REST request by checking one declared capability for the user who sent it.
 *
 * This class owns one fact: how a plugin route decides whether the current request may run.
 * Every route in a `seocart*` namespace passes an instance as its `permission_callback`, built
 * by the operations module's shared permission factory from the operation's declared
 * capability. The route walker in the test suite fails the build on any other callback, so a
 * route cannot be more permissive than the capability it declares.
 *
 * The check is the Authorizer's, the same one the application service behind the route makes:
 * the REST request is where the current user becomes the actor, so this is the one place that
 * names them, explicitly, with Actor::user( get_current_user_id() ). The check then goes
 * through user_can(), and so through the one map_meta_cap callback (CapabilityMapper), which
 * the admin screens and the CLI use as well. There is no shortcut for logged-in users.
 *
 * Three kinds exist:
 *
 * - requiring(): one of the plugin's declared primitives;
 * - requiringOn(): one of the declared meta capabilities, checked on the resource a request
 *   parameter names. When the request names no usable resource, the answer is false without
 *   asking anyone;
 * - publicRead(): the named marker for a route that is public on purpose. It checks nothing,
 *   so "intentionally public" is a written decision rather than an omission, and the walker
 *   refuses it on any endpoint that accepts a method other than GET or HEAD.
 *
 * A callback is checked against CapabilityDeclaration when it is built, and cannot be built
 * for anything else. A core capability such as `exist` or `read` would admit every visitor or
 * every customer, and a primitive checked "on a resource" would ignore the resource, so both
 * are refused. A public write is not one of the kinds on purpose: the Store API defines its own,
 * which cannot exist without a RequestPolicy.
 *
 * Any kind can be narrowed further with withPolicy(), the seam described by RequestPolicy.
 *
 * The class is final because the walker recognises the plugin's callbacks with `instanceof`: a
 * subclass could override __invoke() and still pass for one.
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
	 * Creates a callback that requires one of the plugin's primitives.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability A primitive CapabilityDeclaration declares.
	 * @return self The callback.
	 *
	 * @throws \InvalidArgumentException When the capability is not a declared plugin primitive.
	 */
	public static function requiring( string $capability ): self {
		if ( ! ( new CapabilityDeclaration() )->isPrimitive( $capability ) ) {
			throw new \InvalidArgumentException( 'PermissionCallback::requiring() accepts only a primitive capability that CapabilityDeclaration declares.' );
		}

		return new self( $capability, null );
	}

	/**
	 * Creates a callback that requires a meta capability on the resource a request parameter names.
	 *
	 * @since 0.1.0
	 *
	 * @param string $capability        A meta capability CapabilityDeclaration declares.
	 * @param string $resourceParameter The request parameter that carries the resource identifier.
	 * @return self The callback.
	 *
	 * @throws \InvalidArgumentException When the capability is not a declared meta capability, or
	 *                                   the parameter name is empty.
	 */
	public static function requiringOn( string $capability, string $resourceParameter ): self {
		if ( ! ( new CapabilityDeclaration() )->isMetaCapability( $capability ) ) {
			throw new \InvalidArgumentException( 'PermissionCallback::requiringOn() accepts only a meta capability that CapabilityDeclaration declares; a primitive would ignore the resource.' );
		}

		if ( '' === trim( $resourceParameter ) ) {
			throw new \InvalidArgumentException( 'PermissionCallback::requiringOn() needs the name of the request parameter that carries the resource.' );
		}

		return new self( $capability, $resourceParameter );
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
	 * Returns the request parameter that names the resource the capability is checked on.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The parameter, or null for a check without a resource.
	 */
	public function resourceParameter(): ?string {
		return $this->resourceParameter;
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
		if ( null !== $this->capability ) {
			$resource = null === $this->resourceParameter ? null : $request->get_param( $this->resourceParameter );
			$actor    = Actor::user( get_current_user_id() );

			if ( ! ( new Authorizer( new CapabilityDeclaration() ) )->allows( $actor, $this->capability, $resource ) ) {
				return false;
			}
		}

		foreach ( $this->policies as $policy ) {
			if ( ! $policy->allows( $request, $this->capability ) ) {
				return false;
			}
		}

		return true;
	}
}
