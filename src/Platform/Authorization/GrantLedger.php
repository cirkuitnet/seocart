<?php
/**
 * GrantLedger: the record of which capabilities the installer has granted on a site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers, per site, every role and capability pair the installer has already settled.
 *
 * A capability that is missing from a role cannot tell the installer why: it may never have
 * been granted, or a merchant may have taken it away. The ledger is how the installer tells
 * the two apart. A pair it has recorded is never granted again, so a merchant's removal
 * survives every later run, while a capability a later version adds to a bundle is granted
 * exactly once.
 *
 * An implementation keeps one record per site and is read only while the installer runs,
 * never on an ordinary request. It must survive deactivation, and it is removed with the rest
 * of the store's data by the deletion job.
 *
 * @since 0.1.0
 */
interface GrantLedger {

	/**
	 * Returns every pair recorded for the current site.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>> Capabilities, keyed by role name.
	 */
	public function granted(): array;

	/**
	 * Adds pairs to the record of the current site. Pairs already recorded stay recorded once.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, list<string>> $grants Capabilities, keyed by role name.
	 */
	public function record( array $grants ): void;
}
