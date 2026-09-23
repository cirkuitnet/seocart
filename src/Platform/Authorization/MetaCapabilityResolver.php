<?php
/**
 * MetaCapabilityResolver: turns one plugin meta capability on one resource into primitives
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which primitives a user needs to act on one resource.
 *
 * A module that owns a resource-aware capability, such as "may view this order", registers a
 * resolver for it with CapabilityMapper. The mapper calls it from the one map_meta_cap
 * callback, so a REST permission callback, an admin screen and a CLI command all get the same
 * answer.
 *
 * A resolver never grants anything itself: it names primitives, and core checks them against
 * the user's roles. When it cannot find the resource, or cannot tell who owns it, it returns
 * null, and the check is denied.
 *
 * @since 0.1.0
 */
interface MetaCapabilityResolver {

	/**
	 * Returns the primitives a user must hold to exercise the meta capability on a resource.
	 *
	 * @since 0.1.0
	 *
	 * @param int               $userId The user being checked. 0 for a visitor who is not logged in.
	 * @param array<int, mixed> $args   What followed the capability in the current_user_can()
	 *                                  call, the resource identifier first. May be empty.
	 * @return list<string>|null The primitives, all of which the user must hold, or null when the
	 *                           resource cannot be resolved. An empty list is treated as null.
	 */
	public function primitivesFor( int $userId, array $args ): ?array;
}
