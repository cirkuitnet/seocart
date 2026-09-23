<?php
/**
 * Authorizer: the one capability check behind every application service and REST route
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

use SEOCart\Support\Error\CodedException;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an actor holds a declared capability, on a resource when the capability is a meta capability.
 *
 * This class owns one fact: how a capability check is made. An application service calls
 * authorize() before it does anything, and gets `authorization.denied` (HTTP 403) when the
 * answer is no. Every REST route's PermissionCallback asks allows() the same question, so a
 * service and the route in front of it cannot disagree:
 *
 *     $this->authorizer->authorize( $actor, 'seocart_manage_inventory' );
 *     $this->authorizer->authorize( $actor, 'seocart_view_order', $order_uuid );
 *
 * The check is user_can() for the actor's user, which ends in the one map_meta_cap callback
 * (CapabilityMapper). The actor is always named by the caller: there is no fallback to the
 * logged-in user, and being logged in exempts nobody.
 *
 * The capability decides the shape of the check, as it does for PermissionCallback:
 *
 * - a declared primitive is checked without a resource;
 * - a declared meta capability is checked on a resource. A resource that names nothing — none
 *   at all, zero, a negative number, a blank string, or a value that is neither a number nor a
 *   string — is denied without asking anyone, and so is a product meta capability on anything
 *   but a product post.
 *
 * Anything else, including core capabilities such as `read` or `exist` and a primitive with a
 * resource, is a programming error: an InvalidArgumentException, never an answer.
 *
 * @since 0.1.0
 */
final class Authorizer {

	/**
	 * The declaration that says which capabilities may be checked, and how.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $declaration;

	/**
	 * Creates the authorizer.
	 *
	 * @since 0.1.0
	 *
	 * @param CapabilityDeclaration $declaration The plugin's capability declaration.
	 */
	public function __construct( CapabilityDeclaration $declaration ) {
		$this->declaration = $declaration;
	}

	/**
	 * Lets the use case go on when the actor holds the capability, and stops it otherwise.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor           $actor      Who is acting.
	 * @param string          $capability A declared primitive, or a declared meta capability.
	 * @param int|string|null $identifier Optional. The resource a meta capability is checked on:
	 *                                    an id or a uuid. Must be null for a primitive. Default null.
	 *
	 * @throws CodedException            With `authorization.denied` when the actor may not.
	 * @throws \InvalidArgumentException When the capability is not declared, or a primitive is
	 *                                   given a resource.
	 */
	public function authorize( Actor $actor, string $capability, int|string|null $identifier = null ): void {
		if ( ! $this->allows( $actor, $capability, $identifier ) ) {
			CodedException::raise( AuthorizationError::Denied, array( 'capability' => $capability ) );
		}
	}

	/**
	 * Tells whether the actor holds the capability, on the resource when it is a meta capability.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor  $actor      Who is acting.
	 * @param string $capability A declared primitive, or a declared meta capability.
	 * @param mixed  $identifier Optional. The resource a meta capability is checked on, as the
	 *                           caller received it: digits become an integer id, any other
	 *                           non-blank string is passed on as it is. Must be null for a
	 *                           primitive. Default null.
	 * @return bool True when user_can() says yes for the actor's user.
	 *
	 * @throws \InvalidArgumentException When the capability is not declared, or a primitive is
	 *                                   given a resource.
	 */
	public function allows( Actor $actor, string $capability, mixed $identifier = null ): bool {
		if ( $this->declaration->isPrimitive( $capability ) ) {
			if ( null !== $identifier ) {
				throw new \InvalidArgumentException( 'A primitive capability is checked without a resource; a resource would be ignored. Check a meta capability on it instead.' );
			}

			return user_can( $actor->userId(), $capability );
		}

		if ( ! $this->declaration->isMetaCapability( $capability ) ) {
			throw new \InvalidArgumentException( 'Only a capability that CapabilityDeclaration declares can be checked.' );
		}

		$usable = self::resourceIdentifier( $identifier );

		return null !== $usable && self::isAbout( $capability, $usable ) && user_can( $actor->userId(), $capability, $usable );
	}

	/**
	 * Tells whether a resource is one the meta capability can be about.
	 *
	 * Core maps the product post type's meta capabilities through the type of whichever post the
	 * check names: `edit_seocart_product` on an ordinary post is answered with the capabilities
	 * for editing posts, which an editor holds. They are therefore checked only on a post of the
	 * product post type; anything else is denied without asking. The plugin's own meta
	 * capabilities are left to their resolvers, which look the resource up themselves.
	 *
	 * @since 0.1.0
	 *
	 * @param string     $capability A declared meta capability.
	 * @param int|string $identifier A usable resource identifier.
	 * @return bool False for a product meta capability on anything but a product post.
	 */
	private static function isAbout( string $capability, int|string $identifier ): bool {
		if ( ! in_array( $capability, ProductCapabilities::metaCapabilities(), true ) ) {
			return true;
		}

		return is_int( $identifier ) && ProductCapabilities::POST_TYPE === get_post_type( $identifier );
	}

	/**
	 * Reads a resource identifier.
	 *
	 * A number, including one written as digits in a string, as route patterns deliver it,
	 * becomes a positive integer. Any other non-blank string, such as a uuid, is passed on as it
	 * is, for the resolver to look up. Everything else names no resource.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The value that should name the resource.
	 * @return int|string|null The identifier, or null when the value names no resource.
	 */
	private static function resourceIdentifier( mixed $value ): int|string|null {
		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]+$/D', $value ) ) {
			$value = (int) $value;
		}

		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		return is_string( $value ) && '' !== trim( $value ) ? $value : null;
	}
}
