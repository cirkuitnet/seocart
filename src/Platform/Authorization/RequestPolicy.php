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

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The one seam where request-level policies attach to a PermissionCallback.
 *
 * A policy can only narrow: the callback consults it after its capability check has passed,
 * and a policy that allows cannot turn a denial into a grant. A policy answers the way a
 * WordPress permission callback does: true lets the request through, false denies it with
 * WordPress's own refusal, and a WP_Error denies it with that error, for a refusal the client
 * must be able to tell apart from the others. The error is built by the error translator, so it
 * has the code, the status and the data members of every other error.
 *
 * The Store API's request policy is one: a public write cannot be declared without it (see
 * PermissionCallback::publicWrite()). The credential-scope intersection, under which a request
 * authenticated by a named integration credential may use a capability only if the
 * credential's allow-list names it as well, will be another.
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
	 *                                    null for a public read or a public write.
	 * @return bool|WP_Error True to let the request through; false, or the error to answer with,
	 *                       to deny it.
	 */
	public function allows( WP_REST_Request $request, ?string $capability ): bool|WP_Error;
}
