<?php
/**
 * WriteMethod: the HTTP methods an operation that changes something may be served with
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Application\Operations;

defined( 'ABSPATH' ) || exit;

/**
 * The HTTP method of a REST route whose operation changes something.
 *
 * This enum owns one fact: that a change is never served by GET. It has no GET case, and a
 * read-only operation declares no write method at all — its method is GET by derivation — so a
 * route that changes the store on GET cannot be declared. GET requests are prefetched, cached and
 * followed by crawlers; a state change behind one is a correctness and a security bug.
 *
 * @since 0.1.0
 */
enum WriteMethod: string {

	/**
	 * Creates something, or runs a command such as an adjustment.
	 *
	 * @since 0.1.0
	 */
	case Post = 'POST';

	/**
	 * Replaces a resource.
	 *
	 * @since 0.1.0
	 */
	case Put = 'PUT';

	/**
	 * Changes part of a resource.
	 *
	 * @since 0.1.0
	 */
	case Patch = 'PATCH';

	/**
	 * Removes a resource.
	 *
	 * @since 0.1.0
	 */
	case Delete = 'DELETE';
}
