<?php
/**
 * NoCustomers: the answer while the store keeps no customer records
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Order\Application;

use SEOCart\Platform\Authorization\Actor;

defined( 'ABSPATH' ) || exit;

/**
 * Says that no actor is a customer.
 *
 * Owns one fact: that until the store keeps customer records, no order belongs to any actor, so
 * every order, a logged-in shopper's included, is read with its access key. It reads nothing. The
 * module that keeps customer records replaces it with an implementation that looks the actor up.
 *
 * @since 0.1.0
 */
final class NoCustomers implements ActorCustomers {

	/**
	 * Returns no customer, whoever the actor is.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor.
	 * @return int|null Always null.
	 */
	public function customerOf( Actor $actor ): ?int {
		unset( $actor );

		return null;
	}
}
