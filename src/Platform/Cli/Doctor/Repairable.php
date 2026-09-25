<?php
/**
 * Repairable: a doctor check that can fix what it finds, structurally and safely
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Cli\Doctor;

defined( 'ABSPATH' ) || exit;

/**
 * A Check whose finding `wp seocart doctor --repair` may fix on its own.
 *
 * Owns one fact: which checks `--repair` may call repair() on, and that repair() is safe to call
 * whenever the check last failed. It never touches a price, a variant, a ledger row or a source
 * binding; it fixes only what is structural and fully recoverable. `--repair` calls repair() once
 * per failed Repairable, after the first pass and before the second.
 *
 * @since 0.1.0
 */
interface Repairable extends Check {

	/**
	 * Fixes what the check last found, re-checking every condition it relies on before it writes.
	 *
	 * Idempotent: calling it when there is nothing left to fix changes nothing and reports no
	 * change. A row a concurrent writer is holding is skipped, reported, and left for the next
	 * run.
	 *
	 * @since 0.1.0
	 *
	 * @return RepairResult What was changed, or skipped and why.
	 */
	public function repair(): RepairResult;
}
