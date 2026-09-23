<?php
/**
 * CapabilityInstaller: grants the capability declaration to the roles of the current site
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Platform\Authorization;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the shipped roles and grants every bundle, once per site.
 *
 * The kernel calls install() when the plugin is activated, and on multisite once for each site,
 * inside that site. It is never called on `init` or on any ordinary request: roles live in the
 * `{prefix}user_roles` option, and registering them on every request would write it on every
 * request. Deactivation calls nothing here, so it removes nothing.
 *
 * Every run is idempotent. For each role, only the pairs that the GrantLedger has not recorded
 * are due, and each due pair is settled exactly once:
 *
 * - a shipped role that does not exist and has no record is created with its due capabilities;
 * - a shipped role that does not exist but has a record was deleted by the merchant, and is not
 *   created again;
 * - a core role that does not exist is left alone, and retried on the next run;
 * - on a role that exists, a due capability is granted only when the role holds no value for it
 *   at all, so an explicit denial (`false`) is respected.
 *
 * Every settled pair is recorded, including one that was already present. A capability a
 * merchant removes from a role after that is therefore never granted again.
 *
 * @since 0.1.0
 */
final class CapabilityInstaller {

	/**
	 * What to grant, and to which role.
	 *
	 * @since 0.1.0
	 *
	 * @var CapabilityDeclaration
	 */
	private CapabilityDeclaration $declaration;

	/**
	 * The record of what earlier runs settled on this site.
	 *
	 * @since 0.1.0
	 *
	 * @var GrantLedger
	 */
	private GrantLedger $ledger;

	/**
	 * Creates the installer.
	 *
	 * @since 0.1.0
	 *
	 * @param CapabilityDeclaration $declaration What to grant, and to which role.
	 * @param GrantLedger           $ledger      The record of the current site.
	 */
	public function __construct( CapabilityDeclaration $declaration, GrantLedger $ledger ) {
		$this->declaration = $declaration;
		$this->ledger      = $ledger;
	}

	/**
	 * Grants whatever the declaration holds and the current site has not settled yet.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, list<string>> The capabilities this run granted, keyed by role. Empty
	 *                                     when every pair was already settled.
	 */
	public function install(): array {
		$recorded = $this->ledger->granted();
		$granted  = array();
		$settled  = array();

		foreach ( $this->declaration->roles() as $role ) {
			$due = array_values( array_diff( $this->declaration->bundle( $role ), $recorded[ $role ] ?? array() ) );

			if ( array() === $due ) {
				continue;
			}

			$existing = get_role( $role );

			if ( null === $existing ) {
				if ( ! $this->declaration->isShippedRole( $role ) || isset( $recorded[ $role ] ) ) {
					continue;
				}

				add_role( $role, (string) $this->declaration->roleName( $role ), array_fill_keys( $due, true ) );

				if ( null === get_role( $role ) ) {
					continue;
				}

				$granted[ $role ] = $due;
				$settled[ $role ] = $due;

				continue;
			}

			$new = array();

			foreach ( $due as $capability ) {
				if ( ! array_key_exists( $capability, $existing->capabilities ) ) {
					$existing->add_cap( $capability );
					$new[] = $capability;
				}
			}

			if ( array() !== $new ) {
				$granted[ $role ] = $new;
			}

			$settled[ $role ] = $due;
		}

		if ( array() !== $settled ) {
			$this->ledger->record( $settled );
		}

		return $granted;
	}
}
