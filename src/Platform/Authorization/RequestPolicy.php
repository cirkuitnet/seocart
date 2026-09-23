<?php
/**
 * RequestPolicy: a further condition a permission callback applies after its capability check
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
 * The one seam where later request-level policies attach to a PermissionCallback.
 *
 * A policy can only narrow: the callback consults it after its capability check has passed,
 * and a policy that allows cannot turn a denial into a grant. Two are planned, and neither is
 * built yet: the Store API's cart-token write policy (a write must carry the cart token and the
 * required request header) and the credential-scope intersection, under which a request
 * authenticated by a named integration credential may use a capability only if the
 * credential's allow-list names it as well.
 *
 * @since 0.1.0
 */
interface RequestPolicy {

	/**
	 * Tells whether this policy lets a request through.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request    The request being answered.
	 * @param string|null     $capability The capability the callback has already confirmed, or
	 *                                    null for a public read.
	 * @return bool True to let the request through; false denies it.
	 */
	public function allows( WP_REST_Request $request, ?string $capability ): bool;
}
