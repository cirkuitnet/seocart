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
 * with the customer an order was placed for; the placement asks for the customer an order it
 * writes belongs to, and for the person an actor is, which its idempotency keys are scoped to.
 *
 * @since 0.1.0
 * @since 0.2.0 The customer an order placed by an actor belongs to, and the person an actor is.
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

	/**
	 * Returns the customer an order placed by an actor belongs to, creating the record of a user's first order: the one place a customer record is created.
	 *
	 * Called only inside a transaction that writes on the actor's behalf, such as the placement's
	 * first unit of work, so a record is created with the order it is created for or not at all,
	 * and a unit run again after a deadlock finds or creates it again. The customer comes from the
	 * actor alone, never from anything a client sent.
	 *
	 * @since 0.2.0
	 *
	 * @throws \LogicException Outside a transaction, when a record must be created.
	 *
	 * @param Actor $actor The actor the order is placed by.
	 * @return int|null The customer's id; null when the actor is no customer, such as a visitor or a process.
	 */
	public function customerFor( Actor $actor ): ?int;

	/**
	 * Returns the person an actor is, as a customer link names a person: the user's id and the time they registered, joined by a bar.
	 *
	 * A deleted user's id can come back for someone else, so a user id alone does not name one
	 * person; with the time the user registered it does, as a customer link holds it. The
	 * placement scopes a signed-in person's idempotency keys to this answer, so that two people
	 * presenting one cart never share a key. Asked of the user WordPress authenticated for the
	 * request, it costs no statement: WordPress read that user to authenticate them.
	 *
	 * @since 0.2.0
	 *
	 * @param Actor $actor The actor.
	 * @return string|null The person, such as `42|2026-11-14 09:30:00`; null for anyone who is no
	 *                     person (a visitor, a process) and for a user WordPress no longer has.
	 */
	public function personOf( Actor $actor ): ?string;
}
