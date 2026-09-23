<?php
/**
 * ReloadsRoles: puts the in-memory roles back in step with the database around a test
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Tests\Support;

/**
 * Reloads the roles of the current site from the database.
 *
 * This trait owns one fact: WordPress keeps role changes in two places, the roles option and
 * the WP_Roles object built from it. The test transaction rolls the option back, but not the
 * object, so a role or capability one test added would still be granted in the next. A test
 * that changes roles calls reloadRoles() after parent::set_up() and after parent::tear_down().
 * It also reads roles back from the database, rather than from the object a change went
 * through, when a test needs to know what was stored.
 *
 * @since 0.1.0
 */
trait ReloadsRoles {

	/**
	 * Empties the object cache and rebuilds the roles of the current site from the roles option.
	 *
	 * @since 0.1.0
	 */
	protected static function reloadRoles(): void {
		\WP_UnitTestCase_Base::flush_cache();

		wp_roles()->for_site();
	}
}
