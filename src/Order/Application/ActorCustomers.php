<?php
/**
 * ActorCustomers: which customer an actor is, as the order module asks it
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
 * The port the order module learns an actor's customer through.
 *
 * Owns one fact for the order module: which customer, if any, an actor acts as. An order belongs
 * to a customer record, not to a WordPress user, and customer records are kept outside the order
 * module, so it asks this port and never reads them itself. OrderAccessPolicy compares the answer
 * with the customer an order was placed for.
 *
 * @since 0.1.0
 */
interface ActorCustomers {

	/**
	 * Returns the customer an actor acts as.
	 *
	 * @since 0.1.0
	 *
	 * @param Actor $actor The actor: a visitor, a user, or a process acting on a user's authority.
	 * @return int|null The customer's id, or null when the actor is no customer.
	 */
	public function customerOf( Actor $actor ): ?int;
}
