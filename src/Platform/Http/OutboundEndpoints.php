<?php
/**
 * OutboundEndpoints: the registry of every external service the plugin contacts
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Http;

use SEOCart\Contracts\OutboundHost;

defined( 'ABSPATH' ) || exit;

/**
 * Declares every server, other than the site itself, that SEOCart may contact.
 *
 * This class owns one fact: which external services the plugin talks to. What it sends to
 * each one, and when, is the service's OutboundHost declaration, whose fields are the one
 * list of what a disclosure says. The "External services" section of readme.txt is generated
 * from this list (`composer docs:generate`) and drift-tested against it
 * (`composer docs:check`), so the disclosure the WordPress.org directory requires cannot
 * fall behind the code; and the outbound client refuses a host that is not declared, so the
 * code cannot get ahead of the disclosure.
 *
 * The list is pure data. It is read by command-line tools that never load WordPress,
 * so it performs no I/O, calls no WordPress function and translates nothing: the
 * strings are English sentences written for readme.txt, which the directory
 * translates on its own.
 *
 * @since 0.1.0
 * @since 0.2.0 Each service is an OutboundHost; the field list moved there.
 */
final class OutboundEndpoints {

	/**
	 * Returns every declared external service, in the order readme.txt lists them.
	 *
	 * Empty: SEOCart contacts no server other than the site it runs on. A payment gateway,
	 * which is a plugin of its own, declares the services it calls itself.
	 *
	 * @since 0.1.0
	 * @since 0.2.0 Returns OutboundHost declarations instead of arrays.
	 *
	 * @return list<OutboundHost> One declaration per service.
	 */
	public static function all(): array {
		return array();
	}
}
