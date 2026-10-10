<?php
/**
 * PermissionKind: the four ways a plugin route decides who may send a request
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * The kinds of PermissionCallback: what decides whether a request may run.
 *
 * Owns one fact: the kinds a route's guard comes in. Each is a written decision, which the route
 * walker in the test suite holds to its rules: a capability is checked for the user; a public read
 * checks nothing and is served by GET and HEAD only; a public write checks no capability, and the
 * Store API's request policy decides instead; a signed request checks no capability either, and
 * its sender proves who it is by signing the request's body, which the route's handler verifies.
 *
 * @since 0.2.0
 */
enum PermissionKind {

	/**
	 * A capability the plugin declares, checked for the current user, on a resource or without one.
	 *
	 * @since 0.2.0
	 */
	case Capability;

	/**
	 * A read that is public on purpose: nothing is checked.
	 *
	 * @since 0.2.0
	 */
	case PublicRead;

	/**
	 * A write anyone may send, decided by the Store API's request policy instead of a capability.
	 *
	 * @since 0.2.0
	 */
	case PublicWrite;

	/**
	 * A write a provider sends, signed over its body: the policy checks only the request's shape, and the handler verifies the signature before it reads anything.
	 *
	 * @since 0.2.0
	 */
	case Signed;
}
