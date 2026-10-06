<?php
/**
 * RefundCapPolicy: which refund caps a user is held to
 *
 * @package SEOCart
 * @since   0.2.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Payment\Application;

use SEOCart\Payment\Domain\Refund\RefundAllocation;
use SEOCart\Platform\Authorization\Actor;
use SEOCart\Platform\Settings\SettingsStore;
use SEOCart\Support\Currency;
use SEOCart\Support\Decimal;
use SEOCart\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Says which refund caps a user is held to, in a base currency.
 *
 * Owns one fact: who is capped. A user is capped only when every role they hold that grants the
 * refund capability is a capped role of RefundCapSettings: holding a role that grants it and caps
 * nothing, such as the store manager's or one the merchant made, uncaps the user, and so does the
 * capability granted to the user alone. A user holding several capped roles gets, for each cap,
 * the largest of their roles' values. The roles are read as data, to find which settings apply,
 * never to authorize: the capability check has already let the user refund.
 *
 * @since 0.2.0
 */
final class RefundCapPolicy {

	/**
	 * The settings the caps are read from.
	 *
	 * @since 0.2.0
	 *
	 * @var SettingsStore
	 */
	private SettingsStore $settings;

	/**
	 * Creates the policy. Reads nothing.
	 *
	 * @since 0.2.0
	 *
	 * @param SettingsStore $settings The settings the caps are read from.
	 */
	public function __construct( SettingsStore $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns the caps a user is held to.
	 *
	 * Reads the user's roles first, which the capability check has cached, and the caps' settings
	 * only for a capped user: an uncapped user costs no statement.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor    $actor Who refunds.
	 * @param Currency $base  The base currency of the order refunded, which the caps are amounts of.
	 * @return RefundCaps The caps; none for an uncapped user.
	 */
	public function for( Actor $actor, Currency $base ): RefundCaps {
		$roles = self::cappedRoles( $actor );

		if ( array() === $roles ) {
			return RefundCaps::none();
		}

		return new RefundCaps( $this->largest( $roles, 0, $base ), $this->largest( $roles, 1, $base ) );
	}

	/**
	 * Returns the capped roles a user holds when every role of theirs that grants refunds is one; otherwise none.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The user.
	 * @return list<string> The capped roles; empty for an uncapped user.
	 */
	private static function cappedRoles( Actor $actor ): array {
		$user = get_userdata( $actor->userId() );

		if ( false === $user ) {
			return array();
		}

		$granting = array_values( array_filter( $user->roles, static fn( string $role ): bool => (bool) get_role( $role )?->has_cap( RefundService::CAPABILITY ) ) );

		if ( array() === $granting || array() !== array_diff( $granting, array_keys( RefundCapSettings::CAPPED_ROLES ) ) ) {
			return array();
		}

		return $granting;
	}

	/**
	 * Returns the largest of some capped roles' values of one cap, or null when one of them leaves it uncapped.
	 *
	 * @since 0.2.0
	 *
	 * @param string[] $roles The capped roles.
	 * @param int      $which 0 for the cap of one order, 1 for the cap of a day.
	 * @param Currency $base  The base currency.
	 * @return Money|null The cap; null for none.
	 *
	 * @phpstan-param list<string> $roles
	 */
	private function largest( array $roles, int $which, Currency $base ): ?Money {
		$largest = null;

		foreach ( $roles as $role ) {
			$value = (string) $this->settings->value( RefundCapSettings::CAPPED_ROLES[ $role ][ $which ] );

			if ( '' === $value ) {
				return null;
			}

			$cap     = RefundAllocation::cap( Decimal::of( $value ), $base );
			$largest = null === $largest || $cap->compare( $largest ) > 0 ? $cap : $largest;
		}

		return $largest;
	}
}
