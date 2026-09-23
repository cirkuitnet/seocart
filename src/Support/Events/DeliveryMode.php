<?php
/**
 * DeliveryMode: how a domain event reaches its listeners
 *
 * @package SEOCart
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace SEOCart\Support\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The two ways a published event is delivered.
 *
 * Owns one fact: the choice between an event that must survive a crash and one that may be
 * lost with the request. Both fire a `seocart_` action once the transaction that recorded the
 * event has committed; neither ever runs a listener inside that transaction.
 *
 * @since 0.1.0
 */
enum DeliveryMode: string {

	/**
	 * Written as a row inside the transaction that changes the state it describes, and delivered
	 * from that row after the commit, at least once. For anything that must not be lost: money,
	 * stock, orders, and everything an outside system observes.
	 *
	 * @since 0.1.0
	 */
	case Outbox = 'outbox';

	/**
	 * Delivered in the same request, right after the commit, from memory. For events whose loss
	 * is tolerable, such as cache invalidation hints and counters.
	 *
	 * @since 0.1.0
	 */
	case AfterCommit = 'after_commit';
}
