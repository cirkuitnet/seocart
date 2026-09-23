<?php
/**
 * Check: one thing `wp seocart doctor` verifies
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only diagnostic of one part of the plugin's state on the current site.
 *
 * Owns one fact: the contract of a doctor check. A check reads, never writes, and answers with
 * a CheckResult that names only structure, counts and identifiers: never a stored value, a
 * secret or anything about a person. Doctor holds the list of checks; the command prints their
 * results and Site Health can show the same ones.
 *
 * @since 0.1.0
 */
interface Check {

	/**
	 * Returns the check's name, one lowercase word such as `schema`.
	 *
	 * @since 0.1.0
	 *
	 * @return string The name.
	 */
	public function name(): string;

	/**
	 * Runs the check.
	 *
	 * @since 0.1.0
	 *
	 * @return CheckResult Passed, or failed with what was found.
	 */
	public function run(): CheckResult;
}
