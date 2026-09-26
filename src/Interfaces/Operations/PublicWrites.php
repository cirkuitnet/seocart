<?php
/**
 * PublicWrites: how the REST adapter guards and counts the writes anyone may send
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Interfaces\Operations;

use SEOCart\Application\Operations\PublicWrite;
use SEOCart\Platform\Authorization\RequestPolicy;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The two things a public write needs from the surface that serves it, in place of a capability.
 *
 * This interface owns one fact: where each control of a public write runs. WordPress asks a
 * permission callback more than once, and not only before the endpoint runs: again for the
 * `Allow` header of the response, for an OPTIONS request, and inside whatever another plugin
 * wraps the callback with. So the permission callback only answers, and the count happens once,
 * where the matched endpoint runs:
 *
 * - policy(): the request policy the route's PermissionCallback::publicWrite() asks. It changes
 *   nothing, however often it is asked.
 * - admit(): counts one run of the write against its rate limit. The REST adapter calls it once per
 *   request, in the endpoint's callback, before the service runs; OPTIONS and the `Allow` header
 *   never run the callback, so they never count.
 *
 * The Store API implements it (StoreWrites); the kernel hands it to the REST adapter.
 *
 * @since 0.1.0
 */
interface PublicWrites {

	/**
	 * Returns the request policy of a public write: the checks its permission callback makes.
	 *
	 * @since 0.1.0
	 *
	 * @param PublicWrite $write What the operation declared.
	 * @return RequestPolicy The policy, which changes nothing when it is asked.
	 */
	public function policy( PublicWrite $write ): RequestPolicy;

	/**
	 * Counts one run of a public write against its rate limit.
	 *
	 * @since 0.1.0
	 *
	 * @param PublicWrite $write What the operation declared.
	 * @return true|WP_Error True within the limit; otherwise the error to answer with.
	 */
	public function admit( PublicWrite $write ): bool|WP_Error;
}
